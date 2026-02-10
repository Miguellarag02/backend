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
    "SELECT users.username,
              player.color,
              users.user_image,
              JSON_ARRAYAGG(
                JSON_OBJECT(
                  'id', rc.id,
                  'name', rc.card_name,
                  'qty', prc.qty
                )
              ) AS resources
        FROM users
        JOIN player ON users.id = player.id_user
        JOIN player_resources_card AS prc ON prc.id_player = player.id
        JOIN resources_card AS rc ON rc.id = prc.id_card
        WHERE users.username != :username
          AND rc.id != 6
        GROUP BY users.id, users.username, player.color"
  );
  $stmt->execute(["username" => $username]);
  $rows = $stmt->fetchAll();

  echo json_encode($rows);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
