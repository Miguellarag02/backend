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

  echo json_encode(["ok" => true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
