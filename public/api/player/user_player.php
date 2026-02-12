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
  $stmt = db()->prepare(
    "SELECT
        p.color AS color,
        COALESCE((
          SELECT JSON_ARRAYAGG(
                  JSON_OBJECT('id', t.id, 'name', t.name, 'qty', t.qty)
                )
          FROM (
            SELECT rc.id AS id, rc.card_name AS name, prc.qty AS qty
            FROM player_resources_card prc
            JOIN resources_card rc ON rc.id = prc.id_card
            WHERE prc.id_player = p.id
              AND rc.id <> 6
            GROUP BY rc.id, rc.card_name, prc.qty
          ) t
        ), JSON_ARRAY()) AS resource_cards,
        COALESCE((
          SELECT JSON_ARRAYAGG(
                  JSON_OBJECT('id', t2.id, 'name', t2.name, 'qty', t2.qty)
                )
          FROM (
            SELECT r.id AS id, r.card_name AS name, pr.qty AS qty
            FROM player_random_card pr
            JOIN random_card r ON r.id = pr.id_card
            WHERE pr.id_player = p.id
            GROUP BY r.id, r.card_name, pr.qty
          ) t2
        ), JSON_ARRAY()) AS random_cards
      FROM users u
      JOIN player p ON p.id_user = u.id
      WHERE u.username = :username;"
  );
  $stmt->execute(["username" => $username]);
  $rows = $stmt->fetchAll();

  echo json_encode($rows);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
