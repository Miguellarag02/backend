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

if (!is_array($data) || !isset($data["username"], $data["color"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);
$color = strtolower(trim((string)$data["color"]));

if ($username === "" || $color === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username and color are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
  $pdo = db();
  $pdo->beginTransaction();

  $conflictStmt = $pdo->prepare(
    "SELECT 1
     FROM player p
     JOIN users u ON u.id = p.id_user
     WHERE LOWER(p.color) = :color
       AND u.username <> :username
     LIMIT 1"
  );
  $conflictStmt->execute([
    "color" => $color,
    "username" => $username,
  ]);

  if ($conflictStmt->fetchColumn()) {
    $pdo->rollBack();
    http_response_code(409);
    echo json_encode(["ok" => false, "message" => "Color already taken"]);
    exit;
  }

  $updateStmt = $pdo->prepare(
    "UPDATE player p
     JOIN users u ON u.id = p.id_user
     SET p.color = :color
     WHERE u.username = :username"
  );
  $updateStmt->execute([
    "color" => $color,
    "username" => $username,
  ]);

  if ($updateStmt->rowCount() === 0) {
    $insertStmt = $pdo->prepare(
      "INSERT INTO player (id_user, color)
       SELECT u.id, :color
       FROM users u
       WHERE u.username = :username"
    );
    $insertStmt->execute([
      "color" => $color,
      "username" => $username,
    ]);

    if ($insertStmt->rowCount() === 0) {
      $pdo->rollBack();
      http_response_code(404);
      echo json_encode(["ok" => false, "message" => "User not found"]);
      exit;
    }
  }

  $pdo->commit();
  echo json_encode(["ok" => true, "color" => $color]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
