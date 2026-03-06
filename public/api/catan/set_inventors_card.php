<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);
$selected_resources = $data["selectedResources"] ?? null;

if ($username === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username is required"]);
  exit;
}

if (!is_array($selected_resources)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "selectedResourcesByUser must be an array"]);
  exit;
}

$selected_resources = array_values(array_map('intval', $selected_resources));

if (count($selected_resources) !== 2) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Exactly 2 resources must be selected"]);
  exit;
}

foreach ($selected_resources as $resource_id) {
  if ($resource_id <= 0) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid resource id"]);
    exit;
  }
}

$resource_counts = array_count_values($selected_resources);


require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Resolve player once and lock the row used by later updates.
    $playerStmt = $pdo->prepare("
        SELECT p.id
        FROM player p
        JOIN users u ON u.id = p.id_user
        WHERE u.username = :username
        LIMIT 1
        FOR UPDATE
    ");
    $playerStmt->execute(["username" => $username]);
    $player_id = (int)$playerStmt->fetchColumn();

    if ($player_id <= 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(["ok" => false, "message" => "Player not found"]);
        exit;
    }

    // 1) Chequear si quedan recursos
    foreach ($resource_counts as $resource_id => $take_qty) {
        $checkStmt = $pdo->prepare("
        SELECT ((rc.current_count + :take_qty) <= rc.max_count) AS available
            FROM resources_card AS rc
            WHERE rc.id = :resource_id
            FOR UPDATE
        ");
        $checkStmt->execute([
            "resource_id" => (int)$resource_id,
            "take_qty" => (int)$take_qty,
        ]);
        $available = (int)$checkStmt->fetchColumn();

        if ($available === 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                "ok" => false,
                "message" => "Not enough materials in bank",
            ]);
            exit;
        }
    }

    // 2) Eliminamos la carta (Inventor = random_card id 5)
    $upd = $pdo->prepare("
        UPDATE player_random_card
        SET qty = qty - 1
        WHERE id_player = :player_id
          AND id_card = 5
          AND qty > 0
    ");
    $upd->execute(["player_id" => $player_id]);

    if ($upd->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["ok" => false, "message" => "Inventor card not available"]);
        exit;
    }

    // 3) Repartir recursos al jugador
    foreach ($resource_counts as $resource_id => $take_qty) {
        $upd = $pdo->prepare("
        UPDATE player_resources_card
            SET qty = qty + :take_qty
            WHERE id_player = :player_id
              AND id_card = :resource_id
        ");
        $upd->execute([
            "player_id" => $player_id,
            "resource_id" => (int)$resource_id,
            "take_qty" => (int)$take_qty,
        ]);

        if ($upd->rowCount() !== 1) {
            throw new RuntimeException("Failed to add resource card to player");
        }
    }

    // 4) Actualizar conteo del banco (recursos entregados por el banco)
    foreach ($resource_counts as $resource_id => $take_qty) {
        $upd = $pdo->prepare("
            UPDATE resources_card
            SET current_count = current_count + :take_qty_set
            WHERE id = :resource_id
              AND current_count + :take_qty_check <= max_count
        ");
        $upd->execute([
            "resource_id" => (int)$resource_id,
            "take_qty_set" => (int)$take_qty,
            "take_qty_check" => (int)$take_qty,
        ]);

        if ($upd->rowCount() !== 1) {
            throw new RuntimeException("Failed to update bank resource count");
        }
    }

    $pdo->commit();
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "Database error", "debug" => $e->getMessage()]);
}
