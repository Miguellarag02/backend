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
$selected_resource = $data["selectedResource"] ?? null;

if ($username === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username is required"]);
  exit;
}

if ($selected_resource <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "selectedResourceByUser must be 1, 2, 3, 4 or 5"]);
  exit;
}

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

    // 1) Chequear cantidad de recurso a recolectar
    $checkStmt = $pdo->prepare("
        SELECT SUM(prc.qty)
            FROM player_resources_card AS prc
            WHERE prc.id_player != :player_id
                AND prc.id_card = :resource_id
            FOR UPDATE
    ");
    $checkStmt->execute([
        "player_id" => $player_id,
        "resource_id" => $selected_resource
    ]);
    $resource_qty = (int)$checkStmt->fetchColumn();

    if ($resource_qty === 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            "ok" => false,
            "message" => "Not enough materials to take from users",
        ]);
        exit;
    }

    // 2) Eliminamos la carta (Monopoly = random_card id 3)
    $upd = $pdo->prepare("
        UPDATE player_random_card
        SET qty = qty - 1
        WHERE id_player = :player_id
          AND id_card = 3
          AND qty > 0
    ");
    $upd->execute(["player_id" => $player_id]);

    if ($upd->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["ok" => false, "message" => "Monopoly card not available"]);
        exit;
    }

    // 3) Repartir recursos al jugador principal
    $upd = $pdo->prepare("
        UPDATE player_resources_card
            SET qty = qty + :take_qty
            WHERE id_player = :player_id
                AND id_card = :resource_id
    ");
    $upd->execute([
        "player_id" => $player_id,
        "resource_id" => $selected_resource,
        "take_qty" => $resource_qty,
    ]);

    if ($upd->rowCount() !== 1) {
        throw new RuntimeException("Failed to add resource card to player");
    }

    // 4) Eliminar recursos del resto de jugadores
    $upd = $pdo->prepare("
        UPDATE player_resources_card
            SET qty = 0
            WHERE id_player != :player_id
                AND id_card = :resource_id
    ");
    $upd->execute([
        "player_id" => $player_id,
        "resource_id" => $selected_resource
    ]);

    if ($upd->rowCount() == 0) {
        throw new RuntimeException("Failed to delete resources card from other players");
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
