<?php
// Funciones comunes de la API (nube.php) y del dashboard (panel.php).
date_default_timezone_set('America/Montevideo');
$CFG = require __DIR__ . '/config.php';

const CANALES = ['tc', 'ta', 'hum', 'ldr', 'dist'];
const NOMBRES = ['Temperatura de proceso', 'Temperatura ambiente', 'Humedad relativa', 'Iluminancia', 'Distancia'];
const UNIDADES = ['°C', '°C', '% HR', 'lx', 'cm'];
const MIN_MS = [250, 2000, 2000, 50, 100];
const MAX_MS = 60000;
const RANGO = ['tc' => [-270, 1024], 'ta' => [-50, 100], 'hum' => [0, 100], 'ldr' => [0, 100000], 'dist' => [0, 500]];
const REINTENTO_S = 20;   // se vuelve a ofrecer una orden sin confirmar
const VENCE_S = 240;      // una orden sin confirmar vence

function db(): PDO {
    static $pdo = null;
    global $CFG;
    if ($pdo) return $pdo;
    $error = null;
    foreach ($CFG['db_hosts'] as $host) {
        try {
            $pdo = new PDO("mysql:host=$host;dbname={$CFG['db_name']};charset=utf8mb4", $CFG['db_user'], $CFG['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            return $pdo;
        } catch (PDOException $e) { $error = $e; }
    }
    throw $error;
}

// Incertidumbre expandida (k = 2), igual que la Tabla 4.8 del informe.
function incertidumbre(string $k, $v) {
    if ($v === null) return null;
    switch ($k) {
        case 'tc':   return 2 * sqrt((2.25 ** 2 + 3.0 ** 2 + 2.5 ** 2) / 3);
        case 'ta':   return 2 * (2.0 / sqrt(3));
        case 'hum':  return 2 * (5.0 / sqrt(3));
        case 'ldr':  return 2 * sqrt(0.1 ** 2 / 12 + (0.067 * abs($v)) ** 2 / 3);
        case 'dist': return 2 * sqrt(0.3 ** 2 / 3 + 0.1 ** 2 / 12);
    }
    return null;
}

function meta_set(string $clave, string $valor): void {
    db()->prepare('INSERT INTO meta(clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute([$clave, $valor]);
}

function meta_all(): array {
    $out = [];
    foreach (db()->query('SELECT clave, valor FROM meta') as $r) $out[$r['clave']] = $r['valor'];
    return $out;
}

function vencer_ordenes(float $ahora): void {
    db()->prepare("UPDATE ordenes SET estado = 'expired' WHERE estado IN ('pending','sent') AND expires_ts <= ?")
        ->execute([$ahora]);
}

// Crea órdenes; $ordenes = [[ch, interval_ms], ...]. Devuelve los id creados.
function crear_ordenes(array $ordenes): array {
    $pdo = db();
    $ahora = microtime(true);
    vencer_ordenes($ahora);
    $vistos = [];
    foreach ($ordenes as [$ch, $ms]) {
        if (!is_int($ch) || $ch < 0 || $ch > 4) throw new InvalidArgumentException('Canal inválido');
        if (!is_int($ms) || $ms < MIN_MS[$ch] || $ms > MAX_MS)
            throw new InvalidArgumentException(NOMBRES[$ch] . ': el intervalo debe estar entre ' . MIN_MS[$ch] . ' y ' . MAX_MS . ' ms');
        if (isset($vistos[$ch])) throw new InvalidArgumentException('Canal repetido');
        $vistos[$ch] = true;
        $q = $pdo->prepare("SELECT 1 FROM ordenes WHERE ch = ? AND estado IN ('pending','sent')");
        $q->execute([$ch]);
        if ($q->fetch()) throw new InvalidArgumentException(NOMBRES[$ch] . ' ya tiene una orden en curso; esperá su confirmación');
    }
    $ids = [];
    $ins = $pdo->prepare("INSERT INTO ordenes(ch, interval_ms, estado, created_ts, expires_ts) VALUES (?, ?, 'pending', ?, ?)");
    foreach ($ordenes as [$ch, $ms]) {
        $ins->execute([$ch, $ms, $ahora, $ahora + VENCE_S]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    return $ids;
}

function ultimas_ordenes(int $n = 10): array {
    $q = db()->prepare('SELECT * FROM ordenes ORDER BY id DESC LIMIT ' . (int)$n);
    $q->execute();
    return $q->fetchAll();
}

function ultima_muestra(): ?array {
    $r = db()->query('SELECT * FROM telemetria ORDER BY ts DESC, id DESC LIMIT 1')->fetch();
    return $r ?: null;
}

function historico(float $desde, float $hasta, int $limite = 50000): array {
    $q = db()->prepare('SELECT * FROM telemetria WHERE ts >= ? AND ts <= ? ORDER BY ts, id LIMIT ' . (int)$limite);
    $q->execute([$desde, $hasta]);
    return $q->fetchAll();
}

function json_out($datos, int $codigo = 200): void {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function csv_out(array $filas): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historico_g07.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['fecha_hora_uy', 'ts_epoch', 'seq', 'tc_C', 'ta_C', 'hum_pct', 'ldr_lx', 'dist_cm',
                 'iv0_ms', 'iv1_ms', 'iv2_ms', 'iv3_ms', 'iv4_ms', 'U_tc', 'U_ta', 'U_hum', 'U_ldr', 'U_dist']);
    foreach ($filas as $r) {
        $u = array_map(fn($k) => ($x = incertidumbre($k, $r[$k] === null ? null : (float)$r[$k])) === null ? '' : round($x, 3), CANALES);
        fputcsv($f, array_merge([date('Y-m-d H:i:s', (int)$r['ts']), $r['ts'], $r['seq']],
            array_map(fn($k) => $r[$k], CANALES),
            [$r['iv0'], $r['iv1'], $r['iv2'], $r['iv3'], $r['iv4']], $u));
    }
    exit;
}