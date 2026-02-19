<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data) || !isset($data["username"], $data["from_id"], $data["from_qty"], $data["to_id"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)($data["username"]));
$from_id = (int)($data["from_id"]);
$from_qty = (int)($data["from_qty"]);
$to_id = (int)($data["to_id"]);

if ($from_id <= 0 || $to_id <= 0 || $from_qty <= 0 || $from_id === $to_id) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid trade params"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Check enough materials
    $checkStmt = $pdo->prepare("
        SELECT 
            (prc.qty >= :resource_qty) AS available, 
            py.id as id_player
        FROM player_resources_card AS prc
        JOIN users u ON u.username = :username
        JOIN player py ON py.id_user = u.id
        WHERE prc.id_player = py.id 
        AND prc.id_card = :resource_id
        FOR UPDATE
    ");

    $checkStmt->execute([
        "username" => $username,
        "resource_id" => $from_id,
        "resource_qty" => $from_qty,
    ]);

    $query = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$query) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            "ok" => false,
            "message" => "Player/resource not found"
        ]);
        exit;
    }

    $id_player = (int)$query["id_player"];
    $available = (int)$query["available"];


    if ($available === 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            "ok" => false,
            "message" => "Not enough materials",
            "debug" => $id_player . ": " . $available
        ]);
        exit;
    }

    // Delete resources from player
    $upd = $pdo->prepare("
        UPDATE player_resources_card
        SET qty = qty - :qty
        WHERE id_player = :id_player AND id_card = :resource_id
    ");
    $upd->execute([
        "qty" => $from_qty,
        "id_player" => $id_player,
        "resource_id" => $from_id,
    ]);

    // Add one resource to player
    $upd = $pdo->prepare("
        UPDATE player_resources_card
        SET qty = qty + 1
        WHERE id_player = :id_player AND id_card = :resource_id
    ");
    $upd->execute([
        "id_player" => $id_player,
        "resource_id" => $to_id,
    ]);

    // Balanced resources
    $upd = $pdo->prepare("
        UPDATE resources_card
        SET current_count = current_count + :qty
        WHERE id = :resource_id
    ");
    $upd->execute([
        "qty" => $from_qty,
        "resource_id" => $from_id,
    ]);
    $upd = $pdo->prepare("
        UPDATE resources_card
        SET current_count = current_count - 1
        WHERE id = :resource_id
    ");
    $upd->execute([
        "resource_id" => $to_id,
    ]);

    $pdo->commit();
    echo json_encode(["ok" => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "message" => "Database error"
    ]);
}
