/*
 * ethernet_dht11.ino
 *
 * Firmware monitoring suhu & kelembapan untuk ESP32-WROOM-32 + modul Ethernet
 * W5500 (SPI) + sensor DHT11. Data dikirim ke server monitoring (Flask) yang
 * berjalan di komputer dalam jaringan lokal.
 *
 * Perangkat keras:
 *   - ESP32-WROOM-32 dev board (board Arduino: DOIT ESP32 DEVKIT V1)
 *   - Modul Ethernet W5500 (RJ45 + magnetics HanRun HR961160C), bus SPI
 *   - Sensor DHT11 modul 3 pin (+, out, -)
 *   - POE Splitter 802.3af/at keluaran 5V DC sebagai sumber daya
 *
 * Wiring:
 *   W5500 VCC   -> 3V3 ESP32        (JANGAN 5V)
 *   W5500 GND   -> GND ESP32
 *   W5500 SCK   -> GPIO18
 *   W5500 MISO  -> GPIO19
 *   W5500 MOSI  -> GPIO23
 *   W5500 CS    -> GPIO5
 *   DHT11 VCC   -> 3V3 ESP32
 *   DHT11 GND   -> GND ESP32
 *   DHT11 OUT   -> GPIO4
 *   POE 5V out+ -> VIN ESP32
 *   POE 5V out- -> GND ESP32
 *
 * Library yang dipakai:
 *   - Ethernet 2.0.0 (Arduino/Paul Stoffregen)  -> sudah terpasang bersama Arduino IDE
 *   - DHT sensor library 1.4.7 (Adafruit)       -> sudah terpasang
 *   - Adafruit Unified Sensor 1.1.15            -> sudah terpasang
 * Catatan penting: pada board sketch ini <Ethernet.h> diambil dari
 * "C:\Program Files (x86)\Arduino\libraries\Ethernet" versi 2.0.0 (bukan
 * library Ethernet bawaan core ESP32, karena library core ESP32 2.0.9 tidak
 * mendukung W5500 lewat SPI). Bila suatu saat W5500 tidak terdeteksi, pasang
 * library Ethernet_Generic lalu ganti baris include di bawah menjadi
 * <Ethernet_Generic.h> - pemanggilan Ethernet.init(pin) tetap sama.
 *
 * Firmware ini mengerjakan dua jalur data sekaligus:
 *   1. PUSH  : setiap INTERVAL_KIRIM_MS mengirim HTTP POST ke server.
 *   2. SERVE : menjalankan HTTP server kecil di port 80 supaya server/PC bisa
 *              menarik data sendiri (/api/latest, /api/buffer, /api/status).
 * Jalur SERVE diperlukan bila port PUSH diblokir firewall komputer server,
 * atau bila ingin data tetap terkumpul walau urutan waktu pengiriman bergeser.
 */

#include <SPI.h>
#include <Ethernet.h>
#include <DHT.h>

#define VERSI_FIRMWARE "1.0.0"
#define NAMA_PERANGKAT "sensor-suhu-01"

// ---------------------------------------------------------------------------
// KONFIGURASI JARINGAN
// Sesuaikan hanya blok ini bila pindah jaringan/perangkat.
// ---------------------------------------------------------------------------
static uint8_t MAC_PERANGKAT[6] = {0x02, 0x00, 0x5A, 0x41, 0x00, 0x01};
static IPAddress IP_PERANGKAT(192, 168, 41, 240);
static IPAddress IP_GATEWAY(192, 168, 41, 254);
static IPAddress IP_NETMASK(255, 255, 255, 0);
static IPAddress IP_DNS(8, 8, 8, 8);

// Tujuan PUSH (komputer yang menjalankan server.py).
static IPAddress IP_SERVER(192, 168, 41, 74);
static const uint16_t PORT_SERVER = 5000;

// Port HTTP server lokal pada ESP32 (untuk mode SERVE/PULL).
static const uint16_t PORT_HTTP_LOKAL = 80;

