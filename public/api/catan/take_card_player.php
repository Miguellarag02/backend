<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data) || !isset($data["username"], $data["stealPlayerId"], $data["activatedKnight"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);
$steal_player_id = (int)$data["stealPlayerId"];
$activatedKnight = (int)$data["activatedKnight"];

if ($username === "" || $steal_player_id <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "username and steal_player_id are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
  $pdo = db();
  $pdo->beginTransaction();

  // Resolve actor only once; all later updates use player.id directly.
  $actorStmt = $pdo->prepare("
    SELECT p.id
    FROM player p
    JOIN users u ON u.id = p.id_user
    WHERE u.username = :username
    LIMIT 1
    FOR UPDATE
  ");
  $actorStmt->execute(["username" => $username]);
  $actor_player_id = (int)$actorStmt->fetchColumn();

  if ($actor_player_id <= 0) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(["ok" => false, "message" => "Player not found"]);
    exit;
  }

  if (!$activatedKnight) { // 1.1) Check dices with 7 value
    $check = $pdo->query("
      SELECT (last_dice = 7) AS diceIsSeven
      FROM game_match
      WHERE id = 1
      FOR UPDATE
    ");

    if ((int)$check->fetchColumn() !== 1) {
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(["ok" => false, "message" => "Cheater? There weren't any 7 value in dices"]);
      exit;
    }
  } else { // 1.2) Check Knight and mark as activated
    $upd = $pdo->prepare("
      UPDATE player p
      JOIN player_random_card prc
        ON prc.id_player = p.id
       AND prc.id_card = 2
      SET prc.qty = prc.qty - 1,
          p.actives_knights = p.actives_knights + 1
      WHERE p.id = :actor_player_id
        AND prc.qty > 0
    ");
    $upd->execute(["actor_player_id" => $actor_player_id]);

    if ($upd->rowCount() < 1) {
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(["ok" => false, "message" => "Cheater? There weren't any Knight card available"]);
      exit;
    }

    $check = $pdo->prepare("
      SELECT
        p.actives_knights AS current_army,
        COALESCE(
          (SELECT MAX(p2.actives_knights) FROM player p2 WHERE p2.id <> p.id),
          0
        ) AS max_army
      FROM player p
      WHERE p.id = :actor_player_id
    ");
    $check->execute(["actor_player_id" => $actor_player_id]);
    $army = $check->fetch(PDO::FETCH_ASSOC) ?: ["current_army" => 0, "max_army" => 0];

    $current_army = (int)$army["current_army"];
    $max_army = (int)$army["max_army"];

    if ($current_army >= 3 && $current_army > $max_army) {
      $upd = $pdo->prepare("
        UPDATE player
        SET biggest_army = (id = :actor_player_id)
      ");
      $upd->execute(["actor_player_id" => $actor_player_id]);
    }
  }

  // 2) Pick a weighted-random resource directly in SQL (probability proportional to qty).
  $getRsc = $pdo->prepare("
    SELECT prc.id_card
    FROM player_resources_card prc
    WHERE prc.id_player = :pid
      AND prc.qty > 0
    ORDER BY (-LOG(1 - RAND()) / prc.qty)
    LIMIT 1
    FOR UPDATE
  ");
  $getRsc->execute(["pid" => $steal_player_id]);
  $stolenResourceId = (int)$getRsc->fetchColumn();

  if ($stolenResourceId > 0) {
    // 3) Apply exchange in one query (victim -1, actor +1).
    $upd = $pdo->prepare("
      UPDATE player_resources_card victim
      JOIN player_resources_card actor
        ON actor.id_player = :actor_player_id
       AND actor.id_card = victim.id_card
      SET victim.qty = victim.qty - 1,
          actor.qty = actor.qty + 1
      WHERE victim.id_player = :victim_player_id
        AND victim.id_card = :cid
        AND victim.qty > 0
    ");
    $upd->execute([
      "actor_player_id" => $actor_player_id,
      "victim_player_id" => $steal_player_id,
      "cid" => $stolenResourceId,
    ]);

    if ($upd->rowCount() < 1) {
      throw new RuntimeException("Failed to transfer resource card");
    }
  } else {
    $stolenResourceId = 0;
  }

  $pdo->commit();
  echo json_encode(["ok" => true, "stolenResourceId" => $stolenResourceId]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error", "debug" => $e->getMessage()]);
}
