# Laboratorio 03 – Sistema de instrumentación multivariable IoT/IIoT

Instrumentación y Medidas Eléctricas · Ingeniería en Mecatrónica · UTEC
Integrantes: Guido Andreoli, Lucas De León y Adrián Terzaghi (Grupo 07).

Instrumento distribuido en tres niveles que mide **cinco variables** con cuatro sensores, guarda el histórico, lo muestra en un dashboard web y permite **cambiar a distancia el intervalo de muestreo** de cada canal.

| Canal | Variable | Sensor |
|---|---|---|
| 0 | Temperatura de proceso (°C) | Termocupla K + MAX6675 |
| 1 | Temperatura ambiente (°C) | DHT11 |
| 2 | Humedad relativa (%HR) | DHT11 |
| 3 | Iluminancia (lx) | LDR en divisor resistivo |
| 4 | Distancia (cm) | HC-SR04 |

```
Nivel 1                 Nivel 2                                   Nivel 3
Arduino Uno --I2C--> NodeMCU ESP8266 --MQTT/Wi-Fi--> PC local --HTTPS--> Servidor + dashboard
(sensores)           (gateway)                       (Mosquitto +        (PHP + MySQL)
                                                      pc_local + SQLite)
```

- **Arduino Uno (esclavo I2C, dirección 0x08):** lee los sensores sin `delay()`, filtra (promedio móvil de 5 muestras; mediana para la distancia) y entrega un paquete de 24 bytes.
- **ESP8266 (maestro I2C):** lee el paquete, lo publica por MQTT y reenvía al Arduino las órdenes de configuración.
- **PC local:** recibe los mensajes MQTT con Mosquitto, los guarda en SQLite, **sube los datos pendientes al servidor cada 10 minutos** y consulta cada 5 s si hay órdenes de configuración.
- **Servidor (hosting con Apache + PHP + MySQL):** guarda el histórico, muestra el dashboard con bandas de incertidumbre y administra las órdenes.

## Contenido del repositorio

| Carpeta | Qué contiene |
|---|---|
| `Arduino/` | Firmware del Arduino Uno (`Arduino.ino`). |
| `ESP8266/` | Firmware del NodeMCU (`ESP8266.ino`) y la plantilla `secrets.example.h`. |
| `pc_local/` | Script de la PC local, `requirements.txt` y la plantilla `config.example.json`. |
| `servidor_php/` | API (`nube.php`), dashboard (`panel.php`), funciones comunes (`lib.php`), `index.html`, `.htaccess`, `schema.sql` y la plantilla `config.example.php`. |

Los archivos con claves y datos de red **no están en el repositorio** (ver `.gitignore`): `ESP8266/secrets.h`, `pc_local/config.json` y `servidor_php/config.php`. Hay que crearlos a partir de sus plantillas, como se explica abajo.

## Qué hace falta

