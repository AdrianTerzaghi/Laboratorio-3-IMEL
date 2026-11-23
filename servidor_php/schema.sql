-- Tablas del servidor (MySQL/MariaDB), reconstruidas a partir de las consultas de nube.php y lib.php.
-- Reemplazar por la exportación "solo estructura" de phpMyAdmin si difiere de la base en uso.
CREATE TABLE IF NOT EXISTS telemetria (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sample_id   VARCHAR(64) NOT NULL,
  ts          DOUBLE NOT NULL,
  received_ts DOUBLE NOT NULL,
  seq         SMALLINT UNSIGNED NULL,
  tc DOUBLE NULL, ta DOUBLE NULL, hum DOUBLE NULL, ldr DOUBLE NULL, dist DOUBLE NULL,
  iv0 INT NULL, iv1 INT NULL, iv2 INT NULL, iv3 INT NULL, iv4 INT NULL,
  UNIQUE KEY uq_sample (sample_id),
  KEY idx_ts (ts)
);
CREATE TABLE IF NOT EXISTS ordenes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ch          TINYINT UNSIGNED NOT NULL,
  interval_ms INT UNSIGNED NOT NULL,
  estado      ENUM('pending','sent','applied','expired') NOT NULL DEFAULT 'pending',
  created_ts  DOUBLE NOT NULL,
  expires_ts  DOUBLE NOT NULL,
  sent_ts     DOUBLE NULL,
  ack_ts      DOUBLE NULL,
  applied_ts  DOUBLE NULL,
  intentos    INT UNSIGNED NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS meta (
  clave VARCHAR(64) NOT NULL PRIMARY KEY,
  valor TEXT NOT NULL
);