// ---------------------------------------------------------------------------
// KONFIGURASI PIN
// ---------------------------------------------------------------------------
static const uint8_t PIN_CS_ETHERNET = 5;
static const uint8_t PIN_DHT = 4;
static const uint8_t PIN_LED_STATUS = 2;

// ---------------------------------------------------------------------------
// KONFIGURASI WAKTU DAN BUFFER
// ---------------------------------------------------------------------------
static const uint32_t INTERVAL_BACA_MS = 10000;      // baca sensor tiap 10 detik
static const uint32_t INTERVAL_KIRIM_MS = 10000;     // coba kirim tiap 10 detik
static const uint32_t INTERVAL_CEK_LINK_MS = 30000;  // cek link/status tiap 30 detik
static const uint32_t INTERVAL_HEARTBEAT_MS = 300000; // heartbeat bila sensor gagal, 5 menit
static const uint32_t MAKS_KIRIM_PER_SIKLUS = 3;     // backfill maksimum 3 data per siklus
static const uint16_t UKURAN_BUFFER = 60;            // simpan 60 pembacaan terakhir

// Satu pembacaan sensor beserta metadata pengiriman.
struct Pembacaan {
  uint32_t id;          // nomor urut pembacaan sejak perangkat menyala
  uint32_t uptimeS;     // uptime perangkat (detik) saat pembacaan diambil
  float suhu;           // derajat Celsius
  float kelembapan;     // persen
  bool valid;           // hasil pembacaan sensor masuk akal
  bool terkirim;        // sudah berhasil dikirim/ditarik server
};

static DHT sensorDht(PIN_DHT, DHT11);

/**
 * Pembungkus EthernetServer agar bisa dipakai di core ESP32.
 *
 * Server bawaan core ESP32 mendeklarasikan begin(uint16_t port = 0) sebagai
 * fungsi virtual murni, sementara EthernetServer versi Arduino hanya
 * mendeklarasikan begin() tanpa parameter sehingga kelasnya menjadi abstrak
 * dan tidak bisa di-instansiasi. Kelas ini menambahkan override yang
 * diperlukan, jadi tidak perlu mengubah library Ethernet sama sekali.
 */
class ServerHttpLokal : public EthernetServer {
 public:
  explicit ServerHttpLokal(uint16_t port) : EthernetServer(port) {}

  void begin(uint16_t port = 0) override {
    (void)port;
    EthernetServer::begin();
  }
};

static ServerHttpLokal serverHttp(PORT_HTTP_LOKAL);

static Pembacaan bufferPembacaan[UKURAN_BUFFER];
static uint32_t idTerakhir = 0;
static uint32_t jumlahTersimpan = 0;
static uint32_t jumlahGagalBaca = 0;
static uint32_t jumlahKirimSukses = 0;
static uint32_t jumlahKirimGagal = 0;
static uint32_t waktuKirimSuksesTerakhir = 0;
static char bootPerangkat[9] = {0};

static uint32_t waktuTerakhirBaca = 0;
static uint32_t waktuTerakhirKirim = 0;
static uint32_t waktuTerakhirCekLink = 0;
static uint32_t waktuTerakhirKedip = 0;
static bool ledMenyala = false;

// ---------------------------------------------------------------------------
// UTILITAS UMUM
// ---------------------------------------------------------------------------

/**
 * Susun alamat MAC perangkat dalam bentuk teks "AA:BB:CC:DD:EE:FF".
 *
 * @return String alamat MAC.
 */
static String teksMac() {
  char hasil[18];
  snprintf(hasil, sizeof(hasil), "%02X:%02X:%02X:%02X:%02X:%02X",
           MAC_PERANGKAT[0], MAC_PERANGKAT[1], MAC_PERANGKAT[2],
           MAC_PERANGKAT[3], MAC_PERANGKAT[4], MAC_PERANGKAT[5]);
  return String(hasil);
}

/**
 * Ubah nilai float menjadi teks JSON. Nilai NaN ditulis sebagai null.
 *
 * @param nilai Nilai yang akan ditulis.
 * @param desimal Jumlah angka di belakang koma.
 * @return String angka atau "null".
 */
