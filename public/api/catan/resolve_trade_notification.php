<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data) || !isset($data["tradeId"], $data["playerId"], $data["acceptTrade"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid request payload"]);
  exit;
}

$trade_id = (int)$data["tradeId"];
$player_id = (int)$data["playerId"];
$accept_trade = filter_var($data["acceptTrade"], FILTER_VALIDATE_BOOL);

if ($player_id <= 0 || $trade_id <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "player ID and trade ID are required"]);
  exit;
}

require_once dirname(__DIR__) . "/database.php";

try {
  $pdo = db();
  $pdo->beginTransaction();

  // 1) Lock trade notification
  $getTn = $pdo->prepare("
    SELECT tn.*
    FROM trade_notifications tn
    WHERE tn.id = :trade_id
      AND (tn.to_id_player = :p1 OR tn.from_id_player = :p2)
    FOR UPDATE
  ");
  $getTn->execute([
    "trade_id" => $trade_id,
    "p1" => $player_id,
    "p2" => $player_id,
  ]);
  $tn = $getTn->fetch(PDO::FETCH_ASSOC);

  if (!$tn) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Trade not found or user not in trade"]);
    exit;
  }

  // Parse JSON arrays
  $from = json_decode((string)$tn["from_resource_ids"], true);
  $to   = json_decode((string)($tn["to_resource_ids"] ?? "[]"), true);

  if (!is_array($from)) $from = [];
  if (!is_array($to))   $to   = [];

  // Si aceptas, debe existir respuesta del TO
  if ($accept_trade) {
    if ($tn["to_resource_ids"] === null) {
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(["ok" => false, "message" => "Trade has no response yet"]);
      exit;
    }

    // 2) Check FROM has enough
    foreach ($from as $idx => $qtyFrom) {
      $qtyFrom = (int)$qtyFrom;
      $resource_id = (int)$idx + 1;

      $check = $pdo->prepare("
        SELECT (qty >= :need) AS ok
        FROM player_resources_card
        WHERE id_player = :pid AND id_card = :cid
        FOR UPDATE
      ");
      $check->execute([
        "need" => $qtyFrom,
        "pid" => (int)$tn["from_id_player"],
        "cid" => $resource_id,
      ]);
      if ((int)$check->fetchColumn() !== 1) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["ok" => false, "message" => "FROM player has not enough resources"]);
        exit;
      }
    }

    // 3) Check TO has enough
    foreach ($to as $idx => $qtyTo) {
      $qtyTo = (int)$qtyTo;
      $resource_id = (int)$idx + 1;

      $check = $pdo->prepare("
        SELECT (qty >= :need) AS ok
        FROM player_resources_card
        WHERE id_player = :pid AND id_card = :cid
        FOR UPDATE
      ");
      $check->execute([
        "need" => $qtyTo,
        "pid" => (int)$tn["to_id_player"],
        "cid" => $resource_id,
      ]);
      if ((int)$check->fetchColumn() !== 1) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(["ok" => false, "message" => "TO player has not enough resources"]);
        exit;
      }
    }

    // 4) Apply exchange (FROM: -from +to)
    foreach ($from as $idx => $qtyFrom) {
      $qtyFrom = (int)$qtyFrom;
      $qtyTo = (int)($to[$idx] ?? 0);
      $cid = (int)$idx + 1;

      $upd = $pdo->prepare("
        UPDATE player_resources_card
        SET qty = qty - :qFrom + :qTo
        WHERE id_player = :pid AND id_card = :cid
      ");
      $upd->execute([
        "qFrom" => $qtyFrom,
        "qTo" => $qtyTo,
        "pid" => (int)$tn["from_id_player"],
        "cid" => $cid,
      ]);
    }

    // 5) Apply exchange (TO: +from -to)
    foreach ($from as $idx => $qtyFrom) {
      $qtyFrom = (int)$qtyFrom;
      $qtyTo = (int)($to[$idx] ?? 0);
      $cid = (int)$idx + 1;

      $upd = $pdo->prepare("
        UPDATE player_resources_card
        SET qty = qty + :qFrom - :qTo
        WHERE id_player = :pid AND id_card = :cid
      ");
      $upd->execute([
        "qFrom" => $qtyFrom,
        "qTo" => $qtyTo,
        "pid" => (int)$tn["to_id_player"],
        "cid" => $cid,
      ]);
    }
  }

  // 6) Always delete notification at the end
  $del = $pdo->prepare("DELETE FROM trade_notifications WHERE id = :id");
  $del->execute(["id" => (int)$tn["id"]]);

  $pdo->commit();
  echo json_encode(["ok" => true]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error", "debug" => $e->getMessage()]);
}
