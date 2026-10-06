/*
 * uji_sensor_dht.ino
 *
 * Sketch bantu untuk mencari tahu kenapa sensor DHT tidak terbaca. Sketch ini
 * mencoba beberapa pin dan dua tipe sensor (DHT11 dan DHT22), lalu melaporkan
 * kombinasi mana yang menghasilkan angka.
 *
 * Cara pakai:
 *   1. Cabut kabel LAN (tidak wajib, tapi supaya hasil tidak terganggu).
 *   2. Upload sketch ini, buka Serial Monitor 115200.
 *   3. Baca hasilnya. Kalau ada baris "BERHASIL", catat pin dan tipenya.
 *   4. Setelah tahu, upload kembali firmware ethernet_dht11 lalu samakan
 *      PIN_DHT dan TIPE_SENSOR di blok KONFIGURASI PIN dengan hasil itu
 *      (bawaannya sudah GPIO27 dan DHT22).
 *
 * Pin 5, 18, 19, 23 sengaja TIDAK diuji karena dipakai modul W5500
 * (CS, SCK, MISO, MOSI) supaya modul Ethernet tidak terganggu.
 *
 * Pin yang diuji memakai pull-up internal ESP32. Bila sensor hanya terbaca
 * dengan cara ini, berarti sensor kehilangan resistor pull-up 10k (wajib untuk
 * DHT11/DHT22 polos 4 kaki tanpa modul).
 */

#include <DHT.h>

#define VERSI_SKETCH "1.0.0"

// Pin yang diuji. GPIO 5, 18, 19, 23 ikut diuji walaupun dipakai modul W5500
// (CS, SCK, MISO, MOSI), supaya ketahuan bila kabel sensor ternyata nyangkut
// di salah satu pin itu. Pengujiannya hanya membaca/menarik pull-up internal,
// jadi tidak merusak modul.
static const uint8_t PIN_UJI[] = {4, 5, 13, 14, 15, 16, 17, 18, 19, 21, 22, 23, 25, 26, 27, 32, 33};
static const uint8_t JUMLAH_PIN = sizeof(PIN_UJI) / sizeof(PIN_UJI[0]);

// Jeda antar percobaan pada pin yang sama (DHT butuh minimal 2 detik).
static const uint32_t JEDA_ANTAR_BACA_MS = 2200;

static int pinBerhasil = -1;
static int tipeBerhasil = -1;

/**
 * Baca satu kombinasi pin dan tipe sensor.
 *
 * Pull-up internal diaktifkan supaya sensor polos tanpa resistor tetap
 * punya peluang terbaca, sekaligus menjadi petunjuk bila itu penyebabnya.
 *
 * @param pin Alamat GPIO yang diuji.
 * @param tipe Tipe sensor (DHT11 atau DHT22).
 * @param suhu Keluaran suhu (derajat Celsius).
 * @param kelembapan Keluaran kelembapan (persen).
 * @return true bila kedua nilai terbaca wajar.
 */
static bool bacaKombinasi(uint8_t pin, uint8_t tipe, float &suhu, float &kelembapan) {
  pinMode(pin, INPUT_PULLUP);

  DHT sensor(pin, tipe);
  sensor.begin();

  delay(300);
  suhu = sensor.readTemperature();
  kelembapan = sensor.readHumidity();

  return !isnan(suhu) && !isnan(kelembapan);
}

/**
 * Periksa apakah ada sesuatu yang tersambung pada satu pin.
 *
 * Caranya: pin dibaca dua kali, sekali dengan pull-up internal dan sekali
 * dengan pull-down internal. Bila hasilnya berbeda (tinggi lalu rendah),
 * kemungkinan besar tidak ada yang tersambung (garis mengambang). Bila hasil
 * tetap rendah saat pull-up aktif, ada sesuatu yang menahan garis ke GND
 * (sensor mati, urutan kaki tertukar, atau kabel salah).
 *
 * @param pin Alamat GPIO yang diperiksa.
 * @return String keterangan singkat untuk ditampilkan.
 */
static String periksaGaris(uint8_t pin) {
  pinMode(pin, INPUT_PULLUP);
  delay(2);
  int saatPullUp = digitalRead(pin);

  pinMode(pin, INPUT_PULLDOWN);
  delay(2);
  int saatPullDown = digitalRead(pin);

  pinMode(pin, INPUT_PULLUP);

  if (saatPullUp == LOW) {
    return String(F("garis ditahan ke GND (sensor mati / kabel salah)"));
  }

  if (saatPullDown == LOW) {
    return String(F("tidak ada yang tersambung (garis mengambang)"));
  }

  return String(F("ada yang menarik garis ke 3V3 (sensor tersambung)"));
}

