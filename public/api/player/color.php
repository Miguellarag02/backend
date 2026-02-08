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
    "SELECT users.username, player.color
     FROM player
     JOIN users ON users.id = player.id_user"
  )->fetchAll();

  echo json_encode($rows);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
