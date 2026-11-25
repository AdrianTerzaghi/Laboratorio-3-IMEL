# Laboratorio 03 – Sistema de instrumentación multivariable IoT/IIoT

Instrumentación y Medidas Eléctricas · Ingeniería en Mecatrónica · UTEC
Integrantes: Guido Andreoli, Lucas De León y Adrián Terzaghi (Grupo 07).

Instrumento distribuido en tres niveles que mide **cinco variables**, guarda el histórico, lo muestra en un dashboard web y permite **cambiar a distancia el intervalo de muestreo** de cada canal.

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
                                                      script + SQLite)
```

- **Arduino Uno (esclavo I2C, dirección 0x08):** lee los sensores sin `delay()`, filtra (promedio móvil de 5 muestras; mediana en la distancia) y entrega un paquete de 24 bytes.
- **ESP8266 (maestro I2C):** lee el paquete, lo publica por MQTT y reenvía al Arduino las órdenes de configuración.
- **PC local:** recibe los mensajes con Mosquitto, los guarda en SQLite, **sube los datos pendientes al servidor cada 10 minutos** y consulta cada 5 s si hay órdenes de configuración.
- **Servidor (Apache + PHP + MySQL):** guarda el histórico, muestra el dashboard con bandas de incertidumbre y administra las órdenes.

## Contenido del repositorio

| Carpeta | Qué contiene |
|---|---|
| `Arduino/` | Firmware del Arduino Uno (`Arduino.ino`). |
| `ESP8266/` | Firmware del NodeMCU (`ESP8266.ino`) y la plantilla `secrets.example.h`. |
| `pc_local/` | Script de la PC local, `requirements.txt` y la plantilla `config.example.json`. |
| `servidor_php/` | API (`nube.php`), dashboard (`panel.php`), funciones comunes (`lib.php`), `index.html`, `.htaccess`, `schema.sql` y la plantilla `config.example.php`. |

Los archivos con claves **no están en el repositorio** (ver `.gitignore`): `ESP8266/secrets.h`, `pc_local/config.json` y `servidor_php/config.php`. Se crean a partir de sus plantillas en los pasos de abajo.

## Qué hace falta antes de empezar

- **Hardware:** Arduino Uno, NodeMCU (ESP8266), termocupla K con módulo MAX6675, DHT11, LDR con una resistencia fija de 10 kΩ nominal, HC-SR04, cables y dos cables USB.
- **Red:** una red Wi-Fi de **2,4 GHz** (el ESP8266 no funciona en 5 GHz) a la que se conecten la PC y el NodeMCU.
- **PC con Windows** con estos programas instalados:
  - [Arduino IDE](https://www.arduino.cc/en/software).
  - [Python 3](https://www.python.org/downloads/): al instalar, **marcar «Add python.exe to PATH»**.
  - [Mosquitto](https://mosquitto.org/download/) (instalador de Windows, con la opción de instalarlo como servicio).
  - [FileZilla](https://filezilla-project.org/) (solo para subir el servidor por FTP).
- **Servidor:** un hosting con PHP y MySQL, acceso FTP y phpMyAdmin.

### Cómo abrir una consola (cmd)

- **Consola normal dentro de una carpeta:** abrir la carpeta en el Explorador de archivos, hacer clic en la barra de direcciones (donde está la ruta), escribir `cmd` y presionar Enter. Se abre una ventana negra ya ubicada en esa carpeta.
- **Consola como administrador:** menú Inicio, escribir `cmd`, clic derecho en «Símbolo del sistema» y *Ejecutar como administrador*.

### Paso 0. Descargar el repositorio

En esta página de GitHub: botón verde **Code → Download ZIP**, y descomprimir el ZIP (por ejemplo en `C:\Lab3`). Si se tiene Git instalado, también sirve:

```
git clone https://github.com/AdrianTerzaghi/Laboratorio-3-IMEL.git
```

Comprobar que Python quedó instalado, en una consola:

```
python --version
pip --version
```

Ambos comandos deben mostrar un número de versión.

## Conexiones

**Arduino Uno**

| Señal | Pin del Arduino |
|---|---|
| MAX6675 SCK / CS / SO | 4 / 5 / 6 |
| DHT11 (datos) | 2 |
| LDR (punto medio del divisor; la resistencia fija va a GND) | A0 |
| HC-SR04 TRIG / ECHO | 7 / 3 |
| I2C SDA / SCL | A4 / A5 |

**Bus I2C entre placas:** SDA del Arduino (A4) con D2 del NodeMCU (GPIO4), SCL del Arduino (A5) con D1 del NodeMCU (GPIO5) y masa (GND) común. El Arduino trabaja a 5 V y el ESP8266 a 3,3 V: el firmware desactiva los pull-ups internos del Arduino y el bus queda a 3,3 V con las resistencias externas del lado del ESP8266.

## Puesta en marcha (en este orden)

### Paso 1. Arduino Uno

1. Conectar el Arduino a la PC por USB.
2. Abrir el Arduino IDE. En el menú *Herramientas → Administrar bibliotecas…* buscar e instalar **DHT sensor library** (de Adafruit) y **Adafruit Unified Sensor**. Si pregunta por dependencias, aceptar «Install all».
3. *Archivo → Abrir…* y elegir `Arduino/Arduino.ino`.
4. *Herramientas → Placa → Arduino AVR Boards → Arduino Uno*.
5. *Herramientas → Puerto* y elegir el puerto COM del Arduino (si hay dudas, es el que aparece al conectar el cable; también se ve en el *Administrador de dispositivos* de Windows, en «Puertos (COM y LPT)»).
6. Presionar el botón **Subir** (flecha →). Debe terminar con «Subido».
7. *Herramientas → Monitor Serie*, con la velocidad **115200 baudios**. Debe imprimir cada segundo líneas como:
   ```
   seq=12 tc=24.50 ta=24.60 hum=55.00 lux=210.30 dist=15.20
   ```
   Un guion (`-`) en una variable significa que todavía no hay muestra de ese canal.

### Paso 2. ESP8266 (gateway)

1. En el Arduino IDE: *Archivo → Preferencias* y, en «URLs adicionales de gestor de tarjetas», pegar:
   ```
   http://arduino.esp8266.com/stable/package_esp8266com_index.json
   ```
2. *Herramientas → Placa → Gestor de tarjetas…*, buscar **esp8266** e instalar «esp8266 by ESP8266 Community».
3. *Herramientas → Administrar bibliotecas…* e instalar **PubSubClient** (Nick O'Leary) y **ArduinoJson** (Benoit Blanchon, **versión 7**).
4. En la carpeta `ESP8266/`, copiar el archivo `secrets.example.h` y pegarlo con el nombre `secrets.h` (en la misma carpeta). Abrirlo con el Bloc de notas y completar:
   ```
   #define WIFI_SSID "nombre_de_tu_red_2_4GHz"
   #define WIFI_PASS "clave_de_tu_red"
   #define MQTT_HOST "192.168.1.50"
   #define MQTT_PORT 1883
   ```
   `MQTT_HOST` es la dirección IPv4 de la PC. Se obtiene abriendo una consola y ejecutando:
   ```
   ipconfig
   ```
   y copiando el número de la línea **«Dirección IPv4»** del adaptador Wi-Fi que se usa. **Si se cambia de red, esta dirección cambia: hay que actualizar `MQTT_HOST` y volver a cargar el programa.**
5. Conectar el NodeMCU por USB. Abrir `ESP8266/ESP8266.ino`, elegir *Herramientas → Placa → ESP8266 Boards → NodeMCU 1.0 (ESP-12E Module)* y el puerto COM del NodeMCU, y presionar **Subir**.
6. Abrir el Monitor Serie a **115200 baudios**: debe mostrar la conexión a la red Wi-Fi y al broker MQTT (este último paso funciona recién cuando Mosquitto está en marcha, ver el paso 3).

### Paso 3. Mosquitto (broker MQTT en la PC)

1. Abrir el **Bloc de notas como administrador** (menú Inicio, escribir «Bloc de notas», clic derecho, *Ejecutar como administrador*), *Archivo → Abrir* y elegir `C:\Program Files\mosquitto\mosquitto.conf` (en el selector, cambiar el filtro a «Todos los archivos»). Agregar **al final** estas dos líneas y guardar:
   ```
   listener 1883 0.0.0.0
   allow_anonymous true
   ```
2. Abrir una consola **como administrador** y ejecutar, uno por uno:
   ```
   netsh advfirewall firewall add rule name="Mosquitto 1883" dir=in action=allow protocol=TCP localport=1883
   net stop mosquitto
   net start mosquitto
   ```
   El primero habilita el puerto 1883 en el firewall (se hace una sola vez). Los otros dos reinician el servicio para que lea la configuración.
3. Verificar que está en marcha:
   ```
   sc query mosquitto
   netstat -an | findstr 1883
   ```
   El primero debe mostrar `STATE : 4  RUNNING` y el segundo una línea con `0.0.0.0:1883` en `LISTENING`.
4. Verificar que llegan los datos del gateway (con el Arduino y el NodeMCU encendidos). En una consola normal:
   ```
   "C:\Program Files\mosquitto\mosquitto_sub.exe" -h localhost -t "lab3/#" -v
   ```
   Debe imprimir mensajes de `lab3/telemetria` (y de `lab3/estado`). Se corta con `Ctrl + C`. **Si no aparece nada:** la PC y el NodeMCU deben estar en la misma red de 2,4 GHz, `MQTT_HOST` debe ser la IPv4 actual de la PC y el firewall debe tener la regla del punto 2.

### Paso 4. Servidor (nivel 3)

1. **Base de datos.** Entrar a phpMyAdmin del hosting, elegir la base de datos del grupo, ir a la pestaña **Importar**, elegir el archivo `servidor_php/schema.sql` y presionar **Importar**. Deben aparecer las tablas `telemetria`, `ordenes` y `meta`.
2. **Configuración.** En la carpeta `servidor_php/`, copiar `config.example.php` y pegarlo como `config.php`. Abrirlo con el Bloc de notas y completar:
   - `db_hosts`, `db_name`, `db_user`, `db_pass`: los datos de la base que da el hosting (en phpMyAdmin).
   - `api_key`: una clave larga y aleatoria (sirve cualquier texto de unos 40 caracteres). **Es la misma que irá en `pc_local/config.json`.**
   - `panel_user` y `panel_pass`: usuario y clave para entrar al dashboard.
3. **Subida por FTP.** En FileZilla: *Archivo → Gestor de sitios → Nuevo sitio*, con el servidor, usuario y clave FTP del hosting, y *Conectar*. En el panel izquierdo (PC) abrir la carpeta `servidor_php/`; en el panel derecho (servidor) abrir la carpeta pública que corresponda al grupo. Seleccionar **todo el contenido** de `servidor_php/` (incluido `config.php` y `.htaccess`) y arrastrarlo al panel derecho. El archivo `schema.sql` no hace falta subirlo.
4. **Prueba.** En la consola:
   ```
   curl https://DOMINIO/RUTA/nube.php/api/health
   ```
   Debe responder algo como `{"ok":true,"ts":...}`. También se puede abrir esa dirección en el navegador. El dashboard está en `https://DOMINIO/RUTA/panel.php` y pide el usuario y la clave del punto 2.

