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
  $rows = db()->query(
    "SELECT id, letter, dice_number, pos_x, pos_y, pos_z, is_thief, thief_pos_x, thief_pos_y, thief_pos_z, thief_rot_z, resource_id, letter_pos_x, letter_pos_y, letter_pos_z
     FROM hexagon"
  )->fetchAll();

  echo json_encode($rows);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
