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
  $town_rows = db()->query(
      "SELECT tw.id, tw.pos_x, tw.pos_y, tw.pos_z, py.color
        FROM town as tw
        JOIN player as py
        WHERE tw.player_id IS NOT NULL
            AND py.id = tw.player_id"
  )->fetchAll(PDO::FETCH_ASSOC);

  $path_rows = db()->query(
      "SELECT twc.pos_x, twc.pos_y, twc.pos_z, twc.rot_x, twc.rot_y, twc.rot_z, twc.from_town_id, twc.to_town_id, py.color
        FROM town_conections as twc
        JOIN player as py
        WHERE twc.player_id IS NOT NULL
            AND twc.from_town_id < twc.to_town_id
            AND py.id = twc.player_id"
  )->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
      "towns" => $town_rows,
      "paths" => $path_rows
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