El servidor del grupo está en `https://utecnologica.org/estudiantes/imel/g07/`.

### Paso 5. Script de la PC local

1. Abrir una consola **dentro de la carpeta `pc_local/`** (ver «Cómo abrir una consola») e instalar las dependencias, una sola vez:
   ```
   pip install -r requirements.txt
   ```
2. Copiar `config.example.json` y pegarlo como `config.json`. Abrirlo con el Bloc de notas y completar:

   | Campo | Qué poner |
   |---|---|
   | `mqtt_host` | `localhost` (Mosquitto corre en la misma PC). |
   | `mqtt_port` | `1883`. |
   | `cloud_url` | La dirección de `nube.php`, por ejemplo `https://DOMINIO/RUTA/nube.php`. |
   | `api_key` | La misma clave que `api_key` de `config.php`. |
   | `upload_period_s` | Cada cuántos segundos se suben los datos. **600 = cada 10 minutos** (valor de la entrega). |
   | `poll_period_s` | Cada cuántos segundos se consultan las órdenes de configuración (`5`). |
   | `db_path` | `nivel2.db` (base local en SQLite; se crea sola). |

3. Ejecutar el script (con el nombre exacto del archivo que hay en la carpeta, `pc_local.py` o `PC_Local.py`):
   ```
   python pc_local.py config.json
   ```
   La consola muestra la conexión al broker, las muestras guardadas y las subidas al servidor. Se detiene con `Ctrl + C`. Debe quedar abierta mientras el sistema funciona.

