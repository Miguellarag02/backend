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
    $pdo->beginTransaction();

    // Check if the game has started, in that case reject
    $getStartGame = $pdo->prepare("
        SELECT (round > 0) AS started
        FROM game_match
        WHERE id = 1
    ");
    $getStartGame->execute();
    $startedGame = $getStartGame->fetch(PDO::FETCH_ASSOC);

    if ($startedGame && (int)$startedGame["started"] === 1) {
        throw new RuntimeException(
            "The game has started"
        );
    }
    

    $insertStmt = $pdo->prepare(
        "UPDATE player
            JOIN users ON users.username = :username
        SET player.is_playing = NOT player.is_playing
            WHERE player.id_user = users.id;
        ");
    $insertStmt->execute([
        "username" => $username
    ]);

    // Verificamos ahora si la partida puede dar comienzo
    // Get n_players
    $getStartGame = $pdo->prepare("
        SELECT COUNT(player.id) = game_match.max_player AS startGame
        FROM game_match
            JOIN player ON player.is_playing = TRUE
            GROUP BY game_match.id
        FOR UPDATE
    ");
    $getStartGame->execute();
    $startGame = $getStartGame->fetch(PDO::FETCH_ASSOC);

    if ($startGame && (int)$startGame["startGame"] === 1) {
        // 1. Obtener jugadores activos
        $stmt = $pdo->query("
            SELECT id
            FROM player
            WHERE is_playing = TRUE
        ");
        $players = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($players) === 0) {
            throw new Exception("No players currently playing");
        }

        // 2. Mezclar aleatoriamente
        shuffle($players);

        // 3. Asignar orden
        $update = $pdo->prepare("
            UPDATE player
            SET current_order = :order
            WHERE id = :id
        ");

        $order = 1;
        foreach ($players as $id) {
            $update->execute([
                ':order' => $order,
                ':id' => $id
            ]);
            $order++;
        }

        // 4) Leer recursos y construir la bolsa respetando max_hex_count
        $resStmt = $pdo->query("SELECT id, max_hex_count FROM resources_card ORDER BY id");
        $resources = $resStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$resources) {
            throw new RuntimeException("No hay recursos en la tabla resources.");
        }

        $pool = [];
        $expectedDeserts = 0;

        foreach ($resources as $r) {
            $rid = (int)$r["id"];
            $count = (int)$r["max_hex_count"];
            if ($count < 0) {
                throw new RuntimeException("max_hex_count inválido para resource id={$rid}");
            }
            if ($rid === 6) $expectedDeserts += $count;
            for ($i = 0; $i < $count; $i++) {
                $pool[] = $rid;
            }
        }

        // Bloquear hexágonos y leerlos en orden estable
        $hexStmt = $pdo->query("SELECT id, dice_number, letter FROM hexagon ORDER BY id FOR UPDATE");
        $hexagons = $hexStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$hexagons) {
            throw new RuntimeException("No hay filas en la tabla hexagon.");
        }

        $hexCount = count($hexagons);
        if (count($pool) !== $hexCount) {
            throw new RuntimeException(
                "La suma de max_hex_count (" . count($pool) . ") no coincide con nº de hexágonos ({$hexCount})."
            );
        }

        if ($expectedDeserts !== 1) {
            // Tu caso: Desierto=1. Si cambias la tabla, te avisa.
            throw new RuntimeException("Se esperaba exactamente 1 desierto (resource id=6). Hay: {$expectedDeserts}");
        }

        // 2) Aleatorizar pool y asignar resource_id a cada hexagon en orden
        shuffle($pool);

        $updateRes = $pdo->prepare("UPDATE hexagon SET resource_id = :rid WHERE id = :hid");
        $hexOrder = []; // guardamos orden con resource asignado

        for ($i = 0; $i < $hexCount; $i++) {
            $hid = (int)$hexagons[$i]["id"];
            $rid = (int)$pool[$i];

            $updateRes->execute([
                ":rid" => $rid,
                ":hid" => $hid,
            ]);

            $hexOrder[] = ["id" => $hid, "resource_id" => $rid];
        }

        // 3) Construir la secuencia ORIGINAL de dice_number y letter (sin NULL) en orden por id
        $seqStmt = $pdo->query(
            "SELECT dice_number, letter
             FROM hexagon
             WHERE dice_number IS NOT NULL
             ORDER BY id"
        );
        $seqRaw = $seqStmt->fetchAll(PDO::FETCH_ASSOC);

        $diceSeq = array_map('intval', array_column($seqRaw, 'dice_number')); // lista de 18 números
        $letterSeq = array_map('strval', array_column($seqRaw, 'letter')); // lista de 18 letras

        // Validación: debe haber exactamente (hexCount - 1) números si hay 1 desierto
        if (count($diceSeq) !== $hexCount - 1 || count($letterSeq) !== $hexCount - 1) {
            throw new RuntimeException(
                "La secuencia de dice_number/letter no NULL (" . count($diceSeq) . "/" . count($letterSeq) .
                ") no coincide con hexágonos-1 (" . ($hexCount - 1) . ")."
            );
        }

        // 4) Reasignar dice_number: desierto => NULL, resto => siguiente de diceSeq
        $updateSeq = $pdo->prepare("UPDATE hexagon SET dice_number = :dn, letter = :lett WHERE id = :hid");

        $k = 0;
        $desertHexId = null;

        foreach ($hexOrder as $h) {
            $hid = (int)$h["id"];
            $rid = (int)$h["resource_id"];

            if ($rid === 6) {
                $desertHexId = $hid;
                $updateSeq->execute([
                    ":dn"  => null,
                    ":hid" => $hid,
                    ":lett" => 's'
                ]);
            } else {
                $updateSeq->execute([
                    ":dn"  => $diceSeq[$k],
                    ":hid" => $hid,
                    ":lett" => $letterSeq[$k],
                ]);
                $k++;
            }
        }

        if ($desertHexId === null) {
            throw new RuntimeException("No se encontró hexágono con resource_id=6 tras la aleatorización.");
        }
        if ($k !== count($diceSeq) || $k !== count($letterSeq)) {
            throw new RuntimeException("No se consumió toda la secuencia de dice_number/letter. Consumidos={$k}");
        }

        // Finally, if all the last process was fine, they mpa is generated, so we start officialy the game:
        $updateStartGame = $pdo->prepare("
            UPDATE game_match
            SET turn = 1, round = 1
            WHERE id = 1
        ");
        $updateStartGame->execute();
    }

    $pdo->commit();
    echo json_encode(["ok" => true]);


} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode([
    "ok" => false, 
    "message" => "Database error",
    "debug" => $e->getMessage()
    ]);
}
