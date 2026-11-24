-- Estructura de la base de datos del nivel 3 (Grupo 07).
-- Exportada de phpMyAdmin (MariaDB 11.8) y limpiada: solo estructura, sin datos.
-- Para crearla: importar este archivo en phpMyAdmin dentro de una base vacia.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- Marcas de actividad (ultima subida, ultima consulta de la PC, ultima confirmacion)
CREATE TABLE `meta` (
  `clave` varchar(32) NOT NULL,
  `valor` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cola de ordenes de configuracion: pending -> sent -> applied / expired
CREATE TABLE `ordenes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ch` tinyint(3) UNSIGNED NOT NULL,
  `interval_ms` int(10) UNSIGNED NOT NULL,
  `estado` enum('pending','sent','applied','expired') NOT NULL DEFAULT 'pending',
  `created_ts` double NOT NULL,
  `sent_ts` double DEFAULT NULL,
  `ack_ts` double DEFAULT NULL,
  `applied_ts` double DEFAULT NULL,
  `expires_ts` double NOT NULL,
  `intentos` int(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historico de telemetria (sample_id unico: evita duplicados al reenviar lotes)
CREATE TABLE `telemetria` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sample_id` varchar(128) NOT NULL,
  `ts` double NOT NULL,
  `received_ts` double NOT NULL,
  `seq` tinyint(3) UNSIGNED DEFAULT NULL,
  `tc` double DEFAULT NULL,
  `ta` double DEFAULT NULL,
  `hum` double DEFAULT NULL,
  `ldr` double DEFAULT NULL,
  `dist` double DEFAULT NULL,
  `iv0` int(11) DEFAULT NULL,
  `iv1` int(11) DEFAULT NULL,
  `iv2` int(11) DEFAULT NULL,
  `iv3` int(11) DEFAULT NULL,
  `iv4` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sample_id` (`sample_id`),
  KEY `idx_ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
