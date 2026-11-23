#include <Wire.h>
#include <DHT.h>

const uint8_t PIN_TC_SCK = 4;
const uint8_t PIN_TC_CS  = 5;
const uint8_t PIN_TC_SO  = 6;
const uint8_t PIN_DHT    = 2;
const uint8_t PIN_LDR    = A0;
const uint8_t PIN_TRIG   = 7;
const uint8_t PIN_ECHO   = 3;

const uint8_t DIR_I2C = 0x08;
const uint8_t LEN_PAQUETE = 24;
const uint8_t CMD_SET_INTERVALO = 0x01;

const uint8_t N_CANALES = 5;
const uint8_t CH_TC = 0, CH_TA = 1, CH_HUM = 2, CH_LDR = 3, CH_DIST = 4;
const uint8_t N_PROM = 5;

const uint16_t INTERVALO_MIN[N_CANALES] = {250, 2000, 2000, 50, 100};
const uint16_t INTERVALO_MAX = 60000;

const float R_FIJA     = 6670.0f;
const float LDR_R10    = 208686.0f;
const float LDR_GAMMA  = 1.008f;
const unsigned long LDR_ACUM_US = 1000;
unsigned long ldr_suma = 0;
uint16_t ldr_n = 0;
unsigned long ldr_t_acum = 0;
float ldr_adc_ultimo = 0;
const float LUX_MAX    = 3270.0f;

uint16_t intervalo[N_CANALES] = {1000, 2000, 2000, 250, 500};
unsigned long t_ultimo[N_CANALES] = {0, 0, 0, 0, 0};

float buffer[N_CANALES][N_PROM];
uint8_t n_buf[N_CANALES] = {0, 0, 0, 0, 0};
uint8_t idx_buf[N_CANALES] = {0, 0, 0, 0, 0};

DHT dht(PIN_DHT, DHT11);

volatile uint8_t seq = 0;
volatile uint8_t cfg_count = 0;
volatile bool hay_cmd = false;
volatile uint8_t cmd_canal = 0;
volatile uint16_t cmd_valor = 0;
uint8_t paquete[LEN_PAQUETE];

const unsigned long HC_TIMEOUT_MS = 40;
const float DIST_K = 1.0136f;
volatile unsigned long hc_subida = 0;
volatile unsigned long hc_bajada = 0;
volatile bool hc_eco_listo = false;
bool hc_esperando = false;
unsigned long hc_t_disparo = 0;

void alEco() {
  if (digitalRead(PIN_ECHO)) {
    hc_subida = micros();
  } else {
    hc_bajada = micros();
    hc_eco_listo = true;
  }
}

void dispararHC() {
  hc_eco_listo = false;
  digitalWrite(PIN_TRIG, LOW);
  delayMicroseconds(2);
  digitalWrite(PIN_TRIG, HIGH);
  delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  hc_t_disparo = millis();
  hc_esperando = true;
}

float leerMAX6675() {
  uint16_t v = 0;
  digitalWrite(PIN_TC_CS, LOW);
  delayMicroseconds(10);
  for (int8_t i = 15; i >= 0; i--) {
    digitalWrite(PIN_TC_SCK, HIGH);
    delayMicroseconds(5);
    if (digitalRead(PIN_TC_SO)) v |= (uint16_t)1 << i;
    digitalWrite(PIN_TC_SCK, LOW);
    delayMicroseconds(5);
  }
  digitalWrite(PIN_TC_CS, HIGH);
  if (v & 0x0004) return NAN;
  return (v >> 3) * 0.25f;
}

float luxDesdeADC(float a) {
  if (a <= 0.5f) return 0.0f;
  if (a >= 1022.5f) return LUX_MAX;
  float r_ldr = R_FIJA * (1023.0f - a) / a;
  float lux = 10.0f * (float)pow(LDR_R10 / r_ldr, 1.0f / LDR_GAMMA);
  return (lux > LUX_MAX) ? LUX_MAX : lux;
}

