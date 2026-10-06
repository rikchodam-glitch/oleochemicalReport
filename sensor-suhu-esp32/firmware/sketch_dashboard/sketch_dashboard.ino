// -----------------------------------------------------------------------------
// Salinan sketsa dashboard yang dipakai perangkat ruang panel (sebagai acuan).
//
// Perangkat ini hanya menyajikan NILAI TERKINI:
//   - port 80   : halaman HTML yang memuat "Suhu: xx.xx" dan "Kelembaban: xx.xx"
//   - port 5000 : satu baris JSON, contoh
//                 {"device":"ESP32_W5500","ip":"192.168.41.240","temperature":27.50,"humidity":64.00,"uptime_sec":746}
//
// Catatan: layanan TCP port 5000 baru membalas SETELAH klien mengirim satu byte
// (lihat handleTCPClients()). Aplikasi Laravel sudah menyesuaikan diri dengan
// perilaku itu (App\Services\PanelPerangkatReader::bacaJsonTcp()).
//
// Di halaman Monitoring Ruang Panel, ruangan dengan sketsa ini dipilih
// "Otomatis", "JSON TCP sketch dashboard", atau "Dashboard HTML sketch".
// Sketsa ini TIDAK punya buffer pembacaan dan TIDAK punya endpoint /api/status,
// jadi perangkat dikenali lewat port TCP 5000 atau dashboard port 80.
// -----------------------------------------------------------------------------

#include <SPI.h>
#include <Ethernet.h>
#include <DHT.h>

// solusi arduino lama
class ESP32EthernetServer : public EthernetServer {
public:
  ESP32EthernetServer(uint16_t port) : EthernetServer(port) {}

  // Menambahkan default value "= 0" agar bisa dipanggil tanpa argumen: begin()
  void begin(uint16_t port = 0) override {
    (void)port;
    EthernetServer::begin();
  }
};

// --- Pemetaan Pin ESP32 Sesuai Gambar Skema ---
#define PIN_W5500_CS   5   // CS / nSS
#define PIN_W5500_RST  17  // RST / nRESET
#define PIN_W5500_SCK  18  // SCK / SCLK
#define PIN_W5500_MISO 19  // MISO
#define PIN_W5500_MOSI 23  // MOSI

// Pin Data Sensor DHT
#define DHTPIN         27  // Pin Data DHT
#define DHTTYPE        DHT22 // Ubah ke DHT22 jika memakai DHT22

DHT dht(DHTPIN, DHTTYPE);

// --- Konfigurasi Network (IP Statis) ---
byte mac[] = { 0xDE, 0xAD, 0xBE, 0xEF, 0xFE, 0xED };
IPAddress ip(192, 168, 41, 240);
IPAddress gateway(192, 168, 41, 254);
IPAddress subnet(255, 255, 255, 0);

// Inisialisasi Server Menggunakan Kelas Wrapper Khusus ESP32
ESP32EthernetServer webServer(80);     // HTTP Web Server (Port 80)
ESP32EthernetServer tcpServer(5000);   // TCP Data JSON Server (Port 5000)

// Variabel Global Data Sensor
float humidity = 0.0;
float temperature = 0.0;
unsigned long lastSensorRead = 0;
const long readInterval = 2000; // Pembacaan sensor setiap 2 detik

// Deklarasi Fungsi
String buildJSONData();
void handleWebClients();
void handleTCPClients();

void setup() {
  Serial.begin(9600);
  delay(100);
  Serial.println("\n==========================================");
  Serial.println("  ESP32 + Ethernet W5500 + DHT Reader     ");
  Serial.println("==========================================");

  // Inisialisasi Sensor DHT
  dht.begin();

  // Reset Hardware W5500 via GPIO ESP32
  pinMode(PIN_W5500_RST, OUTPUT);
  digitalWrite(PIN_W5500_RST, LOW);
  delay(100);
  digitalWrite(PIN_W5500_RST, HIGH);
  delay(200);

  // Konfigurasi Pin Bus SPI ESP32
  SPI.begin(PIN_W5500_SCK, PIN_W5500_MISO, PIN_W5500_MOSI, PIN_W5500_CS);
  Ethernet.init(PIN_W5500_CS);

  // Inisialisasi Network Ethernet IP Statis
  Ethernet.begin(mac, ip, gateway, gateway, subnet);

  // Validasi Hardware W5500
  if (Ethernet.hardwareStatus() == EthernetNoHardware) {
    Serial.println("[ERROR] Modul W5500 tidak ditemukan! Periksa sambungan kabel SPI.");
    while (true) {
      delay(500);
    }
  }

  if (Ethernet.linkStatus() == LinkOFF) {
    Serial.println("[WARNING] Kabel Ethernet LAN belum terhubung.");
  } else {
    Serial.println("[OK] Kabel Ethernet LAN Terhubung.");
  }

  // Jalankan Server (Sekarang berjalan tanpa error)
  webServer.begin();
  tcpServer.begin();

  Serial.print("[STATUS] IP Address ESP32: ");
  Serial.println(Ethernet.localIP());
  Serial.println("[STATUS] Web Server siap di port 80 (http://192.168.41.240)");
  Serial.println("[STATUS] TCP JSON Data Server siap di port 5000");
  Serial.println("==========================================\n");
}

