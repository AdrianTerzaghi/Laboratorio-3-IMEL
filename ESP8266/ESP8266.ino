#include <ESP8266WiFi.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <Wire.h>
#include "secrets.h"

const uint8_t DIR_ARDUINO = 0x08;
const uint8_t LEN_PAQUETE = 24;
const uint8_t CMD_SET_INTERVALO = 0x01;
const uint8_t N_CANALES = 5;

const unsigned long PERIODO_LECTURA_MS = 500;
const char* TOPICO_TELEMETRIA = "lab3/telemetria";
const char* TOPICO_CONFIG     = "lab3/config";
const char* TOPICO_ACK        = "lab3/config/ack";
const char* TOPICO_ESTADO     = "lab3/estado";

WiFiClient wifi;
PubSubClient mqtt(wifi);

uint8_t ultimo_seq = 255;
bool primera_lectura = true;

bool pend_activo = false;
long pend_id = 0;
uint8_t pend_canal = 0;
uint16_t pend_valor = 0;

void conectarWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  Serial.print("Conectando a WiFi");
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 15000) {
    delay(250);
    Serial.print(".");
  }
  Serial.println(WiFi.status() == WL_CONNECTED ? " OK" : " fallo");
}

void conectarMQTT() {
  if (mqtt.connected() || WiFi.status() != WL_CONNECTED) return;
  Serial.print("Conectando a MQTT... ");
  if (mqtt.connect("lab3-esp8266")) {
    mqtt.subscribe(TOPICO_CONFIG, 1);
    Serial.println("OK");
  } else {
    Serial.print("fallo, rc=");
    Serial.println(mqtt.state());
  }
}

bool enviarComandoI2C(uint8_t canal, uint16_t valor) {
  Wire.beginTransmission(DIR_ARDUINO);
  Wire.write(CMD_SET_INTERVALO);
  Wire.write(canal);
  Wire.write((uint8_t)(valor & 0xFF));
  Wire.write((uint8_t)(valor >> 8));
  return Wire.endTransmission() == 0;
}

void alMensaje(char* topico, byte* carga, unsigned int largo) {
  if (strcmp(topico, TOPICO_CONFIG) != 0) return;
  JsonDocument doc;
  if (deserializeJson(doc, carga, largo)) {
    Serial.println("Config: JSON invalido");
    return;
  }
  long id = doc["id"] | -1L;
  int ch = doc["ch"] | -1;
  long ms = doc["interval_ms"] | -1L;
  if (id < 0 || ch < 0 || ch >= N_CANALES || ms <= 0 || ms > 65535) {
    Serial.println("Config: campos fuera de rango");
    return;
  }
  if (enviarComandoI2C((uint8_t)ch, (uint16_t)ms)) {
    pend_activo = true;
    pend_id = id;
    pend_canal = (uint8_t)ch;
    pend_valor = (uint16_t)ms;
    Serial.printf("Config enviada al Arduino: ch=%d, %ld ms\n", ch, ms);
  } else {
    Serial.println("Config: fallo la escritura I2C");
  }
}

int16_t leerI16(const uint8_t* p) { return (int16_t)(p[0] | (p[1] << 8)); }
uint16_t leerU16(const uint8_t* p) { return (uint16_t)(p[0] | (p[1] << 8)); }

void leerYPublicar() {
  uint8_t buf[LEN_PAQUETE];
  uint8_t n = Wire.requestFrom((int)DIR_ARDUINO, (int)LEN_PAQUETE);
  if (n != LEN_PAQUETE) {
    while (Wire.available()) Wire.read();
    Serial.println("I2C: sin respuesta del Arduino");
    return;
  }
  for (uint8_t i = 0; i < LEN_PAQUETE; i++) buf[i] = Wire.read();

  uint8_t x = 0;
  for (uint8_t i = 0; i < LEN_PAQUETE - 1; i++) x ^= buf[i];
  if (x != buf[LEN_PAQUETE - 1]) {
    Serial.println("Paquete I2C con checksum incorrecto");
    return;
  }

  uint8_t seq = buf[0];
  uint8_t mascara = buf[1];
  uint16_t iv[N_CANALES];
  for (uint8_t c = 0; c < N_CANALES; c++) iv[c] = leerU16(&buf[12 + 2 * c]);

  if (pend_activo && iv[pend_canal] == pend_valor) {
    JsonDocument ack;
    ack["id"] = pend_id;
    ack["ch"] = pend_canal;
    ack["interval_ms"] = pend_valor;
    ack["applied"] = true;
    char out[128];
    size_t len = serializeJson(ack, out);
    mqtt.publish(TOPICO_ACK, (const uint8_t*)out, len, false);
    pend_activo = false;
    Serial.println("Config confirmada por el Arduino");
  }

  if (!primera_lectura && seq == ultimo_seq) return;
  primera_lectura = false;
  ultimo_seq = seq;

  JsonDocument doc;
  doc["seq"] = seq;
  const char* claves[N_CANALES] = {"tc", "ta", "hum", "ldr", "dist"};
  const float escala[N_CANALES] = {4.0f, 100.0f, 100.0f, 10.0f, 10.0f};
  for (uint8_t c = 0; c < N_CANALES; c++) {
    if (mascara & (1 << c)) doc[claves[c]] = leerI16(&buf[2 + 2 * c]) / escala[c];
    else doc[claves[c]] = nullptr;
  }
  JsonArray arr = doc["iv"].to<JsonArray>();
  for (uint8_t c = 0; c < N_CANALES; c++) arr.add(iv[c]);

  char out[320];
  size_t len = serializeJson(doc, out);
  mqtt.publish(TOPICO_TELEMETRIA, (const uint8_t*)out, len, false);
  Serial.println(out);
}

void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println();
  Serial.println();
  Serial.println("=== Gateway Lab 3 ===");
  Wire.begin(4, 5);
  Wire.setClockStretchLimit(10000);
  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setCallback(alMensaje);
  mqtt.setBufferSize(512);
}

void loop() {
  static unsigned long t_lectura = 0;
  static unsigned long t_estado = 0;

  conectarWiFi();
  conectarMQTT();
  mqtt.loop();

  if (millis() - t_lectura >= PERIODO_LECTURA_MS) {
    t_lectura = millis();
    if (mqtt.connected()) leerYPublicar();
  }

  if (mqtt.connected() && millis() - t_estado >= 5000) {
    t_estado = millis();
    mqtt.publish(TOPICO_ESTADO, "gateway activo");
  }
}
