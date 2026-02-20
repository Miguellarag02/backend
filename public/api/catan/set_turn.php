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

if (!is_array($data) || !isset($data["username"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);

if ($username === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Check if the username has the current turn
    $check = $pdo->prepare("
        SELECT (gm.turn = player.current_order) AS has_turn, player.id AS player_id, player.current_order AS player_turn
        FROM game_match gm
        JOIN users ON users.username = :username
        JOIN player ON player.id_user = users.id
        WHERE gm.id = 1
        FOR UPDATE
    ");
    $check->execute([
        "username" => $username
    ]);
    $checkTurn = $check->fetch(PDO::FETCH_ASSOC);

    if ($checkTurn && (int)$checkTurn["has_turn"] != 1) {
        throw new RuntimeException(
            "The user doesn't have the current turn"
        );
    }

    // Get Game Match
    $player_id = (string)$player_id["player_id"];
    $getGm = $pdo->prepare("SELECT * FROM game_match WHERE id = 1 FOR UPDATE");
    $getGm->execute();
    $gm = $getGm->fetch(PDO::FETCH_ASSOC);
    $updateGameMatch =  $pdo->prepare("UPDATE game_match SET turn = :turn, round = :round, last_dice = 0 WHERE id = 1");

    $current_turn = (int)$gm["turn"];
    $current_round = (int)$gm["round"];
    $player_turn = (int)$checkTurn["player_turn"];
    // Check if it is the last player
    if($current_turn === (int)$gm["max_player"]){
        // We are on the first round?
        if ($current_round === 1){
            $updateGameMatch->execute(["turn" => ($current_turn - 1), "round" => ($current_round + 1)]);
        } else {
            $updateGameMatch->execute(["turn" => 1, "round" => ($current_round + 1)]);
        }
    } else {
        // We are on the second round?
        if ($current_round === 2){
            // We are the last one?
            if($current_turn === 1){
                $updateGameMatch->execute(["turn" => 1, "round" => ($current_round + 1)]);
            } else {
                $updateGameMatch->execute(["turn" => ($current_turn - 1), "round" => $current_round]);
            }
        } else {
            $updateGameMatch->execute(["turn" => ($current_turn + 1), "round" => $current_round]);
        }
    }

    $pdo->commit();
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode([
    "ok" => false, 
    "message" => "Database error",
    "debug" => $e->getMessage()
    ]);
}
