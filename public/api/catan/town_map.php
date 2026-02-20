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
  echo json_encode(["ok" => false, "message" => "Username is required: " . $username]);
  exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT 
            tw.id, 
            tw.level,
            tw.pos_x, 
            tw.pos_y, 
            tw.pos_z, 
            py.color, 
            u.username,
            (tw.player_id IS NOT NULL) AS is_builded,

            COALESCE((
            SELECT MAX(tw_near.player_id IS NOT NULL)
            FROM town_conections tc
            JOIN town tw_near ON tw_near.id = tc.to_town_id
            WHERE tc.from_town_id = tw.id
            ), 0) AS near_to_town,

            COALESCE((
            SELECT EXISTS (
                SELECT 1
                FROM town_conections tc
                JOIN users u_conec ON u_conec.username = :username
                JOIN player py_conec ON py_conec.id_user = u_conec.id
                WHERE (tc.from_town_id = tw.id OR tc.to_town_id = tw.id)
                AND tc.player_id = py_conec.id
            )
            ), 0) AS near_to_path

        FROM town AS tw
        LEFT JOIN player AS py ON py.id = tw.player_id
        LEFT JOIN users  AS u  ON u.id = py.id_user
    ");
    $stmt->execute(["username" => $username]);
    $town_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT twc.id, 
            twc.pos_x,
            twc.pos_y,
            twc.pos_z,
            twc.rot_x,
            twc.rot_y,
            twc.rot_z,
            twc.from_town_id,
            twc.to_town_id,
            py.color,
            (twc.player_id IS NOT NULL) AS is_builded,
            COALESCE((
                SELECT MAX(tw.player_id = py_conec.id)
                FROM town tw
                JOIN users u_conec ON u_conec.username = :username_1
                JOIN player py_conec ON py_conec.id_user = u_conec.id
                WHERE twc.from_town_id = tw.id OR twc.to_town_id = tw.id
            ), 0) AS near_to_town,
            COALESCE((
                SELECT MAX(tc.player_id IS NOT NULL)
                FROM town_conections tc
                JOIN users u_conec ON u_conec.username = :username_2
                JOIN player py_conec ON py_conec.id_user = u_conec.id
                WHERE (tc.from_town_id = twc.from_town_id 
                    OR tc.to_town_id = twc.from_town_id
                    OR tc.from_town_id = twc.to_town_id
                    OR tc.to_town_id = twc.to_town_id)
                AND tc.player_id = py_conec.id
            ), 0) AS near_to_path
        FROM town_conections as twc
        LEFT JOIN player AS py 
            ON py.id = twc.player_id
        WHERE twc.from_town_id < twc.to_town_id
    ");
    $stmt->execute([
        "username_1" => $username,
        "username_2" => $username
    ]);
    $path_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
  echo json_encode([
      "towns" => $town_rows,
      "paths" => $path_rows
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
}
