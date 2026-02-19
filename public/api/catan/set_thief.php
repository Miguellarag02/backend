<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data) || !isset($data["username"], $data["hexId"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);
$hex_id = $data["hexId"];

if ($username === "" || $hex_id < 1) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username and Hex ID are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Check if i the player turn
    $checkStmt = $pdo->prepare("
        SELECT (p.current_order = gm.turn) AS available
        FROM game_match gm
        JOIN users u ON u.username = :username
        JOIN player p ON p.id_user = u.id
        WHERE gm.id = 1
        FOR UPDATE
    ");
    $checkStmt->execute(["username" => $username]);
    $available = (int) $checkStmt->fetchColumn();

    if ($available === 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["ok" => false, "message" => "Is not your turn"]);
        exit;
    }

    // Delete thief
    $updateStmt = $pdo->prepare("
        UPDATE hexagon hex
        SET hex.is_thief = 0
        WHERE hex.is_thief = 1
    ");
    $updateStmt->execute([ ]);

    // Add thief
    $updateStmt = $pdo->prepare("
        UPDATE hexagon hex
        SET hex.is_thief = 1
        WHERE hex.id = :hex_id
    ");
    $updateStmt->execute(["hex_id" => $hex_id]);

    $pdo->commit();
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "Database error"]);
}
