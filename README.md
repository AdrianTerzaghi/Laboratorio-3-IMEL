# Laboratorio 03 – Sistema de instrumentación multivariable IoT/IIoT

Instrumentación y Medidas Eléctricas · Ingeniería en Mecatrónica · UTEC

Integrantes: Guido Andreoli, Lucas De León y Adrián Terzaghi.

Instrumento distribuido de tres niveles que mide cinco variables con cuatro sensores (temperatura de proceso con termocupla K y MAX6675, temperatura ambiente y humedad con DHT11, iluminancia con LDR y distancia con HC-SR04), guarda el histórico y permite cambiar desde un panel remoto el intervalo de muestreo de cada canal. Todo el software es de código abierto.

```
Arduino Uno --I2C--> ESP8266 --MQTT--> PC local (Mosquitto + SQLite) --HTTP--> Servidor / dashboard
   nivel 1              nivel 2                                                    nivel 3
```

## Estructura

| Carpeta | Contenido |
|---|---|
| `Arduino/` | Firmware del Arduino Uno (nivel 1): lectura de sensores, muestreo con `millis()`, filtrado y esclavo I2C en la dirección 0x08. |
| `ESP8266/` | Firmware del NodeMCU (nivel 2): maestro I2C y cliente MQTT. |
| `PC_Local/` | Script `PC_Local.py`: recibe la telemetría de Mosquitto, la guarda en SQLite, la sube al servidor cada 10 min y consulta las órdenes de configuración. |
| `Servidor_PHP/` | Nivel 3: API (`nube.php`), dashboard (`panel.php`), funciones comunes (`lib.php`), página de inicio, `.htaccess`, plantilla `config.example.php` y `schema.sql` con las tablas. Se carga por FTP al hosting con Apache, PHP y MySQL. |

## Puesta en marcha

1. **Arduino Uno.** Instalar en el IDE las bibliotecas *DHT sensor library* y *Adafruit Unified Sensor*, y cargar `Arduino/Arduino.ino`.
2. **ESP8266.** Instalar el paquete de placas ESP8266, y las bibliotecas *PubSubClient* y *ArduinoJson* 7. Copiar `ESP8266/secrets.example.h` como `secrets.h`, completar la red Wi-Fi (2,4 GHz) y la IP de la PC, y cargar `ESP8266.ino` en la placa NodeMCU 1.0.
3. **Mosquitto.** Instalarlo en la PC local y agregar `listener 1883 0.0.0.0` y `allow_anonymous true` a `mosquitto.conf`.
4. **PC local.** Dentro de `PC_Local/`: `pip install -r requirements.txt`, copiar `config.example.json` como `config.json` con la dirección del servidor y la clave de la API, y ejecutar `python PC_Local.py config.json`.
5. **Servidor.** Crear las tablas con `Servidor_PHP/schema.sql` (phpMyAdmin), copiar `config.example.php` como `config.php` con los datos de la base y las claves, y subir la carpeta por FTP. La PC local usa como `cloud_url` la dirección de `nube.php`; el panel está en `panel.php`.

Los archivos con claves y datos de red (`secrets.h`, `config.json`, `config.php`) no se incluyen en el repositorio.

## Licencia

MIT.