void loop() {
  unsigned long currentMillis = millis();

  // 1. Pembacaan Sensor DHT Periodik (Non-Blocking)
  if (currentMillis - lastSensorRead >= readInterval) {
    lastSensorRead = currentMillis;

    float h = dht.readHumidity();
    float t = dht.readTemperature();

    if (!isnan(h) && !isnan(t)) {
      humidity = h;
      temperature = t;
      Serial.printf("[Serial Log ESP32] Suhu: %.2f °C | Kelembaban: %.2f %%\n", temperature, humidity);
    } else {
      Serial.println("[Serial Log ESP32] Gagal membaca data dari sensor DHT!");
    }
  }

  // 2. Layani Client Web HTTP (Port 80)
  handleWebClients();

  // 3. Layani Client Komunikasi TCP Data JSON (Port 5000)
  handleTCPClients();
}


// Fungsi Membuat Data JSON
String buildJSONData() {
  String json = "{";
  json += "\"device\":\"ESP32_W5500\",";
  json += "\"ip\":\"192.168.41.240\",";
  json += "\"temperature\":" + String(isnan(temperature) ? 0.0 : temperature, 2) + ",";
  json += "\"humidity\":" + String(isnan(humidity) ? 0.0 : humidity, 2) + ",";
  json += "\"uptime_sec\":" + String(millis() / 1000);
  json += "}";
  return json;
}

// Handler Web AP Point / Web Dashboard
void handleWebClients() {
  EthernetClient client = webServer.available();
  if (client) {
    boolean currentLineIsBlank = true;
    while (client.connected()) {
      if (client.available()) {
        char c = client.read();
        if (c == '\n' && currentLineIsBlank) {
          client.println("HTTP/1.1 200 OK");
          client.println("Content-Type: text/html");
          client.println("Connection: close");
          client.println("Refresh: 3");
          client.println();

          client.println("<!DOCTYPE HTML>");
          client.println("<html>");
          client.println("<head><title>MONITORING SUHU DASBOARD</title></head>");
          client.println("<body style='font-family: Arial; text-align: center; background-color: #f4f4f9; padding-top: 30px;'>");
          client.println("<h2>MONITORING SUHU RUANG SERVER MAIN OFFICE</h2>");
          client.println("<p>IP Address Device: <b>192.168.41.240</b></p>");
          client.println("<div style='display: inline-block; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1);'>");

          client.print("<h3 style='color: #e74c3c;'>Suhu: ");
          client.print(temperature, 2);
          client.println(" &deg;C</h3>");

          client.print("<h3 style='color: #3498db;'>Kelembaban: ");
          client.print(humidity, 2);
          client.println(" %</h3>");

          client.println("</div>");
          client.println("<br><br><p><small>KARYA ANAK BANGSA--Tarik Data JSON TCP via Port 5000</small></p>");
          client.println("</body>");
          client.println("</html>");
          break;
        }
        if (c == '\n') {
          currentLineIsBlank = true;
        } else if (c != '\r') {
          currentLineIsBlank = false;
        }
      }
    }
    delay(1);
    client.stop();
  }
}

// Handler Socket Server TCP (JSON Response)
void handleTCPClients() {
  EthernetClient client = tcpServer.available();
  if (client) {
    while (client.connected()) {
      if (client.available()) {
        String jsonPayload = buildJSONData();
        client.println(jsonPayload);
        client.stop();
      }
    }
  }
}

