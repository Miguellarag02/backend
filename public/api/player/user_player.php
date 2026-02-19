<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

$username = trim((string)($_GET["username"] ?? ""));
if ($username === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username is required"]);
  exit;
}

try {
  $pdo = db();

  // 1) Player + cards en 1 query
  $stmt = $pdo->prepare(
    "SELECT
        p.id AS player_id,
        p.color AS color,
        p.current_order AS current_order,
        COALESCE((
          SELECT JSON_ARRAYAGG(
            JSON_OBJECT('id', x.id, 'name', x.name, 'qty', x.qty, 'trade_qty', x.trade_qty)
          )
          FROM (
            SELECT
              rc.id AS id,
              rc.card_name AS name,
              prc.qty AS qty,
              LEAST(
                COALESCE(MIN(t_spec.resource_trade_qty), 4),
                COALESCE(MIN(t_gen.resource_trade_qty), 4)
              ) AS trade_qty
            FROM player_resources_card prc
            JOIN resources_card rc
              ON rc.id = prc.id_card
            LEFT JOIN town t_spec
              ON t_spec.player_id = prc.id_player
            AND t_spec.resource_trade_id = rc.id
            LEFT JOIN town t_gen
              ON t_gen.player_id = prc.id_player
            AND t_gen.resource_trade_id IS NULL
            WHERE prc.id_player = 1
              AND rc.id <> 6
            GROUP BY rc.id, prc.qty
          ) x
        ), JSON_ARRAY()) AS resource_cards,
        COALESCE((
          SELECT JSON_ARRAYAGG(
            JSON_OBJECT('id', y.id, 'name', y.name, 'qty', y.qty)
          )
          FROM (
            SELECT r.id AS id, r.card_name AS name, pr.qty AS qty
            FROM player_random_card pr
            JOIN random_card r ON r.id = pr.id_card
            WHERE pr.id_player = p.id
            GROUP BY r.id
          ) y
        ), JSON_ARRAY()) AS random_cards
      FROM users u
      JOIN player p ON p.id_user = u.id
      WHERE u.username = :username
      LIMIT 1;"
  );
  $stmt->execute(["username" => $username]);
  $player = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$player) {
    http_response_code(404);
    echo json_encode(["ok" => false, "message" => "User not found"]);
    exit;
  }

  $player["resource_cards"] = json_decode($player["resource_cards"], true) ?? [];
  $player["random_cards"]   = json_decode($player["random_cards"], true) ?? [];

  $stmt = $pdo->prepare(
    "SELECT
        (b1.max_count - COALESCE((
          SELECT COUNT(*)
          FROM town_conections tc
          WHERE tc.player_id = :pid_paths
        ), 0)) AS available_paths,

        (b2.max_count - COALESCE((
          SELECT COUNT(*)
          FROM town t
          WHERE t.player_id = :pid_town AND t.level = 1
        ), 0)) AS available_town,

        (b3.max_count - COALESCE((
          SELECT COUNT(*)
          FROM town t
          WHERE t.player_id = :pid_city AND t.level = 2
        ), 0)) AS available_city,

        (SELECT COALESCE(SUM(rc.max_count - rc.current_count), 0) FROM random_card rc) AS available_random_card

      FROM building b1
      JOIN building b2 ON b2.id = 2
      JOIN building b3 ON b3.id = 3
      WHERE b1.id = 1
      LIMIT 1;"
  );

  $stmt->execute([
    "pid_paths" => (int)$player["player_id"],
    "pid_town"  => (int)$player["player_id"],
    "pid_city"  => (int)$player["player_id"],
  ]);
  $available = $stmt->fetch(PDO::FETCH_ASSOC);

  // Get current trade notification
  $getTn = $pdo->prepare("SELECT * FROM trade_notifications tn ORDER BY tn.id DESC");
  $getTn->execute();
  $tns = $getTn->fetchAll(PDO::FETCH_ASSOC);

  // Get current turn and order
  $getGm = $pdo->prepare("
    SELECT * 
      FROM game_match 
      LIMIT 1
  ");
  $getGm->execute();
  $gm = $getGm->fetch(PDO::FETCH_ASSOC);

  // Get n_players
  $getNumPlyer = $pdo->prepare("
    SELECT COUNT(*) AS n_players
      FROM player
      WHERE player.is_playing = TRUE
  ");
  $getNumPlyer->execute();
  $numPlayer = $getNumPlyer->fetch(PDO::FETCH_ASSOC);
 
  echo json_encode([
    "ok" => true,
    "player" => [
      "id" => (int)$player["player_id"],
      "color" => $player["color"],
      "order" => $player["current_order"],
      "resource_cards" => $player["resource_cards"],
      "random_cards" => $player["random_cards"],
    ],
    "available" => [
      1 => (int)$available["available_paths"],
      2 => (int)$available["available_town"],
      3 => (int)$available["available_city"],
      4 => (int)$available["available_random_card"],
    ],
    "trade_notification" => $tns,
    "game_match" => $gm,
    "n_players" => $numPlayer
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Database error",
    //"debug" => $e->getMessage() // activa solo en dev
  ]);
}
