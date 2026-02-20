<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data) || !isset($data["username"], $data["buildId"], $data["level"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);
$build_id = $data["buildId"];
$level = $data["level"];

if ($username === "" || $build_id < 1 || ($level != 1 && $level != 2)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username and build ID are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Check if the player is in the first round, in that case the build is free
    $check = $pdo->prepare("
        SELECT ((gm.turn = player.current_order) && (gm.round = 1 || gm.round = 2)) AS build_free
        FROM game_match gm
        JOIN users ON users.username = :username
        JOIN player ON player.id_user = users.id
        WHERE gm.id = 1
        FOR UPDATE
    ");
    $check->execute([
        "username" => $username
    ]);
    $checkRound = (int) $check->fetchColumn();

    if ($checkRound != 1) {

        // Check enough resource
        $checkStmt = $pdo->prepare("
            SELECT MIN(brc.qty <= pyc.qty) AS available
            FROM building_resources_card brc
            JOIN users u ON u.username = :username
            JOIN player p ON p.id_user = u.id
            JOIN player_resources_card pyc
                ON pyc.id_player = p.id AND brc.id_card = pyc.id_card
            WHERE brc.id_building = (1 + :level_town)
            FOR UPDATE
        ");
        $checkStmt->execute(["username" => $username, "level_town" => $level]);
        $available = (int) $checkStmt->fetchColumn();

        if ($available === 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(["ok" => false, "message" => "Not enough materials"]);
            exit;
        }

        // Delete resource from user
        $updateStmt = $pdo->prepare("
            UPDATE player_resources_card pyc
            JOIN player p ON pyc.id_player = p.id
            JOIN users u ON p.id_user = u.id
            JOIN building_resources_card brc ON brc.id_card = pyc.id_card
            SET pyc.qty = pyc.qty - brc.qty
            WHERE u.username = :username
                AND brc.id_building = (1 + :level_town)
        ");
        $updateStmt->execute(["username" => $username, "level_town" => $level]);

        // Add resource to banck
        $updateStmt = $pdo->prepare("
            UPDATE resources_card rc
            JOIN building_resources_card brc ON brc.id_card = rc.id
            SET rc.current_count = rc.current_count - brc.qty
            WHERE brc.id_building = (1 + :level_town)
        ");
        $updateStmt->execute(["level_town" => $level]);
    }

    // Add town
    $updateStmt = $pdo->prepare(
        "UPDATE town t
        JOIN users u ON u.username = :username
        JOIN player p ON p.id_user = u.id
        SET t.player_id = p.id, t.level = :level_town
        WHERE t.id = :build_id"
    );
    $updateStmt->execute([
        "level_town" => $level,
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
