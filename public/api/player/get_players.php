<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
  $stmt = db()->prepare(
    "SELECT users.username,
              player.id,
              player.color,
              player.biggest_army,
              player.largest_path,
              player.points,
              player.current_order,
              users.user_image,
              JSON_ARRAYAGG(
                JSON_OBJECT(
                  'id', rc.id,
                  'name', rc.card_name,
                  'qty', prc.qty
                )
              ) AS resources,
              JSON_ARRAYAGG(
                JSON_OBJECT(
                  'id', rc.id,
                  'name', rc.card_name,
                  'qty', prc.qty
                )
              ) AS user_resources
        FROM users
        JOIN player ON users.id = player.id_user
        JOIN player_resources_card AS prc ON prc.id_player = player.id
        JOIN resources_card AS rc ON rc.id = prc.id_card
        WHERE rc.id != 6 AND player.is_playing = TRUE
        GROUP BY player.current_order, player.id"
  );
  $stmt->execute([]);
  $rows = $stmt->fetchAll();

  echo json_encode($rows);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
