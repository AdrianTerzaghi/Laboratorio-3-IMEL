import json
import logging
import sqlite3
import sys
import threading
import time

import paho.mqtt.client as mqtt
import requests

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("nivel2")

RUTA_CFG = sys.argv[1] if len(sys.argv) > 1 else "config.json"
with open(RUTA_CFG, encoding="utf-8") as f:
    CFG = json.load(f)

MQTT_HOST = CFG.get("mqtt_host", "localhost")
MQTT_PORT = CFG.get("mqtt_port", 1883)
NUBE = CFG["cloud_url"].rstrip("/")
CABECERAS = {"X-API-Key": CFG["api_key"]}
UPLOAD_PERIODO_S = CFG.get("upload_period_s", 600)
POLL_PERIODO_S = CFG.get("poll_period_s", 5)
LOTE_MAX = 500
DB_LOCAL = CFG.get("db_path", "nivel2.db")

T_TEL, T_CFG, T_ACK = "lab3/telemetria", "lab3/config", "lab3/config/ack"

COMANDOS = {}

def db():
    con = sqlite3.connect(DB_LOCAL, timeout=10)
    con.row_factory = sqlite3.Row
    return con

def iniciar_db():
    con = db()
    con.executescript(
        """
        CREATE TABLE IF NOT EXISTS samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts REAL NOT NULL, seq INTEGER,
            tc REAL, ta REAL, hum REAL, ldr REAL, dist REAL,
            iv TEXT,
            sent INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS acks (
            cmd_id INTEGER PRIMARY KEY,
            ts REAL NOT NULL,
            ch INTEGER, interval_ms INTEGER,
            sent INTEGER NOT NULL DEFAULT 0
        );
        """
    )
    columnas = [r[1] for r in con.execute("PRAGMA table_info(samples)")]
    if "dist" not in columnas:
        con.execute("ALTER TABLE samples ADD COLUMN dist REAL")
    columnas_ack = [r[1] for r in con.execute("PRAGMA table_info(acks)")]
    for col in ("ch", "interval_ms"):
        if col not in columnas_ack:
            con.execute(f"ALTER TABLE acks ADD COLUMN {col} INTEGER")
    con.commit()
    con.close()

def al_mensaje(cliente, _userdata, msg):
    try:
        datos = json.loads(msg.payload)
    except ValueError:
        log.warning("Mensaje no JSON en %s", msg.topic)
        return

    if msg.topic == T_TEL:
        con = db()
        con.execute(
            "INSERT INTO samples(ts, seq, tc, ta, hum, ldr, dist, iv) VALUES (?,?,?,?,?,?,?,?)",
            (time.time(), datos.get("seq"), datos.get("tc"), datos.get("ta"), datos.get("hum"),
             datos.get("ldr"), datos.get("dist"), json.dumps(datos.get("iv"))),
        )
        con.commit()
        con.close()
    elif msg.topic == T_ACK and datos.get("applied"):
        ch, ms = datos.get("ch"), datos.get("interval_ms")
        if ch is None and datos.get("id") in COMANDOS:
            ch, ms = COMANDOS[datos["id"]]
        datos["ch"], datos["interval_ms"] = ch, ms
        con = db()
        con.execute("INSERT OR REPLACE INTO acks(cmd_id, ts, ch, interval_ms, sent) VALUES (?,?,?,?,0)",
                    (datos["id"], time.time(), datos.get("ch"), datos.get("interval_ms")))
        con.commit()
        con.close()
        log.info("Confirmacion del gateway para el comando %s", datos["id"])
        enviar_acks()

def al_conectar(cliente, *args):
    cliente.subscribe([(T_TEL, 0), (T_ACK, 1)])
    log.info("Conectado a Mosquitto en %s:%s", MQTT_HOST, MQTT_PORT)

def crear_cliente():
    try:
        c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="nivel2-pc")
    except AttributeError:
        c = mqtt.Client(client_id="nivel2-pc")
    c.on_connect = al_conectar
    c.on_message = al_mensaje
    return c