/**
 * Cetak satu baris hasil percobaan ke Serial Monitor.
 */
static void cetakHasil(uint8_t pin, uint8_t tipe, bool berhasil, float suhu, float kelembapan) {
  Serial.print(F("GPIO "));
  if (pin < 10) {
    Serial.print(F(" "));
  }
  Serial.print(pin);

  Serial.print(tipe == DHT11 ? F("  DHT11 : ") : F("  DHT22 : "));

  if (!berhasil) {
    Serial.println(F("gagal"));
    return;
  }

  Serial.print(suhu, 1);
  Serial.print(F(" C / "));
  Serial.print(kelembapan, 1);
  Serial.println(F(" %   <== BERHASIL"));
}

/**
 * Uji seluruh kombinasi pin dan tipe, lalu simpulkan hasilnya.
 */
static void ujiSemuaKombinasi() {
  pinBerhasil = -1;
  tipeBerhasil = -1;

  Serial.println();
  Serial.println(F("=== Mulai pengujian pin dan tipe sensor ==="));
  Serial.println(F("Sensor harus sudah tersambung: VCC ke 3V3, GND ke GND, OUT ke pin yang diuji."));
  Serial.println();

  for (uint8_t i = 0; i < JUMLAH_PIN; i++) {
    uint8_t pin = PIN_UJI[i];

    // Kondisi garis pin diperiksa lebih dulu supaya bisa dibedakan antara
    // "kabel tidak tersambung ke pin ini" dan "tersambung tapi sensor diam".
    Serial.print(F("-- GPIO "));
    Serial.print(pin);
    Serial.print(F(" : "));
    Serial.println(periksaGaris(pin));

    // DHT11 lebih dulu.
    float suhu = NAN;
    float kelembapan = NAN;
    bool berhasil = bacaKombinasi(pin, DHT11, suhu, kelembapan);
    cetakHasil(pin, DHT11, berhasil, suhu, kelembapan);

    if (berhasil && pinBerhasil < 0) {
      pinBerhasil = pin;
      tipeBerhasil = DHT11;
    }

    delay(JEDA_ANTAR_BACA_MS);

    // Lalu DHT22 pada pin yang sama.
    suhu = NAN;
    kelembapan = NAN;
    berhasil = bacaKombinasi(pin, DHT22, suhu, kelembapan);
    cetakHasil(pin, DHT22, berhasil, suhu, kelembapan);

    if (berhasil && pinBerhasil < 0) {
      pinBerhasil = pin;
      tipeBerhasil = DHT22;
    }

    delay(JEDA_ANTAR_BACA_MS);
  }

  Serial.println();
  if (pinBerhasil < 0) {
    Serial.println(F("KESIMPULAN: tidak ada kombinasi yang berhasil."));
    Serial.println(F("Periksa: 1) urutan kaki sensor (VCC, DATA, GND), 2) sensor polos 4 kaki"));
    Serial.println(F("butuh resistor 10k antara VCC dan DATA, 3) coba sensor lain bila tetap gagal."));
    return;
  }

  Serial.print(F("KESIMPULAN: sensor terbaca pada GPIO "));
  Serial.print(pinBerhasil);
  Serial.println(tipeBerhasil == DHT11 ? F(" dengan tipe DHT11.") : F(" dengan tipe DHT22."));
  Serial.println(F("Pakai nilai itu pada PIN_DHT dan tipe sensor di firmware ethernet_dht11."));
}

void setup() {
  Serial.begin(115200);
  delay(300);

  Serial.println();
  Serial.println(F("===================================================="));
  Serial.println(F(" UJI SENSOR DHT11 / DHT22 - pencari pin dan tipe"));
  Serial.print(F(" Versi sketch : "));
  Serial.println(F(VERSI_SKETCH));
  Serial.println(F("===================================================="));

  ujiSemuaKombinasi();
}

void loop() {
  // Bila sudah ketemu, pantau terus kombinasi itu supaya nilainya bisa dilihat.
  if (pinBerhasil >= 0) {
    float suhu = NAN;
    float kelembapan = NAN;
    bool berhasil = bacaKombinasi((uint8_t)pinBerhasil, (uint8_t)tipeBerhasil, suhu, kelembapan);

    cetakHasil((uint8_t)pinBerhasil, (uint8_t)tipeBerhasil, berhasil, suhu, kelembapan);
    delay(5000);
    return;
  }

  // Bila belum ada yang berhasil, ulangi pengujian tiap 30 detik supaya
  // pengguna bisa memperbaiki kabel lalu melihat hasilnya tanpa upload ulang.
  Serial.println(F("Menunggu 30 detik, lalu menguji ulang. Perbaiki kabel sekarang bila perlu."));
  delay(30000);
  ujiSemuaKombinasi();
}
