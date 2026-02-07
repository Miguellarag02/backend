<?php
declare(strict_types=1);
header("Content-Type: application/json");

// Solo permitir POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "ok" => false,
        "message" => "Method not allowed"
    ]);
    exit;
}

// Leer JSON del body
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

// Validar JSON
if (!$data || !isset($data["username"], $data["password"])) {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "message" => "Invalid request payload"
    ]);
    exit;
}

$username = trim($data["username"]);
$password = $data["password"];

// Validación básica
if ($username === "" || $password === "") {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "message" => "Username and password are required"
    ]);
    exit;
}

/* ===============================
   CONEXIÓN A BASE DE DATOS
   =============================== */

$config = require dirname(__DIR__, 3) . "/src/config/config.php";
$dbConfig = $config["db"] ?? null;

if (!is_array($dbConfig)) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "message" => "Database connection error"
    ]);
    exit;
}

try {
    $pdo = new PDO(
        sprintf(
            "mysql:host=%s;dbname=%s;charset=%s",
            $dbConfig["host"],
            $dbConfig["name"],
            $dbConfig["charset"] ?? "utf8mb4"
        ),
        $dbConfig["user"],
        $dbConfig["pass"],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "message" => "Database connection error"
    ]);
    exit;
}

/* ===============================
   AUTENTICACIÓN
   =============================== */

$stmt = $pdo->prepare("
    SELECT id, username, password_hash, user_image
    FROM users
    WHERE username = :username
    LIMIT 1
");
$stmt->execute(["username" => $username]);
$userRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userRow || !password_verify($password, $userRow["password_hash"])) {
    http_response_code(401);
    echo json_encode([
        "ok" => false,
        "message" => "Invalid username or password"
    ]);
    exit;
}

/* ===============================
   LOGIN OK → SESIÓN
   =============================== */

session_start();
$_SESSION["user_id"] = $userRow["id"];
$_SESSION["username"] = $userRow["username"];

echo json_encode([
    "ok" => true,
    "message" => "Login successful",
    "user" => [
        "id" => $userRow["id"],
        "username" => $userRow["username"],
        "user_image" => $userRow["user_image"]
    ]
]);
