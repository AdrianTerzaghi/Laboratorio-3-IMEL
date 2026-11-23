<?php
// API del Nivel 3 para pc_local.py.
// cloud_url = https://utecnologica.org/estudiantes/imel/g07/nube.php
// pc_local.py agrega /api/telemetry, /api/config/pending y /api/config/{id}/ack.
require __DIR__ . '/lib.php';

$ruta = rtrim($_SERVER['PATH_INFO'] ?? '', '/');
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($ruta === '/api/health') {
        db()->query('SELECT 1');
        json_out(['ok' => true, 'ts' => microtime(true)]);
    }

    $clave = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($CFG['api_key'], $clave)) json_out(['error' => 'Clave API inválida'], 401);

    $cuerpo = json_decode(file_get_contents('php://input') ?: 'null', true);
    $ahora = microtime(true);

    // ---- Lotes de telemetría ----
    if ($ruta === '/api/telemetry' && $metodo === 'POST') {
        $filas = $cuerpo['rows'] ?? null;
        if (!is_array($filas) || count($filas) < 1 || count($filas) > 1000)
            json_out(['error' => 'Enviar de 1 a 1000 filas en "rows"'], 422);
        $ins = db()->prepare('INSERT IGNORE INTO telemetria
            (sample_id, ts, received_ts, seq, tc, ta, hum, ldr, dist, iv0, iv1, iv2, iv3, iv4)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $nuevas = 0; $rechazadas = [];
        foreach ($filas as $i => $f) {
            $motivo = validar_fila($f, $ahora);
            if ($motivo) { $rechazadas[] = ['fila' => $i, 'motivo' => $motivo]; continue; }
            $iv = is_array($f['iv'] ?? null) ? array_values($f['iv']) : [null, null, null, null, null];
            $vals = array_map(fn($k) => isset($f[$k]) ? (float)$f[$k] : null, CANALES);
            // pc_local.py no manda identificador: se usa una huella del contenido para descartar reenvíos.
            $id = $f['sample_id'] ?? sha1(json_encode([sprintf('%.6f', $f['ts']), $f['seq'] ?? null, $vals, $iv]));
            $ins->execute(array_merge([$id, (float)$f['ts'], $ahora, $f['seq'] ?? null], $vals, $iv));
            $nuevas += $ins->rowCount();
        }
        meta_set('last_upload', (string)$ahora);
        json_out(['recibidas' => count($filas), 'nuevas' => $nuevas, 'rechazadas' => $rechazadas]);
    }

    // ---- Polling de órdenes: una por vez ----
    if ($ruta === '/api/config/pending' && $metodo === 'GET') {
        $pdo = db();
        vencer_ordenes($ahora);
        meta_set('last_poll', (string)$ahora);
        $enviada = $pdo->query("SELECT * FROM ordenes WHERE estado = 'sent' ORDER BY id LIMIT 1")->fetch();
        if ($enviada) {
            if ($enviada['sent_ts'] > $ahora - REINTENTO_S) json_out(['commands' => []]);
            $orden = $enviada;                 // reintento del mismo id
        } else {
            $orden = $pdo->query("SELECT * FROM ordenes WHERE estado = 'pending' ORDER BY id LIMIT 1")->fetch();
            if (!$orden) json_out(['commands' => []]);
        }
        $pdo->prepare("UPDATE ordenes SET estado = 'sent', sent_ts = ?, intentos = intentos + 1 WHERE id = ?")
            ->execute([$ahora, $orden['id']]);
        json_out(['commands' => [['id' => (int)$orden['id'], 'ch' => (int)$orden['ch'], 'interval_ms' => (int)$orden['interval_ms']]]]);
    }

    // ---- Confirmación del gateway ----
    if (preg_match('#^/api/config/(\d+)/ack$#', $ruta, $m) && $metodo === 'POST') {
        $q = db()->prepare('SELECT * FROM ordenes WHERE id = ?');
        $q->execute([(int)$m[1]]);
        $orden = $q->fetch();
        if (!$orden) json_out(['error' => 'Orden inexistente'], 404);
        $ch = $cuerpo['ch'] ?? $cuerpo['canal'] ?? null;
        if ((int)$ch !== (int)$orden['ch'] || (int)($cuerpo['interval_ms'] ?? -1) !== (int)$orden['interval_ms'])
            json_out(['error' => 'Canal o intervalo distintos de la orden'], 422);
        if ($orden['estado'] === 'applied') json_out(['ok' => true, 'duplicado' => true]);
        // Se acepta también una confirmación tardía de una orden vencida: el Arduino sí la aplicó.
        db()->prepare("UPDATE ordenes SET estado = 'applied', ack_ts = ?, applied_ts = ? WHERE id = ?")
            ->execute([$ahora, (float)($cuerpo['applied_ts'] ?? $ahora), $orden['id']]);
        meta_set('last_ack', (string)$ahora);
        json_out(['ok' => true, 'latencia_s' => round($ahora - $orden['created_ts'], 1)]);
    }

    // ---- Estado general (diagnóstico) ----
    if ($ruta === '/api/status' && $metodo === 'GET') {
        json_out(['ultima' => ultima_muestra(),
                  'filas' => (int)db()->query('SELECT COUNT(*) FROM telemetria')->fetchColumn(),
                  'ordenes' => ultimas_ordenes(10), 'meta' => meta_all()]);
    }

    json_out(['error' => 'Ruta no encontrada: ' . $ruta], 404);
} catch (Throwable $e) {
    json_out(['error' => 'Error del servidor: ' . $e->getMessage()], 500);
}

function validar_fila($f, float $ahora): ?string {
    if (!is_array($f)) return 'no es un objeto';
    $ts = $f['ts'] ?? null;
    if (!is_numeric($ts) || $ts <= 0 || $ts > $ahora + 300) return 'ts inválido';
    if (isset($f['seq']) && (!is_int($f['seq']) || $f['seq'] < 0 || $f['seq'] > 255)) return 'seq inválido';
    foreach (RANGO as $k => [$min, $max]) {
        $v = $f[$k] ?? null;
        if ($v !== null && (!is_numeric($v) || $v < $min || $v > $max)) return "$k fuera de rango";
    }
    $iv = $f['iv'] ?? null;
    if ($iv !== null) {
        if (!is_array($iv) || count($iv) !== 5) return 'iv debe tener 5 intervalos';
        foreach (array_values($iv) as $v) if (!is_int($v) || $v < 1 || $v > 65535) return 'iv fuera de rango';
    }
    return null;
}