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
  $playerExistsStmt = db()->prepare(
    "SELECT 1
     FROM player p
     JOIN users u ON u.id = p.id_user
     WHERE u.username = :username
     LIMIT 1"
  );
  $playerExistsStmt->execute(["username" => $username]);
  if ($playerExistsStmt->fetchColumn() === false) {
    http_response_code(404);
    echo json_encode(["ok" => false, "message" => "Player  not found"]);
    exit;
  }

  $rows = db()->query(
    "SELECT users.username, player.color
     FROM player
     JOIN users ON users.id = player.id_user"
  )->fetchAll();

  echo json_encode(["ok" => true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