## Arranque rápido (después de la primera configuración)

Cada vez que se enciende el sistema:

1. Conectar el Arduino y el NodeMCU (con alimentación; el NodeMCU debe estar en la misma red que la PC).
2. Comprobar que Mosquitto corre. Si no, en una consola como administrador:
   ```
   net start mosquitto
   ```
3. Si la red cambió, ejecutar `ipconfig`, actualizar `MQTT_HOST` en `ESP8266/secrets.h` y volver a cargar `ESP8266.ino`.
4. En una consola dentro de `pc_local/`:
   ```
   python pc_local.py config.json
   ```
5. Abrir el dashboard en `panel.php`.

## Cómo comprobar que todo funciona

1. El Monitor Serie del Arduino muestra las cinco variables.
2. `mosquitto_sub` (paso 3, punto 4) recibe mensajes en `lab3/telemetria`.
3. Con el script corriendo se crea el archivo `nivel2.db` en `pc_local/`. Al cumplirse `upload_period_s` la consola informa la subida al servidor. Para una prueba rápida se puede poner `upload_period_s` en `30`, y **volver a `600`** al terminar.
4. En el dashboard aparecen las muestras con las bandas de incertidumbre ±U de cada variable.
5. Desde el dashboard se cambia el intervalo de un canal: la orden pasa de *pendiente* a *enviada* y a *aplicada* cuando el Arduino la confirma (el cambio se nota en el Monitor Serie y en el histórico).
6. Estado general del servidor, con la clave en el encabezado:
   ```
   curl -H "X-API-Key: TU_CLAVE" https://DOMINIO/RUTA/nube.php/api/status
   ```