static String teksAngka(float nilai, uint8_t desimal) {
  if (isnan(nilai)) {
    return String("null");
  }
  return String(nilai, (unsigned int)desimal);
}

/**
 * Tulis catatan ke Serial Monitor dengan tanda waktu uptime.
 *
 * @param pesan Pesan yang akan dicetak.
 */
static void catat(const String &pesan) {
  Serial.print("[");
  Serial.print(millis() / 1000);
  Serial.print("s] ");
  Serial.println(pesan);
}

/**
 * Tampilkan ringkasan wiring dan konfigurasi saat perangkat baru menyala.
 */
static void cetakHeader() {
  Serial.println();
  Serial.println(F("===================================================="));
  Serial.println(F(" Monitoring Suhu & Kelembapan - ESP32 + W5500 + DHT11"));
  Serial.println(F("===================================================="));
  Serial.print(F(" Firmware  : "));
  Serial.println(F(VERSI_FIRMWARE));
  Serial.print(F(" Perangkat : "));
  Serial.println(F(NAMA_PERANGKAT));
  Serial.print(F(" MAC       : "));
  Serial.println(teksMac());
  Serial.print(F(" IP target : "));
  Serial.println(IP_PERANGKAT);
  Serial.print(F(" Server    : "));
  Serial.print(IP_SERVER);
  Serial.print(F(":"));
  Serial.println(PORT_SERVER);
  Serial.println(F(" Wiring    : W5500 SCK=18 MISO=19 MOSI=23 CS=5 VCC=3V3, DHT11 OUT=4"));
  Serial.println(F("===================================================="));
}

// ---------------------------------------------------------------------------
// JARINGAN
// ---------------------------------------------------------------------------

/**
 * Inisialisasi bus SPI dan modul Ethernet W5500 dengan IP statis.
 * Fungsi ini dipanggil sekali di setup().
 */
static void inisialisasiJaringan() {
  // SPI ESP32 memakai pin default VSPI: SCK=18, MISO=19, MOSI=23.
  SPI.begin();
  Ethernet.init(PIN_CS_ETHERNET);

  Ethernet.begin(MAC_PERANGKAT, IP_PERANGKAT, IP_DNS, IP_GATEWAY, IP_NETMASK);
  delay(500);

  EthernetHardwareStatus statusPerangkat = Ethernet.hardwareStatus();
  if (statusPerangkat == EthernetW5500) {
    catat(F("Modul W5500 terdeteksi."));
  } else if (statusPerangkat == EthernetW5100 || statusPerangkat == EthernetW5200) {
    catat(F("PERINGATAN: chip Ethernet terdeteksi bukan W5500, cek modul."));
  } else {
    catat(F("GAGAL: W5500 TIDAK TERDETEKSI."));
    catat(F("Cek kabel SPI: VCC=3V3, GND=GND, SCK=18, MISO=19, MOSI=23, CS=5."));
  }

  if (Ethernet.linkStatus() == LinkON) {
    catat(F("Kabel LAN terhubung (link ON)."));
  } else {
    catat(F("PERINGATAN: link LAN mati, cek kabel ke switch/POE."));
  }

  catat(String(F("IP perangkat: ")) + Ethernet.localIP().toString());
  serverHttp.begin();
  catat(String(F("HTTP server lokal aktif di port ")) + PORT_HTTP_LOKAL + ".");
}

/**
 * Periksa status link dan alamat IP secara berkala, lalu catat ke Serial.
 */
static void cekJaringanBerkala() {
  EthernetLinkStatus link = Ethernet.linkStatus();
  IPAddress ip = Ethernet.localIP();

  if (link != LinkON) {
    catat(F("PERINGATAN: link LAN terputus."));
    return;
  }
  if (ip == IPAddress(0, 0, 0, 0)) {
    catat(F("PERINGATAN: perangkat belum mendapat alamat IP."));
    return;
  }
  catat(String(F("Status jaringan normal, IP ")) + ip.toString());
}