def subir_telemetria():
    con = db()
    # Solo se suben las muestras que ya estaban guardadas al empezar el ciclo;
    # las que llegan mientras se sube quedan para el ciclo siguiente.
    tope = con.execute("SELECT COALESCE(MAX(id), 0) FROM samples").fetchone()[0]
    while True:
        filas = con.execute("SELECT * FROM samples WHERE sent=0 AND id<=? ORDER BY id LIMIT ?",
                            (tope, LOTE_MAX)).fetchall()
        if not filas:
            break
        cuerpo = {"rows": [
            {"ts": f["ts"], "seq": f["seq"], "tc": f["tc"], "ta": f["ta"], "hum": f["hum"],
             "ldr": f["ldr"], "dist": f["dist"], "iv": json.loads(f["iv"]) if f["iv"] else None}
            for f in filas
        ]}
        try:
            r = requests.post(f"{NUBE}/api/telemetry", json=cuerpo, headers=CABECERAS, timeout=15)
            if r.status_code >= 400:
                log.warning("La nube rechazo la telemetria (%s): %s", r.status_code, r.text[:400])
                break
        except requests.RequestException as e:
            log.warning("No se pudo subir telemetria (se reintenta en el proximo ciclo): %s", e)
            break
        ids = [f["id"] for f in filas]
        con.executemany("UPDATE samples SET sent=1 WHERE id=?", [(i,) for i in ids])
        con.commit()
        log.info("Subidas %d muestras a la nube", len(ids))
    con.close()

def enviar_acks():
    con = db()
    for a in con.execute("SELECT * FROM acks WHERE sent=0").fetchall():
        ch, ms = a["ch"], a["interval_ms"]
        if ch is None and a["cmd_id"] in COMANDOS:
            ch, ms = COMANDOS[a["cmd_id"]]
        if ch is None:
            log.warning("Confirmacion del comando %s sin canal (version anterior): se descarta", a["cmd_id"])
            con.execute("UPDATE acks SET sent=2 WHERE cmd_id=?", (a["cmd_id"],))
            con.commit()
            continue
        cuerpo = {"id": a["cmd_id"], "ch": ch, "canal": ch, "interval_ms": ms,
                  "applied": True, "applied_ts": a["ts"], "ts": a["ts"]}
        try:
            r = requests.post(f"{NUBE}/api/config/{a['cmd_id']}/ack", json=cuerpo,
                              headers=CABECERAS, timeout=10)
            if r.status_code >= 400:
                log.warning("La nube rechazo la confirmacion del comando %s (%s): %s",
                            a["cmd_id"], r.status_code, r.text[:400])
                continue
            con.execute("UPDATE acks SET sent=1 WHERE cmd_id=?", (a["cmd_id"],))
            con.commit()
            log.info("Confirmacion del comando %s enviada a la nube", a["cmd_id"])
        except requests.RequestException as e:
            log.warning("No se pudo confirmar el comando %s a la nube: %s", a["cmd_id"], e)
    con.close()

def ciclo_subida():
    while True:
        subir_telemetria()
        enviar_acks()
        time.sleep(UPLOAD_PERIODO_S)

def ciclo_polling(cliente):
    while True:
        try:
            r = requests.get(f"{NUBE}/api/config/pending", headers=CABECERAS, timeout=10)
            r.raise_for_status()
            for cmd in r.json().get("commands", []):
                COMANDOS[cmd["id"]] = (cmd["ch"], cmd["interval_ms"])
                cliente.publish(T_CFG, json.dumps(
                    {"id": cmd["id"], "ch": cmd["ch"], "interval_ms": cmd["interval_ms"]}), qos=1)
                log.info("Comando %s reenviado al gateway: canal %s -> %s ms",
                         cmd["id"], cmd["ch"], cmd["interval_ms"])
        except requests.RequestException as e:
            log.warning("Polling de configuracion fallo: %s", e)
        time.sleep(POLL_PERIODO_S)

def main():
    iniciar_db()
    cliente = crear_cliente()
    cliente.connect(MQTT_HOST, MQTT_PORT, keepalive=30)
    threading.Thread(target=ciclo_subida, daemon=True).start()
    threading.Thread(target=ciclo_polling, args=(cliente,), daemon=True).start()
    log.info("Nivel 2 en marcha: subida cada %s s, polling cada %s s", UPLOAD_PERIODO_S, POLL_PERIODO_S)
    cliente.loop_forever(retry_first_connection=True)

if __name__ == "__main__":
    main()
