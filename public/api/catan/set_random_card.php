<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

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
  echo json_encode(["ok" => false, "message" => "Username is required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // 1) Read all cards with their "remaining" value
    // FOR UPDATE locks rows so two simultaneous draws do not break the count (InnoDB).
    $stmt = $pdo->prepare("
        SELECT id, card_name, current_count, max_count,
               (max_count - current_count) AS remaining
        FROM random_card
        WHERE current_count < max_count
        FOR UPDATE
    ");
    $stmt->execute();
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$cards) {
        $pdo->rollBack();
        throw new RuntimeException("No quedan cartas en el mazo.");
    }

    // 2) Remaining total
    $totalRemaining = 0;
    foreach ($cards as $c) {
        $totalRemaining += (int)$c["remaining"];
    }

    if ($totalRemaining <= 0) {
        $pdo->rollBack();
        throw new RuntimeException("No quedan cartas en el mazo.");
    }

    // 3) Pick a random "ticket" between 1..totalRemaining
    $ticket = random_int(1, $totalRemaining);
    $picked = null;
    foreach ($cards as $c) {
        $ticket -= (int)$c["remaining"];
        if ($ticket <= 0) {
            $picked = $c;
            break;
        }
    }

    if ($picked === null) {
        $pdo->rollBack();
        throw new RuntimeException("Error interno eligiendo carta.");
    }

    // 4) Check enough resources for the card
    $checkStmt = $pdo->prepare("
        SELECT MIN(brc.qty <= pyc.qty) AS available
        FROM building_resources_card brc
        JOIN users u ON u.username = :username
        JOIN player p ON p.id_user = u.id
        JOIN player_resources_card pyc
            ON pyc.id_player = p.id AND brc.id_card = pyc.id_card
        WHERE brc.id_building = 4
        FOR UPDATE
    ");
    $checkStmt->execute(["username" => $username]);
    $available = (int) $checkStmt->fetchColumn();

    if ($available === 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["ok" => false, "message" => "Not enough materials"]);
        exit;
    }

    // 5) Remove resources from the player
    $updateStmt = $pdo->prepare("
        UPDATE player_resources_card pyc
        JOIN player p ON pyc.id_player = p.id
        JOIN users u ON p.id_user = u.id
        JOIN building_resources_card brc ON brc.id_card = pyc.id_card
        SET pyc.qty = pyc.qty - brc.qty
        WHERE u.username = :username
            AND brc.id_building = 4
    ");
    $updateStmt->execute(["username" => $username]);

    // 6) Add resources to bank (subtracting total resources in play)
    $updateStmt = $pdo->prepare("
        UPDATE resources_card rc
        JOIN building_resources_card brc ON brc.id_card = rc.id
        SET rc.current_count = rc.current_count - brc.qty
        WHERE brc.id_building = 4
    ");
    $updateStmt->execute([]);

    // 7) Update current_count (+1) ensuring it does not exceed max
    $upd = $pdo->prepare("
        UPDATE random_card
        SET current_count = current_count + 1
        WHERE id = :id AND current_count < max_count
    ");
    $upd->execute([":id" => $picked["id"]]);

    if ($upd->rowCount() !== 1) {
        // This can happen in race conditions if locking failed (or engine is not InnoDB).
        $pdo->rollBack();
        throw new RuntimeException("No se pudo robar la carta (posible concurrencia).");
    }

    // 8) Add the card to the player
    $sel = $pdo->prepare("
        UPDATE player_random_card prc
        JOIN users u ON u.username = :username
        JOIN player p ON p.id_user = u.id
        SET prc.qty = prc.qty + 1
        WHERE prc.id_card = :id
            AND prc.id_player = p.id;"
    );
    $sel->execute(["id" => $picked["id"], "username" => $username]);

    $pdo->commit();
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "Database error"]);
}