// ---------------------------------------------------------------------------
// SENSOR DAN BUFFER
// ---------------------------------------------------------------------------

/**
 * Ambil slot buffer berdasarkan nomor id pembacaan.
 *
 * @param id Nomor urut pembacaan.
 * @return Pointer ke slot buffer, atau nullptr bila id di luar jangkauan buffer.
 */
static Pembacaan *slotBuffer(uint32_t id) {
  if (jumlahTersimpan == 0) {
    return nullptr;
  }
  uint32_t idTertua = (jumlahTersimpan > UKURAN_BUFFER) ? (idTerakhir - UKURAN_BUFFER + 1) : 1;
  if (id < idTertua || id > idTerakhir) {
    return nullptr;
  }
  return &bufferPembacaan[id % UKURAN_BUFFER];
}

/**
 * Ambil data pembacaan berdasarkan id-nya.
 *
 * @param id Nomor urut pembacaan.
 * @return Pointer ke pembacaan, atau nullptr bila tidak ditemukan.
 */
static Pembacaan *ambilPembacaan(uint32_t id) {
  Pembacaan *slot = slotBuffer(id);
  if (slot == nullptr || slot->id != id) {
    return nullptr;
  }
  return slot;
}

/**
 * Baca sensor DHT11 lalu simpan hasilnya ke buffer RAM.
 * Pembacaan yang gagal (NaN) hanya dihitung, tidak disimpan.
 */
static void bacaSensor() {
  float suhu = sensorDht.readTemperature();
  float kelembapan = sensorDht.readHumidity();
  bool valid = !isnan(suhu) && !isnan(kelembapan);

  if (!valid) {
    jumlahGagalBaca++;
    catat(String(F("Gagal baca DHT11 (percobaan gagal: ")) + jumlahGagalBaca + ").");
    return;
  }

  idTerakhir++;
  Pembacaan *slot = &bufferPembacaan[idTerakhir % UKURAN_BUFFER];
  slot->id = idTerakhir;
  slot->uptimeS = millis() / 1000;
  slot->suhu = suhu;
  slot->kelembapan = kelembapan;
  slot->valid = true;
  slot->terkirim = false;
  jumlahTersimpan++;

  catat(String(F("Pembacaan #")) + slot->id + ": suhu " + teksAngka(suhu, 1) +
        " C, kelembapan " + teksAngka(kelembapan, 1) + " %.");
}

// ---------------------------------------------------------------------------
// HTTP SERVER LOKAL (MODE SERVE / PULL)
// ---------------------------------------------------------------------------

/**
 * Kirim balasan HTTP sederhana lalu tutup koneksi.
 *
 * @param klien Koneksi klien yang akan dibalas.
 * @param kode Kode status HTTP (200/404).
 * @param tipeKonten Nilai header Content-Type.
 * @param isi Isi badan balasan.
 */
static void kirimResponHttp(EthernetClient &klien, uint16_t kode,
                            const char *tipeKonten, const String &isi) {
  klien.print(F("HTTP/1.1 "));
  klien.print(kode);
  klien.println(kode == 200 ? F(" OK") : F(" Not Found"));
  klien.print(F("Content-Type: "));
  klien.println(tipeKonten);
  klien.print(F("Content-Length: "));
  klien.println(isi.length());
  klien.println(F("Connection: close"));
  klien.println(F("Access-Control-Allow-Origin: *"));
  klien.println();
  klien.print(isi);
  delay(20);
  klien.stop();
}

/**
 * Baca baris pertama permintaan HTTP (request line) dengan batas waktu.
 *
 * @param klien Koneksi klien.
 * @param baris Penampung hasil baris permintaan.
 * @return true bila baris berhasil dibaca.
 */