float leerCanal(uint8_t c) {
  switch (c) {
    case CH_TC:  return leerMAX6675();
    case CH_TA:  return dht.readTemperature();
    case CH_HUM: return dht.readHumidity();
    case CH_LDR: {
      if (ldr_n > 0) ldr_adc_ultimo = (float)ldr_suma / ldr_n;
      else ldr_adc_ultimo = analogRead(PIN_LDR);
      ldr_suma = 0;
      ldr_n = 0;
      return luxDesdeADC(ldr_adc_ultimo);
    }
  }
  return NAN;
}

void agregarMuestra(uint8_t c, float x) {
  buffer[c][idx_buf[c]] = x;
  idx_buf[c] = (idx_buf[c] + 1) % N_PROM;
  if (n_buf[c] < N_PROM) n_buf[c]++;
}

float mediana(uint8_t c) {
  float v[N_PROM];
  uint8_t n = n_buf[c];
  for (uint8_t i = 0; i < n; i++) v[i] = buffer[c][i];
  for (uint8_t i = 1; i < n; i++) {
    float x = v[i]; int8_t j = i - 1;
    while (j >= 0 && v[j] > x) { v[j + 1] = v[j]; j--; }
    v[j + 1] = x;
  }
  return (n % 2) ? v[n / 2] : 0.5f * (v[n / 2 - 1] + v[n / 2]);
}

float promedio(uint8_t c) {
  if (c == CH_DIST) return mediana(c);
  float s = 0;
  for (uint8_t i = 0; i < n_buf[c]; i++) s += buffer[c][i];
  return s / n_buf[c];
}

float distanciaCm(unsigned long dt_us) {
  float T = (n_buf[CH_TA] > 0) ? promedio(CH_TA) : 22.0f;
  float c = 331.3f + 0.606f * T;
  float cm = (dt_us * 1e-6f) * c / 2.0f * 100.0f;
  if (cm < 2.0f || cm > 400.0f) return NAN;
  return cm * DIST_K;
}

int16_t aEntero(float x, float escala) {
  return (int16_t)lroundf(x * escala);
}

void escribirU16(uint8_t *p, uint16_t v) { p[0] = v & 0xFF; p[1] = v >> 8; }

void armarPaquete() {
  uint8_t tmp[LEN_PAQUETE];
  uint8_t mascara = 0;
  for (uint8_t c = 0; c < N_CANALES; c++) if (n_buf[c] > 0) mascara |= (1 << c);

  tmp[0] = seq;
  tmp[1] = mascara;
  const float escala[N_CANALES] = {4, 100, 100, 10, 10};
  for (uint8_t c = 0; c < N_CANALES; c++) {
    int16_t v = (n_buf[c] > 0) ? aEntero(promedio(c), escala[c]) : 0;
    escribirU16(&tmp[2 + 2 * c], (uint16_t)v);
  }
  for (uint8_t c = 0; c < N_CANALES; c++) escribirU16(&tmp[12 + 2 * c], intervalo[c]);
  tmp[22] = cfg_count;
  uint8_t x = 0;
  for (uint8_t i = 0; i < 23; i++) x ^= tmp[i];
  tmp[23] = x;

  noInterrupts();
  memcpy(paquete, tmp, LEN_PAQUETE);
  interrupts();
}

void alRecibir(int n) {
  if (n >= 4 && Wire.read() == CMD_SET_INTERVALO) {
    uint8_t canal = Wire.read();
    uint8_t lo = Wire.read();
    uint8_t hi = Wire.read();
    cmd_canal = canal;
    cmd_valor = ((uint16_t)hi << 8) | lo;
    hay_cmd = true;
  }
  while (Wire.available()) Wire.read();
}

void alPedir() {
  Wire.write(paquete, LEN_PAQUETE);
}

