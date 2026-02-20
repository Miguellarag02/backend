<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data) || !isset($data["username"], $data["diceResult"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);
$diceResult = (int)$data["diceResult"];

if ($username === "" || $diceResult < 1 || $diceResult > 12) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username and dice_results are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Check if the player has the current turn
    $check = $pdo->prepare("
        SELECT (gm.turn = player.current_order) AS has_turn
        FROM game_match gm
        JOIN users ON users.username = :username
        JOIN player ON player.id_user = users.id
        WHERE gm.id = 1
        FOR UPDATE
    ");
    $check->execute([
        "username" => $username
    ]);
    $hasTurn = (int) $check->fetchColumn();

    if ($hasTurn === 1) {

        // Update last throw
        $updateDice = $pdo->prepare("
            UPDATE game_match
            SET last_dice = :dice_results
            WHERE id = 1
        ");
        $updateDice->execute([
            "dice_results" => $diceResult
        ]);

        // Update resources from players
        $updateStmt = $pdo->prepare("
            UPDATE player_resources_card prc
                JOIN (
                SELECT
                    t.player_id   AS id_player,
                    h.resource_id AS id_card,
                    SUM(t.level)  AS qty
                FROM town t
                JOIN hexagon_conections hxc ON hxc.to_town_id = t.id
                JOIN hexagon h              ON h.id = hxc.from_hexagon_id
                WHERE h.dice_number = :dice_result
                    AND t.player_id IS NOT NULL
                GROUP BY t.player_id, h.resource_id
                ) prod
                ON prod.id_player = prc.id_player
                AND prod.id_card   = prc.id_card
                SET prc.qty = prc.qty + prod.qty;
        ");
        $updateStmt->execute(["dice_result" => $diceResult]);

        // Update resources banck
        $updateStmt = $pdo->prepare("
            UPDATE resources_card rc
            JOIN (
                SELECT
                    h.resource_id AS id_card,
                    SUM(t.level)  AS qty
                FROM town t
                JOIN hexagon_conections hxc ON hxc.to_town_id = t.id
                JOIN hexagon h              ON h.id = hxc.from_hexagon_id
                WHERE h.dice_number = :dice_result
                    AND t.player_id IS NOT NULL
                GROUP BY t.player_id, h.resource_id
            ) prod
            ON prod.id_card = rc.id
            SET rc.current_count = rc.current_count + prod.qty;
        ");
        $updateStmt->execute(["dice_result" => $diceResult]);
    }
    else {
        throw new RuntimeException("The player doesn't have the current turn");
    }

    $pdo->commit();
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof RuntimeException && $e->getMessage() === "The player doesn't have the current turn") {
        http_response_code(403);
        echo json_encode(["ok" => false, "message" => $e->getMessage()]);
    } else {
        http_response_code(500);
        echo json_encode(["ok" => false, "message" => "Database error", "debug" => $e->getMessage()]);
    }
}