static bool bacaBarisPermintaan(EthernetClient &klien, String &baris) {
  uint32_t mulai = millis();
  baris = "";

  while ((millis() - mulai) < 1500) {
    if (klien.available()) {
      char c = (char)klien.read();
      if (c == '\n') {
        return baris.length() > 0;
      }
      if (c != '\r') {
        baris += c;
        if (baris.length() > 160) {
          return true;
        }
      }
      continue;
    }
    if (!klien.connected()) {
      return false;
    }
    delay(1);
  }
  return false;
}

/**
 * Hitung nomor id tertua yang masih tersimpan di buffer RAM.
 *
 * @return Nomor id tertua, atau 0 bila buffer masih kosong.
 */
static uint32_t idTertuaDiBuffer() {
  if (jumlahTersimpan == 0) {
    return 0;
  }
  if (jumlahTersimpan > UKURAN_BUFFER) {
    return idTerakhir - UKURAN_BUFFER + 1;
  }
  return 1;
}

/**
 * Susun ringkasan status perangkat dalam format JSON.
 *
 * @return String JSON status perangkat.
 */
static String jsonStatusPerangkat() {
  String hasil = String(F("{\"perangkat\":\"")) + NAMA_PERANGKAT + "\"";
  hasil += String(F(",\"firmware\":\"")) + VERSI_FIRMWARE + "\"";
  hasil += String(F(",\"mac\":\"")) + teksMac() + "\"";
  hasil += String(F(",\"ip\":\"")) + Ethernet.localIP().toString() + "\"";
  hasil += String(F(",\"boot\":\"")) + bootPerangkat + "\"";
  hasil += ",\"uptime_s\":" + String(millis() / 1000);
  hasil += String(",\"link\":") + (Ethernet.linkStatus() == LinkON ? "true" : "false");
  hasil += String(",\"w5500_terdeteksi\":") + (Ethernet.hardwareStatus() == EthernetW5500 ? "true" : "false");
  hasil += ",\"id_terakhir\":" + String(idTerakhir);
  hasil += ",\"id_tertua_di_buffer\":" + String(idTertuaDiBuffer());
  hasil += ",\"jumlah_gagal_baca\":" + String(jumlahGagalBaca);
  hasil += ",\"jumlah_kirim_sukses\":" + String(jumlahKirimSukses);
  hasil += ",\"jumlah_kirim_gagal\":" + String(jumlahKirimGagal);
  hasil += ",\"kapasitas_buffer\":" + String(UKURAN_BUFFER);
  hasil += "}";
  return hasil;
}

/**
 * Susun satu pembacaan dalam format JSON.
 *
 * @param data Pembacaan yang akan diubah.
 * @return String JSON pembacaan.
 */
static String jsonPembacaan(const Pembacaan &data) {
  String hasil = String(F("{\"id\":")) + String(data.id);
  hasil += ",\"uptime_s\":" + String(data.uptimeS);
  hasil += ",\"suhu\":" + teksAngka(data.suhu, 1);
  hasil += ",\"kelembapan\":" + teksAngka(data.kelembapan, 1);
  hasil += String(",\"valid\":") + (data.valid ? "true" : "false");
  hasil += "}";
  return hasil;
}

/**
 * Layani satu permintaan HTTP dari server/PC.
 * Endpoint: "/", "/api/latest", "/api/buffer?since=ID", "/api/status".
 */
