<?php
// database.php
declare(strict_types=1);

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $configPathCandidates = [
    dirname(__DIR__, 2) . "/src/config/config.php",
    dirname(__DIR__) . "/src/config/config.php",
  ];

  $configPath = null;
  foreach ($configPathCandidates as $candidate) {
    if (is_file($candidate)) {
      $configPath = $candidate;
      break;
    }
  }

  if ($configPath === null) {
    throw new RuntimeException("Database config file not found");
  }

  $config = require $configPath;
  $db = $config["db"];

  $dsn = sprintf(
    "mysql:host=%s;dbname=%s;charset=%s",
    $db["host"],
    $db["name"],
    $db["charset"]
  );

  $pdo = new PDO($dsn, $db["user"], $db["pass"], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}