- **Hardware:** Arduino Uno, NodeMCU (ESP8266), termocupla K con módulo MAX6675, DHT11, LDR con una resistencia fija de 10 kΩ nominal, HC-SR04, y cables. Una PC con Windows y una red Wi-Fi de **2,4 GHz** (el ESP8266 no funciona en 5 GHz) a la que se conecten la PC y el NodeMCU.
- **Software en la PC:** [Arduino IDE](https://www.arduino.cc/en/software), [Python 3](https://www.python.org/downloads/) (marcar «Add Python to PATH» al instalar) y [Mosquitto](https://mosquitto.org/download/).
- **Servidor:** un hosting con PHP y MySQL, acceso FTP (por ejemplo con [FileZilla](https://filezilla-project.org/)) y phpMyAdmin.

## Conexiones

**Arduino Uno**

| Señal | Pin del Arduino |
|---|---|
| MAX6675 SCK / CS / SO | 4 / 5 / 6 |
| DHT11 (datos) | 2 |
| LDR (punto medio del divisor, resistencia fija a GND) | A0 |
| HC-SR04 TRIG / ECHO | 7 / 3 |
| I2C SDA / SCL | A4 / A5 |

**Bus I2C entre placas:** SDA del Arduino (A4) con D2 del NodeMCU (GPIO4), SCL del Arduino (A5) con D1 del NodeMCU (GPIO5) y masa común. El Arduino trabaja a 5 V y el ESP8266 a 3,3 V, por eso el firmware desactiva los pull-ups internos del Arduino y el bus se deja a 3,3 V con las resistencias externas del lado del ESP8266.

## Puesta en marcha (en este orden)

### 1. Arduino Uno

1. Abrir el Arduino IDE e instalar las bibliotecas desde *Herramientas → Administrar bibliotecas*: **DHT sensor library** (Adafruit) y **Adafruit Unified Sensor**.
2. Abrir `Arduino/Arduino.ino`, elegir *Herramientas → Placa → Arduino Uno* y el puerto COM correspondiente, y cargar el programa.
3. Abrir el Monitor Serie a **115200 baudios**: debe mostrar líneas como `seq=… tc=… ta=… hum=… lux=… dist=…`.

### 2. ESP8266 (gateway)

1. En *Archivo → Preferencias → URLs adicionales de gestor de tarjetas* agregar `http://arduino.esp8266.com/stable/package_esp8266com_index.json`, y en *Gestor de tarjetas* instalar **esp8266**.
2. Instalar las bibliotecas **PubSubClient** y **ArduinoJson** (versión 7).
3. En la carpeta `ESP8266/`, copiar `secrets.example.h` como `secrets.h` y completar:
   - `WIFI_SSID` y `WIFI_PASS`: la red Wi-Fi de 2,4 GHz.
   - `MQTT_HOST`: la dirección IPv4 de la PC donde corre Mosquitto. Se obtiene ejecutando `ipconfig` en la PC (línea «Dirección IPv4»). **Si se cambia de red, esta dirección cambia y hay que actualizarla y volver a cargar el programa.**
4. Elegir la placa *NodeMCU 1.0 (ESP-12E Module)*, el puerto COM y cargar `ESP8266.ino`.

### 3. Mosquitto (broker MQTT en la PC)

1. Instalar Mosquitto. Abrir como administrador el archivo `C:\Program Files\mosquitto\mosquitto.conf` y agregar al final:
   ```
   listener 1883 0.0.0.0
   allow_anonymous true
   ```
2. Abrir una consola **como administrador** y ejecutar:
   ```
   netsh advfirewall firewall add rule name="Mosquitto 1883" dir=in action=allow protocol=TCP localport=1883
   net stop mosquitto
   net start mosquitto
   ```
3. Verificar que está funcionando:
   ```
   sc query mosquitto
   netstat -an | findstr 1883
   ```
   La primera debe decir `RUNNING` y la segunda mostrar `0.0.0.0:1883` en `LISTENING`.
4. Verificar que llegan datos del gateway (con el Arduino y el NodeMCU encendidos):
   ```
   "C:\Program Files\mosquitto\mosquitto_sub.exe" -h localhost -t "lab3/#" -v
   ```
   Deben aparecer mensajes en `lab3/telemetria` y `lab3/estado`. Si no aparece nada, revisar que el NodeMCU y la PC estén en la misma red y que `MQTT_HOST` sea la IP actual de la PC.

### 4. Servidor (nivel 3)

1. En phpMyAdmin, seleccionar la base de datos y usar *Importar* con el archivo `servidor_php/schema.sql` para crear las tablas `telemetria`, `ordenes` y `meta`.
2. En `servidor_php/`, copiar `config.example.php` como `config.php` y completar los datos de la base (host, nombre, usuario y clave), una `api_key` larga y aleatoria, y el usuario y la clave de acceso al panel.
3. Subir por FTP el contenido de `servidor_php/` (incluido `config.php`) a la carpeta pública del hosting.
4. Comprobar el servidor abriendo en el navegador `https://DOMINIO/RUTA/nube.php/api/health`: debe responder `{"ok":true,...}`. El dashboard queda en `https://DOMINIO/RUTA/panel.php` (pide el usuario y la clave del paso 2).

El servidor del grupo está en `https://utecnologica.org/estudiantes/imel/g07/`.

### 5. Script de la PC local

1. Abrir una consola dentro de la carpeta `pc_local/` (en el Explorador, escribir `cmd` en la barra de direcciones y presionar Enter) e instalar las dependencias una sola vez:
   ```
   pip install -r requirements.txt
   ```
2. Copiar `config.example.json` como `config.json` y completar:

   | Campo | Significado |
   |---|---|
   | `mqtt_host`, `mqtt_port` | Broker Mosquitto (por defecto `localhost` y `1883`). |
   | `cloud_url` | Dirección de `nube.php` del servidor, por ejemplo `https://DOMINIO/RUTA/nube.php`. |
   | `api_key` | La misma clave que `api_key` de `config.php`. |
   | `upload_period_s` | Cada cuántos segundos se suben los datos al servidor. **600 = cada 10 minutos** (valor de la entrega). |
   | `poll_period_s` | Cada cuántos segundos se consultan las órdenes de configuración (5). |
   | `db_path` | Archivo de la base local (`nivel2.db`). |

3. Ejecutar el script, indicando el nombre del archivo que tiene en la carpeta (`pc_local.py` o `PC_Local.py`):
   ```
   python pc_local.py config.json
   ```
   En la consola aparecen la conexión al broker, las muestras guardadas y las subidas al servidor. Para detenerlo, `Ctrl + C`.

## Cómo comprobar que todo funciona

1. El Monitor Serie del Arduino muestra las cinco variables.
2. `mosquitto_sub` recibe mensajes en `lab3/telemetria`.
3. Con el script corriendo, se crea `nivel2.db` y, al cumplirse el período, la consola informa la subida al servidor.
4. En el dashboard (`panel.php`) aparecen las muestras y las bandas de incertidumbre ±U de cada variable.
5. Desde el dashboard se cambia el intervalo de un canal: la orden pasa de *pendiente* a *enviada* y a *aplicada* cuando el Arduino la confirma.

## Protocolos y temas MQTT

| Tema | Sentido | Contenido |
|---|---|---|
| `lab3/telemetria` | gateway → PC | Muestras de los cinco canales. |
| `lab3/config` | PC → gateway | Orden de cambio de intervalo (canal e intervalo en ms). |
| `lab3/config/ack` | gateway → PC | Confirmación de la orden aplicada. |
| `lab3/estado` | gateway → PC | Estado del gateway. |

API del servidor (se envía la clave en el encabezado `X-API-Key`): `POST /api/telemetry` (lotes de muestras), `GET /api/config/pending` (orden pendiente) y `POST /api/config/{id}/ack` (confirmación).

## Problemas frecuentes

- **No llegan datos a Mosquitto:** la PC y el NodeMCU deben estar en la misma red de 2,4 GHz y `MQTT_HOST` debe ser la IP actual de la PC (`ipconfig`). Revisar también la regla del firewall del puerto 1883.
- **`python` no se reconoce como comando:** reinstalar Python marcando «Add Python to PATH».
- **El script no encuentra el archivo:** hay que ejecutarlo desde dentro de la carpeta `pc_local/`.
- **El servidor responde «Clave API inválida»:** `api_key` de `config.json` y de `config.php` deben ser idénticas.
- **No se suben datos:** con `upload_period_s = 600` la primera subida ocurre a los 10 minutos de iniciar el script. Para probar rápido se puede bajar a 30 y volver a 600 antes de la entrega.

## Licencia

MIT.