static void layaniPermintaanHttp() {
  EthernetClient klien = serverHttp.available();
  if (!klien) {
    return;
  }

  String barisPermintaan;
  if (!bacaBarisPermintaan(klien, barisPermintaan)) {
    klien.stop();
    return;
  }

  String jalur = barisPermintaan;
  int spasiPertama = jalur.indexOf(' ');
  if (spasiPertama > 0) {
    jalur = jalur.substring(spasiPertama + 1);
  }
  int spasiKedua = jalur.indexOf(' ');
  if (spasiKedua > 0) {
    jalur = jalur.substring(0, spasiKedua);
  }
  catat(String(F("Permintaan HTTP: ")) + jalur);

  if (jalur.startsWith(F("/api/status"))) {
    kirimResponHttp(klien, 200, "application/json", jsonStatusPerangkat());
    return;
  }

  if (jalur.startsWith(F("/api/latest"))) {
    Pembacaan *terakhir = ambilPembacaan(idTerakhir);
    String isi = String(F("{\"perangkat\":\"")) + NAMA_PERANGKAT + "\"";
    isi += String(F(",\"boot\":\"")) + bootPerangkat + "\"";
    isi += String(",\"uptime_s\":") + String(millis() / 1000);
    if (terakhir != nullptr) {
      isi += ",\"ada_data\":true,\"data\":" + jsonPembacaan(*terakhir);
    } else {
      isi += ",\"ada_data\":false,\"data\":null";
    }
    isi += "}";
    kirimResponHttp(klien, 200, "application/json", isi);
    return;
  }

  if (jalur.startsWith(F("/api/buffer"))) {
    uint32_t sejak = 0;
    int posisiSince = jalur.indexOf(F("since="));
    if (posisiSince >= 0) {
      sejak = (uint32_t)jalur.substring(posisiSince + 6).toInt();
    }

    String isi = String(F("{\"perangkat\":\"")) + NAMA_PERANGKAT + "\"";
    isi += String(F(",\"boot\":\"")) + bootPerangkat + "\"";
    isi += String(",\"id_terakhir\":") + String(idTerakhir);
    isi += ",\"data\":[";
    bool pertama = true;
    for (uint32_t id = idTertuaDiBuffer(); id <= idTerakhir; id++) {
      if (id <= sejak) {
        continue;
      }
      Pembacaan *data = ambilPembacaan(id);
      if (data == nullptr) {
        continue;
      }
      if (!pertama) {
        isi += ",";
      }
      isi += jsonPembacaan(*data);
      pertama = false;
    }
    isi += "]}";
    kirimResponHttp(klien, 200, "application/json", isi);
    return;
  }

  if (jalur == "/" || jalur.startsWith(F("/index"))) {
    Pembacaan *terakhir = ambilPembacaan(idTerakhir);
    String halaman = String(F("<html><head><meta charset='utf-8'><title>")) + NAMA_PERANGKAT;
    halaman += F("</title><meta http-equiv='refresh' content='10'></head>");
    halaman += F("<body style='font-family:Segoe UI,Arial;padding:24px;color:#0f172a'>");
    halaman += F("<h2 style='color:#0E9E8E;margin:0 0 4px'>Monitoring Suhu &amp; Kelembapan</h2>");
    halaman += String(F("<div style='margin-bottom:16px;color:#475569'>Perangkat: ")) + NAMA_PERANGKAT +
               " | IP: " + Ethernet.localIP().toString() +
               " | Uptime: " + String(millis() / 1000) + " s</div>";
    if (terakhir != nullptr) {
      halaman += String(F("<div style='font-size:42px;font-weight:700'>")) + teksAngka(terakhir->suhu, 1) + " &deg;C</div>";
      halaman += String(F("<div style='font-size:28px;color:#0E9E8E'>")) + teksAngka(terakhir->kelembapan, 1) + " % RH</div>";
      halaman += String(F("<div style='color:#64748b;margin-top:6px'>Pembacaan #")) + String(terakhir->id) + "</div>";
    } else {
      halaman += F("<div style='color:#b91c1c'>Belum ada data pembacaan yang valid.</div>");
    }
    halaman += F("<ul style='margin-top:20px'><li><a href='/api/latest'>/api/latest</a></li>");
    halaman += F("<li><a href='/api/buffer'>/api/buffer</a></li><li><a href='/api/status'>/api/status</a></li></ul>");
    halaman += F("</body></html>");
    kirimResponHttp(klien, 200, "text/html; charset=utf-8", halaman);
    return;
  }

  kirimResponHttp(klien, 404, "application/json", F("{\"error\":\"jalur tidak dikenal\"}"));
}

// ---------------------------------------------------------------------------
// PUSH KE SERVER
// ---------------------------------------------------------------------------

/**
 * Susun payload JSON untuk satu pembacaan.
 *
 * @param data Pembacaan yang akan dikirim.
 * @return String payload JSON.
 */
