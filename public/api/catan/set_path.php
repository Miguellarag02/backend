<?php
declare(strict_types=1);

function longestRoadForPlayer(PDO $pdo, int $playerId): int
{
    // 1) Load edges for this player
    $stmt = $pdo->prepare("
        SELECT id, from_town_id, to_town_id
        FROM town_conections
        WHERE player_id = :pid
    ");
    $stmt->execute(['pid' => $playerId]);
    $edges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($edges) < 5) {
        return 0;
    }

    // 2) Build adjacency list: node => list of [to, edgeId]
    $adj = []; // int => array of ['to' => int, 'edgeId' => int]
    foreach ($edges as $e) {
        $edgeId = (int)$e['id'];
        $a = (int)$e['from_town_id'];
        $b = (int)$e['to_town_id'];

        $adj[$a][] = ['to' => $b, 'edgeId' => $edgeId];
        $adj[$b][] = ['to' => $a, 'edgeId' => $edgeId]; // undirected
    }

    // 3) DFS + backtracking (mark used edges)
    $best = 0;
    $used = []; // edgeId => true

    $dfs = function (int $node, int $len) use (&$dfs, &$best, &$used, &$adj): void {
        if ($len > $best) {
            $best = $len;
        }

        if (!isset($adj[$node])) {
            return;
        }

        foreach ($adj[$node] as $nbr) {
            $edgeId = (int)$nbr['edgeId'];
            if (isset($used[$edgeId])) {
                continue; // do not reuse edge
            }
            $used[$edgeId] = true;
            $dfs((int)$nbr['to'], $len + 1);
            unset($used[$edgeId]); // backtrack
        }
    };

    // Start DFS from every node in the player's subgraph
    foreach (array_keys($adj) as $startNode) {
        $dfs((int)$startNode, 0);
    }

    return $best;
}

header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data) || !isset($data["username"], $data["buildId"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$username = trim((string)$data["username"]);
$build_id = $data["buildId"];

if ($username === "" || $build_id < 1 ) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username and build ID are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
    $pdo = db();
    $pdo->beginTransaction();

        // Check if the player is in the first round, in that case the build is free
    $check = $pdo->prepare("
        SELECT ((gm.turn = player.current_order) && (gm.round = 1 || gm.round = 2)) AS build_free
        FROM game_match gm
        JOIN users ON users.username = :username
        JOIN player ON player.id_user = users.id
        WHERE gm.id = 1
        FOR UPDATE
    ");
    $check->execute([
        "username" => $username
    ]);
    $checkRound = (int) $check->fetchColumn();

    if ($checkRound != 1) {

        // Check enough materials
        $checkStmt = $pdo->prepare("
            SELECT MIN(brc.qty <= pyc.qty) AS available
            FROM building_resources_card brc
            JOIN users u ON u.username = :username
            JOIN player p ON p.id_user = u.id
            JOIN player_resources_card pyc
                ON pyc.id_player = p.id AND brc.id_card = pyc.id_card
            WHERE brc.id_building = 1
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

        // Delete the requires materials
        $updateStmt = $pdo->prepare("
            UPDATE player_resources_card pyc
            JOIN player p ON pyc.id_player = p.id
            JOIN users u ON p.id_user = u.id
            JOIN building_resources_card brc ON brc.id_card = pyc.id_card
            SET pyc.qty = pyc.qty - brc.qty
            WHERE u.username = :username
                AND brc.id_building = 1
        ");
        $updateStmt->execute(["username" => $username]);

        // Add resource to banck
        $updateStmt = $pdo->prepare("
            UPDATE resources_card rc
            JOIN building_resources_card brc ON brc.id_card = rc.id
            SET rc.current_count = rc.current_count - brc.qty
            WHERE brc.id_building = 1 
        ");
        $updateStmt->execute([]);
    }

    // Add path
    $updateStmt = $pdo->prepare(
        "UPDATE town_conections tc
        JOIN users u ON u.username = :username
        JOIN player p ON p.id_user = u.id
        SET tc.player_id = p.id
        WHERE tc.id = :build_id"
    );
    $updateStmt->execute([
        "build_id" => $build_id,
        "username" => $username,
    ]);
    
    // Resolve player id for longest road calculation
    $playerStmt = $pdo->prepare("
        SELECT p.id
        FROM users u
        JOIN player p ON p.id_user = u.id
        WHERE u.username = :username
        LIMIT 1
    ");
    $playerStmt->execute([
        "username" => $username,
    ]);
    $playerId = (int)$playerStmt->fetchColumn();

    if ($playerId < 1) {
        throw new RuntimeException("Player not found");
    }

    // Now check the longest road
    $largest_road = longestRoadForPlayer($pdo, $playerId);

    if ($largest_road >= 5) {

        // Check if we are the longest path
        $checkStmt = $pdo->prepare("
            SELECT (MAX(largest_path) < :user_path) AS largest
            FROM game_match
        ");
        $checkStmt->execute(["user_path" => $largest_road]);
        $largest = (int) $checkStmt->fetchColumn();

        if ($largest == 1) {
            $updateStmt = $pdo->prepare(
                "UPDATE player p
                JOIN users u ON u.username = :username
                SET p.largest_path = 0
                WHERE  p.id_user != u.id"
            );
            $updateStmt->execute([
                "username" => $username
            ]);
            $updateStmt = $pdo->prepare(
                "UPDATE player p
                JOIN users u ON u.username = :username
                SET p.largest_path = 1
                WHERE  p.id_user = u.id"
            );
            $updateStmt->execute([
                "username" => $username
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(["ok" => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "Database error"]);
}