## Protocolos y temas MQTT

| Tema | Sentido | Contenido |
|---|---|---|
| `lab3/telemetria` | gateway → PC | Muestras de los cinco canales. |
| `lab3/config` | PC → gateway | Orden de cambio de intervalo (canal e intervalo en ms). |
| `lab3/config/ack` | gateway → PC | Confirmación de la orden aplicada. |
| `lab3/estado` | gateway → PC | Estado del gateway. |

API del servidor (la clave va en el encabezado `X-API-Key`): `POST /api/telemetry` (lotes de muestras), `GET /api/config/pending` (orden pendiente) y `POST /api/config/{id}/ack` (confirmación). Las órdenes sin confirmar se vuelven a ofrecer a los 20 s y vencen a los 240 s.

## Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| No llegan datos a Mosquitto | La PC y el NodeMCU deben estar en la misma red de 2,4 GHz y `MQTT_HOST` debe ser la IPv4 actual de la PC (`ipconfig`). Revisar la regla del firewall (paso 3, punto 2). |
| `python` no se reconoce como comando | Reinstalar Python marcando «Add python.exe to PATH» y abrir una consola nueva. |
| `No such file or directory` al ejecutar el script | La consola no está dentro de `pc_local/`, o el nombre del script o de `config.json` no coincide. |
| `Clave API inválida` (error 401) | `api_key` de `config.json` y de `config.php` deben ser idénticas. |
| `Error del servidor` o 500 en `nube.php` | Datos de la base mal escritos en `config.php`, o tablas no creadas (paso 4, punto 1). |
| No se suben datos | Con upload_period_s = 600 el script sube los datos pendientes al arrancar y después cada 10 minutos. Si no hay muestras nuevas, no imprime nada. |
| El Arduino no carga | Revisar la placa (Arduino Uno) y el puerto COM elegidos en *Herramientas*. |
| El NodeMCU no se conecta | Revisar `WIFI_SSID` y `WIFI_PASS`, y que la red sea de 2,4 GHz. |
| Lecturas con guion (`-`) | Todavía no hay muestra de ese canal, o el sensor no está conectado. |

## Licencia

MIT.
