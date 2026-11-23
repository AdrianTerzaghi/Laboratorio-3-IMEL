<?php
// Dashboard del Nivel 3: https://utecnologica.org/estudiantes/imel/g07/panel.php
require __DIR__ . '/lib.php';
session_start();
$accion = $_GET['accion'] ?? '';

// ---- Login ----
if ($accion === 'salir') { session_destroy(); header('Location: panel.php'); exit; }
if (empty($_SESSION['ok'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (hash_equals($CFG['panel_user'], $_POST['u'] ?? '') && hash_equals($CFG['panel_pass'], $_POST['p'] ?? '')) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            $_SESSION['token'] = bin2hex(random_bytes(16));
            header('Location: panel.php'); exit;
        }
        $error = 'Usuario o contraseña incorrectos';
    }
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Ingreso · Grupo 07</title><style>body{font-family:Arial,sans-serif;background:#f3f6f8;display:grid;place-items:center;height:100vh;margin:0}
    form{background:#fff;padding:28px;border-radius:10px;border:1px solid #dde5eb;width:280px}h1{font-size:18px;margin:0 0 16px;color:#142f43}
    input{width:100%;box-sizing:border-box;padding:10px;margin:6px 0 12px;border:1px solid #ccd9e1;border-radius:6px}
    button{width:100%;padding:11px;background:#168772;color:#fff;border:0;border-radius:6px;font-weight:bold;cursor:pointer}.e{color:#b00020;font-size:13px}</style></head>
    <body><form method="post"><h1>Instrumentación · Grupo 07</h1><label>Usuario<input name="u" autocomplete="username"></label>
    <label>Contraseña<input name="p" type="password" autocomplete="current-password"></label><button>Ingresar</button>
    <p class="e"><?= htmlspecialchars($error) ?></p></form></body></html><?php
    exit;
}

// ---- Datos para el dashboard (JSON) ----
try {
    if ($accion === 'datos' || $accion === 'csv') {
        $desde = (float)($_GET['desde'] ?? (time() - 3600));
        $hasta = (float)($_GET['hasta'] ?? (time() + 60));
        $filas = historico($desde, $hasta);
        if ($accion === 'csv') csv_out($filas);
        $total = count($filas);
        $paso = max(1, (int)ceil($total / 2000));          // reducción solo visual
        $serie = [];
        foreach ($filas as $i => $r) {
            if ($i % $paso && $i !== $total - 1) continue;
            $serie[] = array_merge(['ts' => (float)$r['ts']], array_combine(CANALES, array_map(fn($k) => $r[$k] === null ? null : (float)$r[$k], CANALES)));
        }
        $ultima = ultima_muestra();
        if ($ultima) foreach (CANALES as $k) $ultima['U_' . $k] = incertidumbre($k, $ultima[$k] === null ? null : (float)$ultima[$k]);
        vencer_ordenes(microtime(true));
        json_out(['ahora' => microtime(true), 'total' => $total, 'serie' => $serie, 'ultima' => $ultima,
                  'ordenes' => ultimas_ordenes(10), 'meta' => meta_all()]);
    }

    // ---- Nuevas órdenes ----
    if ($accion === 'orden' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['token'], $_SERVER['HTTP_X_TOKEN'] ?? '')) json_out(['error' => 'Sesión inválida'], 403);
        $cuerpo = json_decode(file_get_contents('php://input'), true) ?: [];
        $ordenes = array_map(fn($o) => [(int)($o['ch'] ?? -1), (int)($o['interval_ms'] ?? 0)], $cuerpo['ordenes'] ?? []);
        if (!$ordenes) json_out(['error' => 'No hay cambios para aplicar'], 422);
        json_out(['ids' => crear_ordenes($ordenes)]);
    }
} catch (InvalidArgumentException $e) {
    json_out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_out(['error' => 'Error del servidor: ' . $e->getMessage()], 500);
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard · Grupo 07</title>
<script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
<style>
:root{--azul:#142f43;--verde:#168772;--gris:#657683;--borde:#dde5eb}
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f3f6f8;color:#1d3445;font-size:14px}
header{background:var(--azul);color:#fff;padding:16px 4%;display:flex;justify-content:space-between;align-items:center}
header a{color:#a9c0d0;font-size:12px}main{max-width:1300px;margin:auto;padding:24px 4%}
h1{font-size:26px;margin:0 0 6px}h2{font-size:18px;margin:0 0 8px}.muted{color:var(--gris);font-size:12px}
.estado{font-size:12px;background:#fff;border:1px solid var(--borde);border-radius:8px;padding:8px 12px}
.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:18px 0}
.card{background:#fff;border:1px solid var(--borde);border-top:3px solid;border-radius:8px;padding:14px}
.card b{font-size:26px;display:block;margin:8px 0 4px}.card small{color:var(--gris);display:block;font-size:11px}
.filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0 12px}
select,input,button{font:inherit;padding:8px;border:1px solid #ccd9e1;border-radius:6px;background:#fff}
button{background:var(--verde);color:#fff;border:0;font-weight:bold;cursor:pointer}button.sec{background:#fff;color:#315367;border:1px solid #ccd9e1}
.graficos{display:grid;grid-template-columns:1fr 1fr;gap:14px}.g{background:#fff;border:1px solid var(--borde);border-radius:8px;height:290px}
.panel{background:#fff;border:1px solid var(--borde);border-radius:8px;padding:18px;margin-top:18px}
.ctrl{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:12px 0}.ctrl label{font-size:12px;font-weight:bold}
.ctrl input{width:100%;margin:6px 0 4px}table{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}
th,td{padding:8px;border-bottom:1px solid #e9eef2;text-align:left}th{background:#f3f6f8;color:#70808c;font-weight:normal}
#msg{margin-left:10px;font-size:13px}
@media(max-width:900px){.cards,.ctrl{grid-template-columns:repeat(2,1fr)}.graficos{grid-template-columns:1fr}}
</style></head><body>
<header><strong>INSTRUMENTACIÓN · GRUPO 07 · NIVEL 3</strong><a href="panel.php?accion=salir">Salir</a></header>
<main>
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
    <div><h1>Sistema de instrumentación IoT</h1><div class="muted">Cinco variables con banda de incertidumbre expandida ±U (k = 2) y configuración remota del muestreo.</div></div>
    <div class="estado" id="conexion">Cargando…</div>
  </div>
  <div class="cards" id="cards"></div>

  <h2>Histórico</h2>
  <div class="filtros">
    <select id="periodo"><option value="3600">Última hora</option><option value="86400">Últimas 24 h</option><option value="fechas">Elegir fechas</option></select>
    <input type="date" id="d1"> <input type="date" id="d2">
    <button class="sec" id="csv">Descargar CSV</button>
    <span class="muted" id="nota"></span>
  </div>
  <div class="graficos" id="graficos"></div>

  <div class="panel">
    <h2>Intervalos de muestreo</h2>
    <div class="muted">La orden se confirma cuando el Arduino informa el nuevo intervalo. Solo se envían los canales que cambiaste.</div>
    <div class="ctrl" id="ctrl"></div>
    <button id="aplicar">Aplicar configuración</button><span id="msg"></span>
    <div class="muted" id="ultimoack" style="margin-top:12px"></div>
    <table><thead><tr><th>ID</th><th>Canal</th><th>Pedido</th><th>Estado</th><th>Creada</th><th>Confirmada</th><th>Latencia</th></tr></thead><tbody id="ordenes"></tbody></table>
  </div>
  <p class="muted">Hora de Uruguay. Las marcas de tiempo son las de recepción en la PC local. La PC local sube el histórico cada 10 min.</p>
</main>
<script>
const TOKEN = <?= json_encode($_SESSION['token']) ?>;
const K = ['tc','ta','hum','ldr','dist'];
const NOM = <?= json_encode(NOMBRES, JSON_UNESCAPED_UNICODE) ?>;
const UNI = <?= json_encode(UNIDADES, JSON_UNESCAPED_UNICODE) ?>;
const MIN = <?= json_encode(MIN_MS) ?>;
const COL = ['#db7136','#167ab6','#168772','#8a65ba','#b8860b'];
const EST = {pending:'Pendiente', sent:'Enviada · esperando Arduino', applied:'Aplicada y confirmada', expired:'Vencida sin confirmar'};
const U = {tc:()=>2*Math.sqrt((2.25**2+3**2+2.5**2)/3), ta:()=>4/Math.sqrt(3), hum:()=>10/Math.sqrt(3),
           ldr:v=>2*Math.sqrt(0.01/12+(0.067*Math.abs(v))**2/3), dist:()=>2*Math.sqrt(0.09/3+0.01/12)};
const fmt = t => t ? new Date(t*1000).toLocaleString('es-UY',{timeZone:'America/Montevideo',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'}) : '—';
const hoy = new Date().toLocaleDateString('en-CA',{timeZone:'America/Montevideo'});
d1.value = d2.value = hoy;
let actuales = null;

document.getElementById('graficos').innerHTML = K.map(k=>`<div class="g" id="g_${k}"></div>`).join('');
document.getElementById('ctrl').innerHTML = K.map((k,i)=>`<div><label>${NOM[i]}<input type="number" id="ms_${k}" min="${MIN[i]}" max="60000" step="1"></label>
  <div class="muted">Mín. ${MIN[i]} · Máx. 60000 ms</div></div>`).join('');

function rango(){
  if (periodo.value !== 'fechas'){ const a = Date.now()/1000; return [a - +periodo.value, a + 60]; }
  const a = new Date(d1.value+'T00:00:00-03:00').getTime()/1000, b = new Date(d2.value+'T23:59:59-03:00').getTime()/1000;
  return [a, b];
}

async function cargar(){
  const [a,b] = rango();
  let d;
  try { d = await (await fetch(`panel.php?accion=datos&desde=${a}&hasta=${b}`)).json(); }
  catch(e){ conexion.textContent = 'Sin respuesta del servidor'; return; }
  if (d.error){ conexion.textContent = d.error; return; }
  const poll = +(d.meta.last_poll||0);
  conexion.innerHTML = (d.ahora - poll < 30 ? '● Nivel 2 conectado' : '○ Sin consultas recientes del Nivel 2') +
    `<br><span class="muted">Última subida: ${fmt(+d.meta.last_upload||0)}</span>`;
  const u = d.ultima;
  cards.innerHTML = K.map((k,i)=>{ const v = u ? u[k] : null;
    return `<div class="card" style="border-top-color:${COL[i]}"><small>${NOM[i]}</small><b>${v==null?'—':(+v).toFixed(1)} <span class="muted">${UNI[i]}</span></b>
      <small>${v==null?'Sin dato':'± '+U[k](+v).toFixed(2)+' '+UNI[i]+' (k = 2)'}</small><small>${fmt(u?+u.ts:0)}</small></div>`;}).join('');
  nota.textContent = `${d.total} paquetes en el período` + (d.total > 2000 ? ' (gráficas reducidas; el CSV tiene todos)' : '');
  if (u){ actuales = [u.iv0,u.iv1,u.iv2,u.iv3,u.iv4].map(Number);
    K.forEach((k,i)=>{ const el = document.getElementById('ms_'+k); if (document.activeElement !== el && !el.dataset.editado) el.value = actuales[i]; }); }
  ordenes.innerHTML = d.ordenes.map(o=>`<tr><td>${o.id}</td><td>${NOM[o.ch]}</td><td>${o.interval_ms} ms</td><td>${EST[o.estado]}</td>
    <td>${fmt(+o.created_ts)}</td><td>${fmt(+o.ack_ts)}</td><td>${o.ack_ts? (o.ack_ts-o.created_ts).toFixed(1)+' s':'—'}</td></tr>`).join('')
    || '<tr><td colspan="7" class="muted">Todavía no hay órdenes.</td></tr>';
  ultimoack.textContent = 'Última confirmación del Nivel 2: ' + fmt(+d.meta.last_ack||0);
  if (!window.Plotly){ nota.textContent += ' · No se pudo cargar la biblioteca de gráficos (revisar conexión a internet).'; return; }
  const x = d.serie.map(r=>new Date(r.ts*1000));
  K.forEach((k,i)=>{
    const y = d.serie.map(r=>r[k]), uu = y.map(v=>v==null?null:U[k](v));
    const lo = y.map((v,j)=>v==null?null:v-uu[j]), hi = y.map((v,j)=>v==null?null:v+uu[j]);
    const c = COL[i], rgb = [1,3,5].map(p=>parseInt(c.slice(p,p+2),16)).join(',');
    Plotly.react('g_'+k, [
      {x, y:lo, mode:'lines', line:{width:0}, hoverinfo:'skip', showlegend:false},
      {x, y:hi, mode:'lines', line:{width:0}, fill:'tonexty', fillcolor:`rgba(${rgb},0.18)`, name:'±U', hoverinfo:'skip'},
      {x, y, mode:'lines', line:{color:c,width:2}, name:NOM[i]}],
      {title:{text:`${NOM[i]} [${UNI[i]}]`,font:{size:13}}, margin:{l:50,r:12,t:36,b:36}, showlegend:false,
       xaxis:{type:'date'}, uirevision:k, paper_bgcolor:'#fff', plot_bgcolor:'#fff'}, {displaylogo:false, responsive:true});
  });
}

K.forEach(k=>document.getElementById('ms_'+k).addEventListener('input', e=>e.target.dataset.editado='1'));
aplicar.onclick = async ()=>{
  const lista = K.map((k,i)=>({ch:i, interval_ms:parseInt(document.getElementById('ms_'+k).value)}))
                 .filter(o=>!actuales || o.interval_ms !== actuales[o.ch]);
  const r = await (await fetch('panel.php?accion=orden',{method:'POST',headers:{'Content-Type':'application/json','X-Token':TOKEN},
                   body:JSON.stringify({ordenes:lista})})).json();
  msg.textContent = r.error ? r.error : `Órdenes ${r.ids.join(', ')} creadas. Esperando confirmación del Arduino.`;
  msg.style.color = r.error ? '#b00020' : '#168772';
  if (!r.error) K.forEach(k=>delete document.getElementById('ms_'+k).dataset.editado);
  cargar();
};
csv.onclick = ()=>{ const [a,b] = rango(); location.href = `panel.php?accion=csv&desde=${a}&hasta=${b}`; };
[periodo,d1,d2].forEach(e=>e.onchange = cargar);
cargar(); setInterval(cargar, 5000);
</script></body></html>