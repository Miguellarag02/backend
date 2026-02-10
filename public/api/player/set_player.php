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

  $insertStmt = $pdo->prepare(
    "INSERT INTO player (id_user)
     SELECT u.id
     FROM users u
     WHERE u.username = :username
       AND NOT EXISTS (
         SELECT 1
         FROM player p
         WHERE p.id_user = u.id
       )"
  );
  $insertStmt->execute([
    "username" => $username
  ]);

  if ($insertStmt->rowCount() === 0) {
    $userExistsStmt = $pdo->prepare(
      "SELECT 1
       FROM users
       WHERE username = :username
       LIMIT 1"
    );
    $userExistsStmt->execute(["username" => $username]);

    if (!$userExistsStmt->fetchColumn()) {
      http_response_code(404);
      echo json_encode(["ok" => false, "message" => "User not found"]);
      exit;
    }
  }

  $insertPlayerResourcesStmt = $pdo->prepare(
    "INSERT IGNORE INTO player_resources_card (id_player, id_card, qty)
     SELECT p.id, rc.id, 0
     FROM player p
     JOIN users u ON u.id = p.id_user
     CROSS JOIN resources_card rc
     WHERE u.username = :username"
  );
  $insertPlayerResourcesStmt->execute([
    "username" => $username
  ]);

  $insertPlayerRandomStmt = $pdo->prepare(
    "INSERT IGNORE INTO player_random_card (id_player, id_card, qty)
     SELECT p.id, rc.id, 0
     FROM player p
     JOIN users u ON u.id = p.id_user
     CROSS JOIN random_card rc
     WHERE u.username = :username"
  );
  $insertPlayerRandomStmt->execute([
    "username" => $username
  ]);

  echo json_encode(["ok" => true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
