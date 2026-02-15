<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data) || !isset($data["username"], $data["buildId"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);
$build_id = $data["buildId"];

if ($username === "" || $build_id < 1 ) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username and build ID are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Check enough materials
    $checkStmt = $pdo->prepare("
        SELECT MIN(brc.qty <= pyc.qty) AS available
        FROM building_resources_card brc
        JOIN users u ON u.username = :username
        JOIN player p ON p.id_user = u.id
        JOIN player_resources_card pyc
            ON pyc.id_player = p.id AND brc.id_card = pyc.id_card
        WHERE brc.id_building = 1
        FOR UPDATE
    ");
    $checkStmt->execute(["username" => $username]);
    $available = (int) $checkStmt->fetchColumn();

    if ($available === 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["ok" => false, "message" => "Not enough materials"]);
        exit;
    }

    // Delete the requires materials
    $updateStmt = $pdo->prepare("
        UPDATE player_resources_card pyc
        JOIN player p ON pyc.id_player = p.id
        JOIN users u ON p.id_user = u.id
        JOIN building_resources_card brc ON brc.id_card = pyc.id_card
        SET pyc.qty = pyc.qty - brc.qty
        WHERE u.username = :username
            AND brc.id_building = 1
    ");
    $updateStmt->execute(["username" => $username]);

    // Add resource to banck
    $updateStmt = $pdo->prepare("
        UPDATE resources_card rc
        JOIN building_resources_card brc ON brc.id_card = rc.id
        SET rc.current_count = rc.current_count - brc.qty
        WHERE brc.id_building = 1 
    ");
    $updateStmt->execute([]);

    // Add town
    $updateStmt = $pdo->prepare(
        "UPDATE town_conections tc
        JOIN users u ON u.username = :username
        JOIN player p ON p.id_user = u.id
        SET tc.player_id = p.id
        WHERE tc.id = :build_id"
    );
    $updateStmt->execute([
        "build_id" => $build_id,
        "username" => $username,
    ]);

    $pdo->commit();
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "Database error"]);
}