void procesarComando() {
  noInterrupts();
  uint8_t c = cmd_canal;
  uint16_t v = cmd_valor;
  hay_cmd = false;
  interrupts();

  if (c >= N_CANALES) return;
  if (v < INTERVALO_MIN[c]) v = INTERVALO_MIN[c];
  if (v > INTERVALO_MAX) v = INTERVALO_MAX;
  if (v != intervalo[c]) {
    intervalo[c] = v;
    n_buf[c] = 0;
    idx_buf[c] = 0;
  }
  cfg_count++;
  armarPaquete();
  Serial.print(F(">> Config aplicada: canal ")); Serial.print(c);
  Serial.print(F(" -> ")); Serial.print(v); Serial.println(F(" ms"));
}

void setup() {
  Serial.begin(115200);
  pinMode(PIN_TC_CS, OUTPUT);
  pinMode(PIN_TC_SCK, OUTPUT);
  pinMode(PIN_TC_SO, INPUT);
  digitalWrite(PIN_TC_CS, HIGH);
  digitalWrite(PIN_TC_SCK, LOW);
  pinMode(PIN_TRIG, OUTPUT);
  pinMode(PIN_ECHO, INPUT);
  digitalWrite(PIN_TRIG, LOW);
  attachInterrupt(digitalPinToInterrupt(PIN_ECHO), alEco, CHANGE);
  dht.begin();

  Wire.begin(DIR_I2C);
  digitalWrite(SDA, LOW);
  digitalWrite(SCL, LOW);
  Wire.onReceive(alRecibir);
  Wire.onRequest(alPedir);

  armarPaquete();
  Serial.println(F("=== Arduino Lab 3 (5 canales) ==="));
}

void loop() {
  unsigned long ahora = millis();

  if (hay_cmd) procesarComando();

  if (micros() - ldr_t_acum >= LDR_ACUM_US && ldr_n < 60000) {
    ldr_t_acum = micros();
    ldr_suma += analogRead(PIN_LDR);
    ldr_n++;
  }

  bool hubo_dato = false;
  for (uint8_t c = 0; c < N_CANALES; c++) {
    if (ahora - t_ultimo[c] < intervalo[c]) continue;

    if (hc_esperando && (c == CH_TA || c == CH_HUM || c == CH_DIST)) continue;

    t_ultimo[c] += intervalo[c];
    if (ahora - t_ultimo[c] >= intervalo[c]) t_ultimo[c] = ahora;

    if (c == CH_DIST) {
      dispararHC();
      continue;
    }
    float x = leerCanal(c);
    if (!isnan(x)) {
      agregarMuestra(c, x);
      hubo_dato = true;
    }
  }

  if (hc_esperando) {
    if (hc_eco_listo) {
      noInterrupts();
      unsigned long dt = hc_bajada - hc_subida;
      interrupts();
      hc_esperando = false;
      float d = distanciaCm(dt);
      if (!isnan(d)) {
        agregarMuestra(CH_DIST, d);
        hubo_dato = true;
      }
    } else if (millis() - hc_t_disparo > HC_TIMEOUT_MS) {
      hc_esperando = false;
    }
  }

  if (hubo_dato) {
    seq++;
    armarPaquete();
    Serial.print(F("seq=")); Serial.print(seq);
    Serial.print(F(" tc="));   if (n_buf[CH_TC])   Serial.print(promedio(CH_TC));   else Serial.print(F("-"));
    Serial.print(F(" ta="));   if (n_buf[CH_TA])   Serial.print(promedio(CH_TA));   else Serial.print(F("-"));
    Serial.print(F(" hum="));  if (n_buf[CH_HUM])  Serial.print(promedio(CH_HUM));  else Serial.print(F("-"));
    Serial.print(F(" lux="));  if (n_buf[CH_LDR])  Serial.print(promedio(CH_LDR));  else Serial.print(F("-"));
    Serial.print(F(" dist=")); if (n_buf[CH_DIST]) Serial.println(promedio(CH_DIST)); else Serial.println(F("-"));
  }
}