static String payloadPembacaan(const Pembacaan &data) {
  String isi = String(F("{\"perangkat\":\"")) + NAMA_PERANGKAT + "\"";
  isi += String(F(",\"tipe\":\"reading\""));
  isi += String(F(",\"mac\":\"")) + teksMac() + "\"";
  isi += String(F(",\"ip\":\"")) + Ethernet.localIP().toString() + "\"";
  isi += String(F(",\"boot\":\"")) + bootPerangkat + "\"";
  isi += ",\"id\":" + String(data.id);
  isi += ",\"uptime_s\":" + String(data.uptimeS);
  isi += ",\"suhu\":" + teksAngka(data.suhu, 1);
  isi += ",\"kelembapan\":" + teksAngka(data.kelembapan, 1);
  isi += String(",\"valid\":") + (data.valid ? "true" : "false");
  isi += String(F(",\"firmware\":\"")) + VERSI_FIRMWARE + "\"";
  isi += "}";
  return isi;
}

/**
 * Susun payload JSON heartbeat, dipakai saat sensor belum menghasilkan data valid.
 *
 * @return String payload JSON heartbeat.
 */
static String payloadHeartbeat() {
  String isi = String(F("{\"perangkat\":\"")) + NAMA_PERANGKAT + "\"";
  isi += String(F(",\"tipe\":\"heartbeat\""));
  isi += String(F(",\"mac\":\"")) + teksMac() + "\"";
  isi += String(F(",\"ip\":\"")) + Ethernet.localIP().toString() + "\"";
  isi += String(F(",\"boot\":\"")) + bootPerangkat + "\"";
  isi += ",\"uptime_s\":" + String(millis() / 1000);
  isi += ",\"jumlah_gagal_baca\":" + String(jumlahGagalBaca);
  isi += ",\"id_terakhir\":" + String(idTerakhir);
  isi += String(F(",\"firmware\":\"")) + VERSI_FIRMWARE + "\"";
  isi += "}";
  return isi;
}

/**
 * Kirim satu payload JSON ke server dengan HTTP POST.
 *
 * @param payload Isi JSON.
 * @param kodeBalasan Penampung kode status HTTP dari server (0 bila gagal konek).
 * @return true bila server membalas dengan kode 2xx.
 */
static bool kirimKeServer(const String &payload, uint16_t &kodeBalasan) {
  EthernetClient klien;
  klien.setConnectionTimeout(2000);
  kodeBalasan = 0;

  if (!klien.connect(IP_SERVER, PORT_SERVER)) {
    klien.stop();
    return false;
  }

  klien.println(F("POST /api/data HTTP/1.1"));
  klien.print(F("Host: "));
  klien.print(IP_SERVER);
  klien.print(F(":"));
  klien.println(PORT_SERVER);
  klien.println(F("Content-Type: application/json"));
  klien.print(F("Content-Length: "));
  klien.println(payload.length());
  klien.println(F("Connection: close"));
  klien.println();
  klien.print(payload);

  uint32_t mulai = millis();
  String baris = "";
  bool barisPertamaSelesai = false;
  while (!barisPertamaSelesai && (millis() - mulai) < 3000) {
    while (klien.available()) {
      char c = (char)klien.read();
      if (c == '\n') {
        barisPertamaSelesai = true;
        break;
      }
      if (c != '\r') {
        baris += c;
      }
    }
    if (!barisPertamaSelesai && !klien.connected()) {
      break;
    }
    delay(1);
  }

  if (baris.startsWith(F("HTTP/"))) {
    int spasi = baris.indexOf(' ');
    if (spasi > 0) {
      kodeBalasan = (uint16_t)baris.substring(spasi + 1, spasi + 4).toInt();
    }
  }

  klien.stop();
  return kodeBalasan >= 200 && kodeBalasan < 300;
}

