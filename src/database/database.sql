-- Recomendado para evitar problemas con FKs al importar
SET FOREIGN_KEY_CHECKS = 0;

-- =========================
-- Limpieza para re-ejecucion
-- =========================
DROP TABLE IF EXISTS building_resources_card;
DROP TABLE IF EXISTS player_random_card;
DROP TABLE IF EXISTS player_resources_card;
DROP TABLE IF EXISTS town_conections;
DROP TABLE IF EXISTS hexagon_conections;
DROP TABLE IF EXISTS town;
DROP TABLE IF EXISTS player;
DROP TABLE IF EXISTS building;
DROP TABLE IF EXISTS random_card;
DROP TABLE IF EXISTS resources_card;
DROP TABLE IF EXISTS hexagon;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS trade_notifications;

-- =========================    
-- USERS
-- =========================
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  user_image VARCHAR(255) NULL,
  victories INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================
-- PLAYER (1-1 con users)
-- =========================
CREATE TABLE IF NOT EXISTS player (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL UNIQUE,
  color VARCHAR(10) NOT NULL DEFAULT "",
  actives_knights INT NOT NULL DEFAULT 0,
  largest_path BOOLEAN NOT NULL DEFAULT false,
  biggest_army BOOLEAN NOT NULL DEFAULT false,

  CONSTRAINT fk_player_user
    FOREIGN KEY (id_user) REFERENCES users(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CHECK (actives_knights >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_player_user ON player(id_user);

-- =========================
-- HEXAGON
-- =========================
CREATE TABLE IF NOT EXISTS hexagon (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter VARCHAR(1) NULL,
  pos_x FLOAT NOT NULL,
  pos_y FLOAT NOT NULL,
  pos_z FLOAT NOT NULL,
  is_thief BOOL NOT NULL,
  thief_pos_x FLOAT NOT NULL,
  thief_pos_y FLOAT NOT NULL,
  thief_pos_z FLOAT NOT NULL,
  thief_rot_z FLOAT NOT NULL,
  resource_id INT NULL,
  letter_pos_x FLOAT NOT NULL,
  letter_pos_y FLOAT NOT NULL,
  letter_pos_z FLOAT NOT NULL,
  dice_number INT NULL,

  CONSTRAINT fk_hexagon_resource
    FOREIGN KEY (resource_id) REFERENCES resources_card(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE

) ENGINE=InnoDB;

CREATE INDEX idx_hexagon_resource_id ON hexagon(resource_id);

-- =========================
-- TOWN
-- =========================
CREATE TABLE IF NOT EXISTS town (
  id INT AUTO_INCREMENT PRIMARY KEY,
  player_id INT NULL,
  level INT NOT NULL,
  pos_x FLOAT NOT NULL,
  pos_y FLOAT NOT NULL,
  pos_z FLOAT NOT NULL,
  resource_trade_id INT NULL DEFAULT NULL, 
  resource_trade_qty INT NULL DEFAULT NULL, 

  CONSTRAINT fk_town_player
    FOREIGN KEY (player_id) REFERENCES player(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  CONSTRAINT fk_town_resource
    FOREIGN KEY (resource_trade_id) REFERENCES resources_card(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  CHECK (level >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_town_player_id ON town(player_id);
CREATE INDEX idx_town_resource_id ON town(resource_trade_id);

-- =========================
-- HEXAGON_CONNECTIONS
-- =========================
CREATE TABLE IF NOT EXISTS hexagon_conections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_hexagon_id INT NOT NULL,
  to_town_id INT NOT NULL,

  CONSTRAINT fk_hexcon_hex
    FOREIGN KEY (from_hexagon_id) REFERENCES hexagon(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_hexcon_town
    FOREIGN KEY (to_town_id) REFERENCES town(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  UNIQUE KEY uq_hexcon (from_hexagon_id, to_town_id)
) ENGINE=InnoDB;

CREATE INDEX idx_hexcon_from ON hexagon_conections(from_hexagon_id);
CREATE INDEX idx_hexcon_to   ON hexagon_conections(to_town_id);

-- =========================
-- TOWN_CONNECTIONS
-- =========================
CREATE TABLE IF NOT EXISTS town_conections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_town_id INT NOT NULL,
  to_town_id INT NOT NULL,
  player_id INT NULL,
  pos_x FLOAT NOT NULL,
  pos_y FLOAT NOT NULL,
  pos_z FLOAT NOT NULL,
  rot_x FLOAT NOT NULL,
  rot_y FLOAT NOT NULL,
  rot_z FLOAT NOT NULL,

  CONSTRAINT fk_town_conections_player
    FOREIGN KEY (player_id) REFERENCES player(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,

  CONSTRAINT fk_towncon_from
    FOREIGN KEY (from_town_id) REFERENCES town(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_towncon_to
    FOREIGN KEY (to_town_id) REFERENCES town(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  UNIQUE KEY uq_towncon (from_town_id, to_town_id)
) ENGINE=InnoDB;

CREATE INDEX fk_town_conections_player ON town_conections(player_id);
CREATE INDEX idx_towncon_from ON town_conections(from_town_id);
CREATE INDEX idx_towncon_to   ON town_conections(to_town_id);

-- =========================
-- RESOURCES_CARD (tipos de recurso)
-- =========================
CREATE TABLE IF NOT EXISTS resources_card (
  id INT AUTO_INCREMENT PRIMARY KEY,
  card_name VARCHAR(50) NOT NULL UNIQUE,
  current_count INT NOT NULL DEFAULT 0,
  max_count INT NOT NULL,

  CHECK (current_count >= 0),
  CHECK (max_count >= 0),
  CHECK (current_count <= max_count)
) ENGINE=InnoDB;

-- =========================
-- RANDOM_CARD (tipos de carta aleatoria/desarrollo)
-- =========================
CREATE TABLE IF NOT EXISTS random_card (
  id INT AUTO_INCREMENT PRIMARY KEY,
  card_name VARCHAR(50) NOT NULL UNIQUE,
  current_count INT NOT NULL DEFAULT 0,
  max_count INT NOT NULL,

  CHECK (current_count >= 0),
  CHECK (max_count >= 0),
  CHECK (current_count <= max_count)
) ENGINE=InnoDB;

-- =========================
-- PLAYER_RESOURCES_CARD (cantidad por jugador y tipo de recurso)
-- =========================
CREATE TABLE IF NOT EXISTS player_resources_card (
  id_player INT NOT NULL,
  id_card INT NOT NULL,
  qty INT NOT NULL DEFAULT 0,

  PRIMARY KEY (id_player, id_card),

  CONSTRAINT fk_prc_player
    FOREIGN KEY (id_player) REFERENCES player(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_prc_card
    FOREIGN KEY (id_card) REFERENCES resources_card(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,

  CHECK (qty >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_prc_player ON player_resources_card(id_player);
CREATE INDEX idx_prc_card   ON player_resources_card(id_card);

-- =========================
-- PLAYER_RANDOM_CARD (cantidad por jugador y tipo de carta random)
-- =========================
CREATE TABLE IF NOT EXISTS player_random_card (
  id_player INT NOT NULL,
  id_card INT NOT NULL,
  qty INT NOT NULL DEFAULT 0,

  PRIMARY KEY (id_player, id_card),

  CONSTRAINT fk_prand_player
    FOREIGN KEY (id_player) REFERENCES player(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_prand_card
    FOREIGN KEY (id_card) REFERENCES random_card(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,

  CHECK (qty >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_prand_player ON player_random_card(id_player);
CREATE INDEX idx_prand_card   ON player_random_card(id_card);

-- =========================
-- BUILDING
-- =========================
CREATE TABLE IF NOT EXISTS building (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  max_count INT NOT NULL
) ENGINE=InnoDB;

-- =========================
-- BUILDING_RESOURCES_CARD (coste en recursos por edificio)
-- =========================
CREATE TABLE IF NOT EXISTS building_resources_card (
  id_building INT NOT NULL,
  id_card INT NOT NULL,
  qty INT NOT NULL DEFAULT 0,

  PRIMARY KEY (id_building, id_card),

  CONSTRAINT fk_brc_building
    FOREIGN KEY (id_building) REFERENCES building(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_brc_card
    FOREIGN KEY (id_card) REFERENCES resources_card(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,

  CHECK (qty >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_brc_building ON building_resources_card(id_building);
CREATE INDEX idx_brc_card     ON building_resources_card(id_card);

-- =========================
-- TRADE NOTIFICATIONS
-- =========================
CREATE TABLE IF NOT EXISTS trade_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_id_player INT NOT NULL,
  to_id_player INT NOT NULL,
  from_resource_ids JSON NULL,
  to_resource_ids JSON NULL,

  CONSTRAINT fk_from_player
    FOREIGN KEY (from_id_player) REFERENCES player(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  
  CONSTRAINT fk_to_player
    FOREIGN KEY (to_id_player) REFERENCES player(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE

) ENGINE=InnoDB;

CREATE INDEX idx_from_player ON trade_notifications(from_id_player);
CREATE INDEX idx_to_player ON trade_notifications(to_id_player);

SET FOREIGN_KEY_CHECKS = 1;

-- =========================
-- Insertar datos BUILDING
-- =========================
INSERT INTO building (id, name, max_count)
VALUES
    (1, 'Carretera',  15),
    (2, 'Pueblo',     5),
    (3, 'Ciudad',     4),
    (4, 'Gamblear',   25);

-- =========================
-- Insertar datos RESOURCES_CARD
-- =========================
INSERT INTO resources_card (id, card_name, current_count, max_count)
VALUES
    (1, 'Palo',     0, 19),
    (2, 'Oveja',    0, 19),
    (3, 'Piedra',   0, 19),
    (4, 'Paja',     0, 19),
    (5, 'Arcilla',  0, 19),
    (6, 'desierto', 0, 0);

-- =========================
-- Insertar datos CARTAS_GAMBLEAR
-- =========================
INSERT INTO random_card (id, card_name, current_count, max_count)
VALUES
    (1, 'Punto',                    0, 5  ),
    (2, 'Caballero',                0, 14 ),
    (3, 'Monopolio',                0, 2  ),
    (4, 'Constructor de caminos',   0, 2  ),
    (5, 'Recursos gratis',          0, 2  );

-- =========================
-- Insertar datos BUILDING_RESOURCES_CARD
-- =========================
INSERT INTO building_resources_card (id_building, id_card, qty)
VALUES
    (1, 1, 1),
    (1, 5, 1),
    (2, 1, 1),
    (2, 2, 1),
    (2, 3, 1),
    (2, 4, 1),
    (3, 4, 2),
    (3, 5, 3),
    (4, 2, 1),
    (4, 3, 1),
    (4, 4, 1);

-- =========================
-- Insertar datos HEXAGON
-- =========================
INSERT INTO hexagon (id, letter, dice_number, pos_x, pos_y, pos_z, is_thief, thief_pos_x, thief_pos_y, thief_pos_z, thief_rot_z, letter_pos_x, letter_pos_y, letter_pos_z,  resource_id)
VALUES
    (1,   'a',  0,    -5.26,  0.404,  3.032 , false,  -5.627, 0.436,  3.57,   0.584,  -5.208, 0.515,  2.973,  6),
    (2,   'b',  0,    -5.268, 0.404,  -0.017, false,  -5.01,  0.436,  0.752,  0.584,  -5.367, 0.515,  0.058,  6),
    (3,   'c',  0,    -5.249, 0.404,  -3.046, false,  -5.251, 0.436,  -3.825, 1.853,  -5.288, 0.515,  -2.919, 6),
    (4,   'd',  0,    -2.636, 0.404,  -4.586, false,  -2.569, 0.436,  -3.827, 0.584,  -2.534, 0.515,  -4.549, 6),
    (5,   'e',  0,    -0.005, 0.404,  -6.09 , false,  -0.834, 0.436,  -5.576, 1.955,  0.079,  0.515,  -5.983, 6),
    (6,   'f',  0,    2.624,  0.404,  -4.575, false,  2.404,  0.436,  -5.342, 2.599,  2.715,  0.515,  -4.418, 6),
    (7,   'g',  0,    5.286,  0.404,  -3.069, false,  5.965,  0.436,  -3.817, -0.243, 5.278,  0.515,  -3.004, 6),
    (8,   'h',  0,    5.278,  0.404,  -0.023, false,  5.884,  0.436,  0.547,  2.909,  5.307,  0.515,  0.138,  6),
    (9,   'i',  0,    5.29,   0.404,  3.025 , false,  6.001,  0.436,  3.511,  -2.58,  5.33,   0.515,  3.197,  6),
    (10,  'j',  0,    2.648,  0.404,  4.543 , false,  3.421,  0.436,  4.997,  3.031,  2.646,  0.515,  4.802,  6),
    (11,  'k',  0,    0.017,  0.404,  6.056 , false,  -0.023, 0.436,  7.096,  2.982,  0.064,  0.515,  6.322,  6),
    (12,  'l',  0,    -2.631, 0.404,  4.547 , false,  -2.172, 0.436,  5.229,  1.444,  -2.598, 0.515,  4.788,  6),
    (13,  'm',  0,    -2.63,  0.404,  1.503 , false,  -3.016, 0.436,  0.817,  2.228,  -2.6,   0.515,  1.715,  6),
    (14,  'n',  0,    -2.623, 0.404,  -1.534, false,  -3.14,  0.436,  -1.083, 2.18,   -2.567, 0.515,  -1.576, 6),
    (15,  'o',  0,    -0.004, 0.404,  -3.055, false,  1.039,  0.436,  -3.096, 0.584,  0.011,  0.515,  -3.085, 6),
    (16,  'p',  0,    2.628,  0.404,  -1.539, false,  2.365,  0.436,  -0.99,  1.34,   2.662,  0.515,  -1.582, 6),
    (17,  'q',  0,    2.642,  0.404,  1.499 , false,  2.236,  0.436,  0.821,  0.584,  2.792,  0.515,  1.653,  6),
    (18,  'r',  0,    0.003,  0.404,  3.019 , false,  -0.679, 0.436,  3.425,  1.669,  -0.092, 0.515,  3.173,  6),
    (19,  's',  NULL, 0.001,  0.404,  -0.021, true,   -0.524, 0.436,  -0.666, -0.243, 0.066,  0.515,  0.165,  6);
    
-- =========================
-- Insertar datos TOWN
-- =========================
INSERT INTO town (id, player_id, level, pos_x, pos_y, pos_z, resource_trade_id, resource_trade_qty)
VALUES
    (1,   NULL, 0,  -6.175, 0.626, 4.518,   NULL, NULL),
    (2,   NULL, 0,  -6.89,  0.626, 3.044,   NULL, NULL),
    (3,   NULL, 0,  -6.179, 0.626, 1.543,   1,    2   ),
    (4,   NULL, 0,  -6.903, 0.626, -0.103,  1,    2   ),
    (5,   NULL, 0,  -6.159, 0.626, -1.467,  NULL, NULL),
    (6,   NULL, 0,  -6.894, 0.626, -2.961,  6,    3   ),
    (7,   NULL, 0,  -6.037, 0.626, -4.419,  6,    3   ),
    (8,   NULL, 0,  -4.39,  0.626, -4.52,   NULL, NULL),
    (9,   NULL, 0,  -3.479, 0.626, -6.074,  5,    2   ),
    (10,  NULL, 0,  -1.777, 0.626, -6.058,  5,    2   ),
    (11,  NULL, 0,  -0.741, 0.626, -7.519,  NULL, NULL),    
    (12,  NULL, 0,  0.833,  0.626, -7.514,  NULL, NULL),
    (13,  NULL, 0,  1.796,  0.626, -6.12,   3,    2   ),
    (14,  NULL, 0,  3.462,  0.626, -5.913,  3,    2   ),
    (15,  NULL, 0,  4.349,  0.626, -4.628,  NULL, NULL),
    (16,  NULL, 0,  6.094,  0.626, -4.401,  6,    3   ),    
    (17,  NULL, 0,  6.855,  0.626, -3.045,  6,    3   ),
    (18,  NULL, 0,  6.125,  0.626, -1.465,  NULL, NULL),
    (19,  NULL, 0,  7.037,  0.626, 0.089,   4,    2   ),
    (20,  NULL, 0,  6.163,  0.626, 1.559,   4,    2   ),
    (21,  NULL, 0,  6.906,  0.626, 3.105,   NULL, NULL),
    (22,  NULL, 0,  5.988,  0.626, 4.41,    NULL, NULL),
    (23,  NULL, 0,  4.297,  0.626, 4.556,   6,    3   ),
    (24,  NULL, 0,  3.422,  0.626, 6.026,   6,    3   ),
    (25,  NULL, 0,  1.709,  0.626, 6.178,   NULL, NULL),
    (26,  NULL, 0,  0.747,  0.626, 7.389,   6,    3   ),
    (27,  NULL, 0,  -0.819, 0.626, 7.405,   6,    3   ),
    (28,  NULL, 0,  -1.82,  0.626, 6.206,   NULL, NULL),
    (29,  NULL, 0,  -3.502, 0.626, 6.077,   2,    2   ),
    (30,  NULL, 0,  -4.452, 0.626, 4.642,   2,    2   ),
    (31,  NULL, 0,  -3.478, 0.626, 3.002,   NULL, NULL),
    (32,  NULL, 0,  -4.452, 0.626, 1.66,    NULL, NULL),    
    (33,  NULL, 0,  -3.542, 0.626, 0.031,   NULL, NULL),
    (34,  NULL, 0,  -4.356, 0.626, -1.489,  NULL, NULL),
    (35,  NULL, 0,  -3.472, 0.626, -3.007,  NULL, NULL),
    (36,  NULL, 0,  -1.785, 0.626, -3.02,   NULL, NULL),
    (37,  NULL, 0,  -0.839, 0.626, -4.578,  NULL, NULL),    
    (38,  NULL, 0,  0.886,  0.626, -4.54,   NULL, NULL),
    (39,  NULL, 0,  1.79,   0.626, -3.01,   NULL, NULL),
    (40,  NULL, 0,  3.506,  0.626, -3.04,   NULL, NULL),
    (41,  NULL, 0,  4.436,  0.626, -1.515,  NULL, NULL),
    (42,  NULL, 0,  3.626,  0.626, 0.098,   NULL, NULL),
    (43,  NULL, 0,  4.348,  0.626, 1.5,     NULL, NULL),    
    (44,  NULL, 0,  3.509,  0.626, 3.004,   NULL, NULL),
    (45,  NULL, 0,  1.792,  0.626, 3.018,   NULL, NULL),
    (46,  NULL, 0,  0.852,  0.626, 4.599,   NULL, NULL),
    (47,  NULL, 0,  -0.864, 0.626, 4.547,   NULL, NULL),
    (48,  NULL, 0,  -1.805, 0.626, 3.146,   NULL, NULL),
    (49,  NULL, 0,  -0.812, 0.626, 1.507,   NULL, NULL),    
    (50,  NULL, 0,  -1.785, 0.626, 0.03,    NULL, NULL),
    (51,  NULL, 0,  -0.836, 0.626, -1.585,  NULL, NULL),
    (52,  NULL, 0,  0.903,  0.626, -1.471,  NULL, NULL),
    (53,  NULL, 0,  1.763,  0.626, -0.027,  NULL, NULL),
    (54,  NULL, 0,  0.838,  0.626, 1.609,   NULL, NULL);

-- =========================
-- Insertar datos HEXAGON_CONECTIONS
-- =========================
INSERT INTO hexagon_conections (id, from_hexagon_id, to_town_id)
VALUES
    -- Hexagon 1 connections
    (1, 1, 1),
    (2, 1, 2),
    (3, 1, 3),
    (4, 1, 30),
    (5, 1, 31),
    (6, 1, 32),
    -- Hexagon 2 connections
    (7, 2, 3),
    (8, 2, 4),
    (9, 2, 5),
    (10, 2, 32),
    (11, 2, 33),
    (12, 2, 34),
    -- Hexagon 3 connections
    (13, 3, 5),
    (14, 3, 6),
    (15, 3, 7),
    (16, 3, 8),
    (17, 3, 34),
    (18, 3, 35),
    -- Hexagon 4 connections
    (19, 4, 8),
    (20, 4, 9),
    (21, 4, 10),
    (22, 4, 35),
    (23, 4, 36),
    (24, 4, 37),
    -- Hexagon 5 connections
    (25, 5, 10),
    (26, 5, 11),
    (27, 5, 12),
    (28, 5, 13),
    (29, 5, 37),
    (30, 5, 38),
    -- Hexagon 6 connections
    (31, 6, 13),
    (32, 6, 14),
    (33, 6, 15),
    (34, 6, 38),
    (35, 6, 39),
    (36, 6, 40),
    -- Hexagon 7 connections
    (37, 7, 15),
    (38, 7, 16),
    (39, 7, 17),
    (40, 7, 18),
    (41, 7, 40),
    (42, 7, 41),
    -- Hexagon 8 connections
    (43, 8, 18),
    (44, 8, 19),
    (45, 8, 20),
    (46, 8, 41),
    (47, 8, 42),
    (48, 8, 43),
    -- Hexagon 9 connections
    (49, 9, 20),
    (50, 9, 21),
    (51, 9, 22),
    (52, 9, 23),
    (53, 9, 43),
    (54, 9, 44),
    -- Hexagon 10 connections
    (55, 10, 23),
    (56, 10, 24),
    (57, 10, 25),
    (58, 10, 44),
    (59, 10, 45),
    (60, 10, 46),
    -- Hexagon 11 connections
    (61, 11, 25),
    (62, 11, 26),
    (63, 11, 27),
    (64, 11, 28),
    (65, 11, 46),
    (66, 11, 47),
    -- Hexagon 12 connections
    (67, 12, 28),
    (68, 12, 29),
    (69, 12, 30),
    (70, 12, 31),
    (71, 12, 47),
    (72, 12, 48),
    -- Hexagon 13 connections
    (73, 13, 31),
    (74, 13, 32),
    (75, 13, 33),
    (76, 13, 48),
    (77, 13, 49),
    (78, 13, 50),
    -- Hexagon 14 connections
    (79, 14, 33),
    (80, 14, 34),
    (81, 14, 35),
    (82, 14, 36),
    (83, 14, 50),
    (84, 14, 51),
    -- Hexagon 15 connections
    (85, 15, 36),
    (86, 15, 37),
    (87, 15, 38),
    (88, 15, 39),
    (89, 15, 51),
    (90, 15, 52),
    -- Hexagon 16 connections
    (91, 16, 39),
    (92, 16, 40),
    (93, 16, 41),
    (94, 16, 42),
    (95, 16, 52),
    (96, 16, 53),
    -- Hexagon 17 connections
    (97, 17, 42),
    (98, 17, 43),
    (99, 17, 44),
    (100, 17, 45),
    (101, 17, 53),
    (102, 17, 54),
    -- Hexagon 18 connections
    (103, 18, 45),
    (104, 18, 46),
    (105, 18, 47),
    (106, 18, 48),
    (107, 18, 49),
    (108, 18, 54),
    -- Hexagon 19 connections
    (109, 19, 49),
    (110, 19, 50),
    (111, 19, 51),
    (112, 19, 52),
    (113, 19, 53),
    (114, 19, 54);

-- =========================
-- Insertar datos TOWN_CONECTIONS
-- =========================
INSERT INTO town_conections (id, from_town_id, to_town_id, player_id, pos_x, pos_y, pos_z, rot_x, rot_y, rot_z)
VALUES
    (1,   1,  2,  null, -6.566, 0.457, 3.805,   3.142,  -0.53,  0     ),
    (2,   1,  30, null, -5.287, 0.457, 4.524,   0,      -1.56,  -3.142),
    (3,   2,  1,  null, -6.566, 0.457, 3.805,   3.142,  -0.53,  0     ),
    (4,   2,  3,  null, -6.597, 0.457, 2.249,   -3.142, 0.509,  0     ),
    (5,   3,  2,  null, -6.597, 0.457, 2.249,   -3.142, 0.509,  0     ),
    (6,   3,  4,  null, -6.575, 0.457, 0.724,   3.142,  -0.53,  0     ),
    (7,   3,  32, null, -5.398, 0.457, 1.493,   0,      -1.56,  -3.142),
    (8,   4,  3,  null, -6.575, 0.457, 0.724,   3.142,  -0.53,  0     ),
    (9,   4,  5,  null, -6.594, 0.457, -0.789,  -3.142, 0.509,  0     ),
    (10,  5,  4,  null, -6.594, 0.457, -0.789,  -3.142, 0.509,  0     ),
    (11,  5,  34, null, -5.284, 0.457, -1.518,  0,      -1.56,  -3.142),
    (12,  6,  5,  null, -6.627, 0.457, -2.328,  3.142,  -0.53,  0     ),
    (13,  5,  6,  null, -6.627, 0.457, -2.328,  3.142,  -0.53,  0     ),
    (14,  6,  7,  null, -6.61,  0.457, -3.82,   -3.142, 0.509,  0     ),
    (15,  7,  6,  null, -6.61,  0.457, -3.82,   -3.142, 0.509,  0     ),
    (16,  7,  8,  null, -5.185, 0.457, -4.628,  0,      -1.56,  -3.142),
    (17,  8,  7,  null, -5.185, 0.457, -4.628,  0,      -1.56,  -3.142),
    (18,  8,  9,  null, -3.964, 0.457, -5.354,  -3.142, 0.509,  0     ),
    (19,  8,  35, null, -3.953, 0.457, -3.828,  3.142,  -0.53,  0     ),
    (20,  9,  8,  null, -3.964, 0.457, -5.354,  -3.142, 0.509,  0     ),
    (21,  9,  10, null, -2.695, 0.457, -6.106,  0,      -1.56,  -3.142),
    (22,  10, 9,  null, -2.695, 0.457, -6.106,  0,      -1.56,  -3.142),
    (23,  10, 37, null, -1.315, 0.457, -5.311,  3.142,  -0.53,  0     ),
    (24,  10, 11, null, -1.324, 0.457, -6.873,  -3.142, 0.509,  0     ),
    (25,  11, 10, null, -1.324, 0.457, -6.873,  -3.142, 0.509,  0     ),
    (26,  11, 12, null, -0.027, 0.457, -7.609,  0,      -1.56,  -3.142),
    (27,  12, 11, null, -0.027, 0.457, -7.609,  0,      -1.56,  -3.142),
    (28,  12, 13, null, 1.304,  0.457, -6.824,  3.142,  -0.53,  0     ),
    (29,  13, 12, null, 1.304,  0.457, -6.824,  3.142,  -0.53,  0     ),
    (30,  13, 14, null, 2.624,  0.457, -6.082,  0,      -1.56,  -3.142),
    (31,  13, 38, null, 1.342,  0.457, -5.337,  -3.142, 0.509,  0     ),
    (32,  14, 13, null, 2.624,  0.457, -6.082,  0,      -1.56,  -3.142),
    (33,  14, 15, null, 3.942,  0.457, -5.304,  3.142,  -0.53,  0     ),
    (34,  15, 14, null, 3.942,  0.457, -5.304,  3.142,  -0.53,  0     ),
    (35,  15, 40, null, 3.938,  0.457, -3.841,  -3.142, 0.509,  0     ),
    (36,  15, 16, null, 5.276,  0.457, -4.554,  0,      -1.56,  -3.142),
    (37,  16, 15, null, 5.276,  0.457, -4.554,  0,      -1.56,  -3.142),
    (38,  16, 17, null, 6.613,  0.457, -3.748,  3.142,  -0.53,  0     ),
    (39,  17, 16, null, 6.613,  0.457, -3.748,  3.142,  -0.53,  0     ),
    (40,  17, 18, null, 6.604,  0.457, -2.306,  -3.142, 0.509,  0     ),
    (41,  18, 17, null, 6.604,  0.457, -2.306,  -3.142, 0.509,  0     ),
    (42,  18, 19, null, 6.571,  0.457, -0.768,  3.142,  -0.53,  0     ),
    (43,  18, 41, null, 5.283,  0.457, -1.565,  0,      -1.56,  -3.142),
    (44,  19, 18, null, 6.571,  0.457, -0.768,  3.142,  -0.53,  0     ),
    (45,  19, 20, null, 6.614,  0.457, 0.74,    -3.142, 0.509,  0     ),
    (46,  20, 19, null, 6.614,  0.457, 0.74,    -3.142, 0.509,  0     ),
    (47,  20, 21, null, 6.584,  0.457, 2.243,   3.142,  -0.53,  0     ),
    (48,  20, 43, null, 5.3,    0.457, 1.503,   0,      -1.56,  -3.142),
    (49,  21, 20, null, 6.584,  0.457, 2.243,   3.142,  -0.53,  0     ),
    (50,  21, 22, null, 6.59,   0.457, 3.784,   -3.142, 0.509,  0     ),
    (51,  22, 21, null, 6.59,   0.457, 3.784,   -3.142, 0.509,  0     ),
    (52,  22, 23, null, 5.226,  0.457, 4.537,   0,      -1.56,  -3.142),
    (53,  23, 22, null, 5.226,  0.457, 4.537,   0,      -1.56,  -3.142),
    (54,  23, 24, null, 3.949,  0.457, 5.303,   -3.142, 0.509,  0     ),
    (55,  23, 44, null, 3.925,  0.457, 3.751,   3.142,  -0.53,  0     ),
    (56,  24, 23, null, 3.949,  0.457, 5.303,   -3.142, 0.509,  0     ),
    (57,  24, 25, null, 2.638,  0.457, 6.122,   0,      -1.56,  -3.142),
    (58,  25, 24, null, 2.638,  0.457, 6.122,   0,      -1.56,  -3.142),
    (59,  25, 26, null, 1.33,   0.457, 6.816,   -3.142, 0.509,  0     ),
    (60,  25, 46, null, 1.338,  0.457, 5.41,    3.142,  -0.53,  0     ),
    (61,  26, 25, null, 1.33,   0.457, 6.816,   -3.142, 0.509,  0     ),
    (62,  26, 27, null, -0.026, 0.457, 7.555,   0,      -1.56,  -3.142),
    (63,  27, 26, null, -0.026, 0.457, 7.555,   0,      -1.56,  -3.142),
    (64,  27, 28, null, -1.336, 0.457, 6.815,   3.142,  -0.53,  0     ),
    (65,  28, 27, null, -1.336, 0.457, 6.815,   3.142,  -0.53,  0     ),
    (66,  28, 29, null, -2.677, 0.457, 6.027,   0,      -1.56,  -3.142),
    (67,  28, 47, null, -1.368, 0.457, 5.371,   -3.142, 0.509,  0     ),
    (68,  29, 28, null, -2.677, 0.457, 6.027,   0,      -1.56,  -3.142),
    (69,  29, 30, null, -3.983, 0.457, 5.293,   3.142,  -0.53,  0     ),
    (70,  30, 29, null, -3.983, 0.457, 5.293,   3.142,  -0.53,  0     ),
    (71,  30, 1,  null, -5.287, 0.457, 4.524,   0,      -1.56,  -3.142),
    (72,  30, 31, null, -3.993, 0.457, 3.859,   -3.142, 0.509,  0     ),
    (73,  31, 30, null, -3.993, 0.457, 3.859,   -3.142, 0.509,  0     ),
    (74,  31, 32, null, -3.856, 0.457, 2.418,   3.142,  -0.53,  0     ),
    (75,  31, 48, null, -2.692, 0.457, 3.052,   0,      -1.56,  -3.142),
    (76,  32, 31, null, -3.856, 0.457, 2.418,   3.142,  -0.53,  0     ),
    (77,  32, 33, null, -4.011, 0.457, 0.826,   -3.142, 0.509,  0     ),
    (78,  32, 3,  null, -5.398, 0.457, 1.493,   0,      -1.56,  -3.142),
    (79,  33, 32, null, -4.011, 0.457, 0.826,   -3.142, 0.509,  0     ),
    (80,  33, 34, null, -3.931, 0.457, -0.775,  3.142,  -0.53,  0     ),
    (81,  33, 50, null, -2.673, 0.457, -0.014,  0,      -1.56,  -3.142),
    (82,  34, 33, null, -3.931, 0.457, -0.775,  3.142,  -0.53,  0     ),
    (83,  34, 35, null, -3.973, 0.457, -2.301,  -3.142, 0.509,  0     ),
    (84,  34, 5,  null, -5.284, 0.457, -1.518,  0,      -1.56,  -3.142),
    (85,  35, 34, null, -3.973, 0.457, -2.301,  -3.142, 0.509,  0     ),
    (86,  35, 36, null, -2.628, 0.457, -3.064,  0,      -1.56,  -3.142),
    (87,  35, 8,  null, -3.953, 0.457, -3.828,  3.142,  -0.53,  0     ),
    (88,  36, 35, null, -2.628, 0.457, -3.064,  0,      -1.56,  -3.142),
    (89,  36, 37, null, -1.326, 0.457, -3.834,  -3.142, 0.509,  0     ),
    (90,  36, 51, null, -1.302, 0.457, -2.3,    3.142,  -0.53,  0     ),
    (91,  37, 36, null, -1.326, 0.457, -3.834,  -3.142, 0.509,  0     ),
    (92,  37, 38, null, 0.011,  0.457, -4.547,  0,      -1.56,  -3.142),
    (93,  37, 10, null, -1.315, 0.457, -5.311,  3.142,  -0.53,  0     ),
    (94,  38, 37, null, 0.011,  0.457, -4.547,  0,      -1.56,  -3.142),
    (95,  38, 39, null, 1.364,  0.457, -3.768,  3.142,  -0.53,  0     ),
    (96,  38, 13, null, 1.342,  0.457, -5.337,  -3.142, 0.509,  0     ),
    (97,  39, 38, null, 1.364,  0.457, -3.768,  3.142,  -0.53,  0     ),
    (98,  39, 40, null, 2.713,  0.457, -3.045,  0,      -1.56,  -3.142),
    (99,  39, 52, null, 1.341,  0.457, -2.334,  -3.142, 0.509,  0     ),
    (100, 40, 39, null, 2.713,  0.457, -3.045,  0,      -1.56,  -3.142),
    (101, 40, 41, null, 3.927,  0.457, -2.321,  3.142,  -0.53,  0     ),
    (102, 40, 15, null, 3.938,  0.457, -3.841,  -3.142, 0.509,  0     ),
    (103, 41, 40, null, 3.927,  0.457, -2.321,  3.142,  -0.53,  0     ),
    (104, 41, 42, null, 3.977,  0.457, -0.779,  -3.142, 0.509,  0     ),
    (105, 41, 18, null, 5.283,  0.457, -1.565,  0,      -1.56,  -3.142),
    (106, 42, 41, null, 3.977,  0.457, -0.779,  -3.142, 0.509,  0     ),
    (107, 42, 43, null, 3.987,  0.457, 0.747,   3.142,  -0.53,  0     ),
    (108, 42, 53, null, 2.648,  0.457, -0.025,  0,      -1.56,  -3.142),
    (109, 43, 42, null, 3.987,  0.457, 0.747,   3.142,  -0.53,  0     ),
    (110, 43, 44, null, 3.939,  0.457, 2.257,   -3.142, 0.509,  0     ),
    (111, 43, 20, null, 5.3,    0.457, 1.503,   0,      -1.56,  -3.142),
    (112, 44, 43, null, 3.939,  0.457, 2.257,   -3.142, 0.509,  0     ),
    (113, 44, 45, null, 2.547,  0.457, 2.994,   0,      -1.56,  -3.142),
    (114, 44, 23, null, 3.925,  0.457, 3.751,   3.142,  -0.53,  0     ),
    (115, 45, 44, null, 2.547,  0.457, 2.994,   0,      -1.56,  -3.142),
    (116, 45, 46, null, 1.312,  0.457, 3.783,   -3.142, 0.509,  0     ),
    (117, 45, 54, null, 1.3,    0.457, 2.239,    3.142,  -0.53,  0     ),
    (118, 46, 45, null, 1.312,  0.457, 3.783,   -3.142, 0.509,  0     ),
    (119, 46, 47, null, -0.041, 0.457, 4.579,   0,      -1.56,  -3.142),
    (120, 46, 25, null, 1.338,  0.457, 5.41,    3.142,  -0.53,  0     ),
    (121, 47, 46, null, -0.041, 0.457, 4.579,   0,      -1.56,  -3.142),
    (122, 47, 48, null, -1.286, 0.457, 3.898,   3.142,  -0.53,  0     ),
    (123, 47, 28, null, -1.368, 0.457, 5.371,   -3.142, 0.509,  0     ),
    (124, 48, 47, null, -1.286, 0.457, 3.898,   3.142,  -0.53,  0     ),
    (125, 48, 49, null, -1.366, 0.457, 2.332,   -3.142, 0.509,  0     ),
    (126, 48, 31, null, -2.692, 0.457, 3.052,   0,      -1.56,  -3.142),
    (127, 49, 48, null, -1.366, 0.457, 2.332,   -3.142, 0.509,  0     ),
    (128, 49, 50, null, -1.373, 0.457, 0.699,   3.142,  -0.53,  0     ),
    (129, 49, 54, null, 0.019,  0.457, 1.537,   0,      -1.56,  -3.142),
    (130, 50, 49, null, -1.373, 0.457, 0.699,   3.142,  -0.53,  0     ),
    (131, 50, 51, null, -1.319, 0.457, -0.772,  -3.142, 0.509,  0     ),
    (132, 50, 33, null, -2.673, 0.457, -0.014,  0,      -1.56,  -3.142),
    (133, 51, 50, null, -1.319, 0.457, -0.772,  -3.142, 0.509,  0     ),
    (134, 51, 52, null, -0.003, 0.457, -1.552,  0,      -1.56,  -3.142),
    (135, 51, 36, null, -1.302, 0.457, -2.3,    3.142,  -0.53,  0     ),
    (136, 52, 51, null, -0.003, 0.457, -1.552,  0,      -1.56,  -3.142),
    (137, 52, 53, null, 1.295,  0.457, -0.804,  3.142,  -0.53,  0     ),
    (138, 52, 39, null, 1.341,  0.457, -2.334,  -3.142, 0.509,  0     ),
    (139, 53, 52, null, 1.295,  0.457, -0.804,  3.142,  -0.53,  0     ),
    (140, 53, 54, null, 1.301,  0.457, 0.737,   -3.142, 0.509,  0     ),
    (141, 53, 42, null, 2.648,  0.457, -0.025,  0,      -1.56,  -3.142),
    (142, 54, 53, null, 1.301,  0.457, 0.737,   -3.142, 0.509,  0     ),
    (143, 54, 49, null, 0.019,  0.457, 1.537,   0,      -1.56,  -3.142),
    (144, 54, 45, null, 1.3,    0.457, 2.239,   3.142,  -0.53,  0     );
-- =========================
