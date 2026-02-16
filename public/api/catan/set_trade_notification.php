<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data) || !isset($data["username"], $data["toPlayerId"], $data["selectedResourcesByUser"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$from_user_username = trim((string)$data["username"]);
$selected_resources = $data["selectedResourcesByUser"];
$to_player_id = (int)($data["toPlayerId"] ?? 0);

if ($to_player_id <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "toPlayerId is required"]);
  exit;
}

if (!is_array($selected_resources)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "selectedResourcesByUser must be an array"]);
  exit;
}

$selected_resources_json = json_encode(array_map('intval', $selected_resources), JSON_THROW_ON_ERROR);

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Check enough materials
    foreach ($selected_resources as $index => $value) {
        $checkStmt = $pdo->prepare("
        SELECT (prc.qty >= :resource_qty) AS available
            FROM player_resources_card AS prc
            JOIN users u ON u.username = :username
            JOIN player py ON py.id_user = u.id
            WHERE prc.id_player = py.id 
            AND prc.id_card = (:resource_id + 1)
        ");
        $checkStmt->execute([
            "username" => $from_user_username,
            "resource_id" => $index,
            "resource_qty" => $value,
        ]);
        $available = (int) $checkStmt->fetchColumn();

        if ($available === 0) {
            $pdo->rollBack();
            http_response_code(400);
           echo json_encode([
                "ok" => false,
                "message" => "Not enough materials",
                "Requested resource" => " " . $from_user_username .": Resource " . ($index + 1) . " qty: " . $value,
            ]);
            exit;
        }
    }

    // Comprobamos si estamos respondiendo una notificación existente
    $getTn = $pdo->prepare("
        SELECT tn.id
            FROM trade_notifications AS tn
            JOIN users u ON u.username = :username
            JOIN player py ON py.id_user = u.id
            WHERE tn.to_id_player = py.id
                AND tn.from_id_player = :to_player_id
            FOR UPDATE
    ");
    $getTn->execute([
        "username" => $from_user_username,
        "to_player_id" => $to_player_id
    ]);
    $tn = $getTn->fetch(PDO::FETCH_ASSOC);


    if (!$tn) {
        // No existe ningún trade abierto, proponemos uno
        $stmt = $pdo->prepare("
            INSERT INTO trade_notifications (from_id_player, to_id_player, from_resource_ids)
                SELECT 
                py.id,
                :to_player_id,
                CAST(:selected_resources AS JSON)
                FROM users u
                JOIN player py ON py.id_user = u.id
                WHERE u.username = :username;
        ");
        $stmt->execute([
            "username" => $from_user_username,
            "to_player_id" => $to_player_id,
            "selected_resources" => $selected_resources_json
        ]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                "ok" => false,
                 "message" => "The trade notification cannot be created"]);
            exit;
        }
        $newId = $pdo->lastInsertId();
    }
    else {
        // Ya existe, actualizamos la oferta
        $stmt = $pdo->prepare("
            UPDATE trade_notifications tn
            JOIN users u ON u.username = :username
            JOIN player py ON py.id_user = u.id
            SET tn.to_resource_ids = CAST(:selected_resources AS JSON)
            WHERE tn.id = :tn_id
        ");
        $stmt->execute([
            "username" => $from_user_username,
            "tn_id" => (int)$tn["id"],
            "selected_resources" => $selected_resources_json
        ]);

    }

    $pdo->commit();
    echo json_encode(["ok" => true, "tn" => $tn]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
    "ok" => false,
    "message" => "Database error",
    "debug" => $e->getMessage() // activa solo en dev
    ]);
}