/**
 * Kirim pembacaan yang belum terkirim, maksimum MAKS_KIRIM_PER_SIKLUS per siklus.
 * Bila tidak ada data yang dikirim dan sudah lewat INTERVAL_HEARTBEAT_MS,
 * kirim heartbeat supaya server tetap tahu perangkat hidup.
 */
static void kirimDataBelumTerkirim() {
  uint16_t dikirimSiklusIni = 0;

  for (uint32_t id = idTertuaDiBuffer(); id <= idTerakhir && dikirimSiklusIni < MAKS_KIRIM_PER_SIKLUS; id++) {
    Pembacaan *data = ambilPembacaan(id);
    if (data == nullptr || data->terkirim) {
      continue;
    }

    uint16_t kode = 0;
    if (!kirimKeServer(payloadPembacaan(*data), kode)) {
      jumlahKirimGagal++;
      catat(String(F("Gagal kirim #")) + data->id + " (kode " + kode + "), dicoba lagi siklus berikutnya.");
      return;
    }

    data->terkirim = true;
    jumlahKirimSukses++;
    dikirimSiklusIni++;
    waktuKirimSuksesTerakhir = millis();
    catat(String(F("Terkirim #")) + data->id + " ke server.");
  }

  if (dikirimSiklusIni > 0) {
    return;
  }

  bool perluHeartbeat = (waktuKirimSuksesTerakhir == 0) ||
                        ((millis() - waktuKirimSuksesTerakhir) >= INTERVAL_HEARTBEAT_MS);
  if (!perluHeartbeat) {
    return;
  }

  uint16_t kode = 0;
  if (kirimKeServer(payloadHeartbeat(), kode)) {
    jumlahKirimSukses++;
    waktuKirimSuksesTerakhir = millis();
    catat(F("Heartbeat terkirim ke server."));
  } else {
    jumlahKirimGagal++;
    catat(String(F("Heartbeat gagal terkirim (kode ")) + kode + ").");
  }
}

/**
 * Kedipkan LED status: cepat bila jaringan bermasalah, lambat bila normal.
 *
 * @param sekarang Nilai millis() saat ini.
 */
static void kedipkanLed(uint32_t sekarang) {
  uint32_t jeda = (Ethernet.linkStatus() == LinkON) ? 1000 : 200;
  if ((sekarang - waktuTerakhirKedip) < jeda) {
    return;
  }
  waktuTerakhirKedip = sekarang;
  ledMenyala = !ledMenyala;
  digitalWrite(PIN_LED_STATUS, ledMenyala ? HIGH : LOW);
}

// ---------------------------------------------------------------------------
// SETUP DAN LOOP
// ---------------------------------------------------------------------------

void setup() {
  pinMode(PIN_LED_STATUS, OUTPUT);
  digitalWrite(PIN_LED_STATUS, LOW);

  Serial.begin(115200);
  delay(300);
  cetakHeader();

  snprintf(bootPerangkat, sizeof(bootPerangkat), "%08X", (unsigned int)esp_random());
  catat(String(F("Sesi boot: ")) + bootPerangkat);

  inisialisasiJaringan();

  sensorDht.begin();
  catat(F("Sensor DHT11 siap pada GPIO4."));

  // Beri waktu sensor stabil setelah power-on, lalu ambil pembacaan pertama
  // supaya hasilnya langsung terlihat di Serial Monitor saat commissioning.
  delay(1200);
  bacaSensor();
}

void loop() {
  uint32_t sekarang = millis();

  layaniPermintaanHttp();

  if ((sekarang - waktuTerakhirBaca) >= INTERVAL_BACA_MS) {
    waktuTerakhirBaca = sekarang;
    bacaSensor();
  }

  if ((sekarang - waktuTerakhirKirim) >= INTERVAL_KIRIM_MS) {
    waktuTerakhirKirim = sekarang;
    kirimDataBelumTerkirim();
  }

  if ((sekarang - waktuTerakhirCekLink) >= INTERVAL_CEK_LINK_MS) {
    waktuTerakhirCekLink = sekarang;
    cekJaringanBerkala();
  }

  kedipkanLed(sekarang);
}





