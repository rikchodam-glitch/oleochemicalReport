"""simulator_perangkat.py

Simulator perangkat ESP32 untuk menguji server.py dan dashboard tanpa
perangkat keras. Simulator ini meniru endpoint firmware:

    GET /api/status            -> status perangkat
    GET /api/buffer?since=ID   -> daftar pembacaan dengan id lebih besar dari ID
    GET /api/latest            -> pembacaan terakhir

Menjalankan (port uji 8099, kirim juga lewat PUSH bila diminta):
    .venv\\Scripts\\python.exe tools\\simulator_perangkat.py --port 8099
    .venv\\Scripts\\python.exe tools\\simulator_perangkat.py --port 8099 --push

Catatan: port 8080 dipakai Laragon nginx di komputer ini, jadi simulator
memakai 8099 supaya tidak bentrok.

Setelah simulator jalan, ubah sementara entri perangkat di config.json menjadi
ip 127.0.0.1 dan port 8099 supaya server menarik data dari simulator.
"""

from __future__ import annotations

import argparse
import json
import random
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

BUFFER_MAKS = 60
INTERVAL_DETIK = 10
KONDISI: dict = {
    "boot": "%08X" % random.getrandbits(32),
    "mulai": time.time(),
    "id_terakhir": 0,
    "buffer": [],
    "kunci": threading.Lock(),
}


def uptime() -> int:
    """Hitung uptime simulator dalam detik.

    Returns:
        Jumlah detik sejak simulator dijalankan.
    """
    return int(time.time() - KONDISI["mulai"])


def tambah_pembacaan() -> dict:
    """Buat satu pembacaan baru dan simpan ke buffer simulator.

    Returns:
        dict pembacaan yang baru dibuat.
    """
    with KONDISI["kunci"]:
        KONDISI["id_terakhir"] += 1
        data = {
            "id": KONDISI["id_terakhir"],
            "uptime_s": uptime(),
            "suhu": round(27.0 + random.uniform(-1.5, 2.5), 1),
            "kelembapan": round(60.0 + random.uniform(-8.0, 8.0), 1),
            "valid": True,
        }
        KONDISI["buffer"].append(data)
        if len(KONDISI["buffer"]) > BUFFER_MAKS:
            KONDISI["buffer"] = KONDISI["buffer"][-BUFFER_MAKS:]
        return data


def status_perangkat(nama: str) -> dict:
    """Susun payload status seperti /api/status pada firmware asli.

    Args:
        nama: Nama perangkat yang dilaporkan.

    Returns:
        dict status perangkat.
    """
    return {
        "perangkat": nama,
        "firmware": "simulator-1.0",
        "mac": "02:00:5A:41:00:A0",
        "ip": "127.0.0.1",
        "boot": KONDISI["boot"],
        "uptime_s": uptime(),
        "link": True,
        "w5500_terdeteksi": True,
        "id_terakhir": KONDISI["id_terakhir"],
        "jumlah_gagal_baca": 0,
        "jumlah_kirim_sukses": KONDISI["id_terakhir"],
        "jumlah_kirim_gagal": 0,
        "kapasitas_buffer": BUFFER_MAKS,
    }


class PenanganPermintaan(BaseHTTPRequestHandler):
    """Handler HTTP yang meniru endpoint firmware ESP32."""

    nama_perangkat = "sensor-suhu-01"

    def do_GET(self) -> None:  # noqa: N802 - nama method mengikuti BaseHTTPRequestHandler
        """Layani permintaan GET dari server monitoring."""
        jalur = urlparse(self.path)
        kueri = parse_qs(jalur.query)

        if jalur.path == "/api/status":
            self.kirim_json(status_perangkat(self.nama_perangkat))
            return

        if jalur.path == "/api/buffer":
            sejak = int(kueri.get("since", ["0"])[0] or 0)
            with KONDISI["kunci"]:
                data = [item for item in KONDISI["buffer"] if item["id"] > sejak]
            self.kirim_json(
                {
                    "perangkat": self.nama_perangkat,
                    "boot": KONDISI["boot"],
                    "id_terakhir": KONDISI["id_terakhir"],
                    "data": data,
                }
            )
            return

        if jalur.path == "/api/latest":
            with KONDISI["kunci"]:
                terakhir = KONDISI["buffer"][-1] if KONDISI["buffer"] else None
            self.kirim_json(
                {
                    "perangkat": self.nama_perangkat,
                    "boot": KONDISI["boot"],
                    "uptime_s": uptime(),
                    "ada_data": terakhir is not None,
                    "data": terakhir,
                }
            )
            return

        if jalur.path in ("/", "/index.html"):
            isi = (
                "<html><body style='font-family:Segoe UI,Arial;padding:20px'>"
                "<h2>Simulator perangkat ESP32</h2>"
                f"<p>Perangkat: {self.nama_perangkat} | boot {KONDISI['boot']} | "
                f"uptime {uptime()} detik | id terakhir {KONDISI['id_terakhir']}</p>"
                "<ul><li><a href='/api/status'>/api/status</a></li>"
                "<li><a href='/api/buffer'>/api/buffer</a></li>"
                "<li><a href='/api/latest'>/api/latest</a></li></ul>"
                "</body></html>"
            )
            self.kirim_isi(200, isi.encode("utf-8"), "text/html; charset=utf-8")
            return

        self.kirim_json({"error": "jalur tidak dikenal"}, kode=404)

    def kirim_json(self, data: dict, kode: int = 200) -> None:
        """Kirim balasan berisi JSON.

        Args:
            data: Isi yang dikirim.
            kode: Kode status HTTP.
        """
        self.kirim_isi(kode, json.dumps(data).encode("utf-8"), "application/json")

    def kirim_isi(self, kode: int, isi: bytes, tipe: str) -> None:
        """Kirim balasan HTTP lengkap.

        Args:
            kode: Kode status HTTP.
            isi: Badan balasan dalam byte.
            tipe: Nilai header Content-Type.
        """
        self.send_response(kode)
        self.send_header("Content-Type", tipe)
        self.send_header("Content-Length", str(len(isi)))
        self.end_headers()
        self.wfile.write(isi)

    def log_message(self, format: str, *args) -> None:  # noqa: A002 - mengikuti tanda tangan induk
        """Senapkan log bawaan supaya konsol tidak penuh.

        Args:
            format: Format pesan log.
            *args: Argumen pesan log.
        """
        return


def pekerja_generator() -> None:
    """Buat pembacaan baru secara berkala, meniru pembacaan sensor tiap 10 detik."""
    while True:
        tambah_pembacaan()
        time.sleep(INTERVAL_DETIK)


def pekerja_push(server_url: str) -> None:
    """Kirim setiap pembacaan baru ke endpoint PUSH server monitoring.

    Args:
        server_url: Alamat lengkap endpoint POST server, misal
            http://192.168.41.74:5000/api/data
    """
    terkirim = 0
    while True:
        with KONDISI["kunci"]:
            belum = [item for item in KONDISI["buffer"] if item["id"] > terkirim]

        for item in belum:
            muatan = dict(item)
            muatan.update(
                {
                    "perangkat": "sensor-suhu-01",
                    "tipe": "reading",
                    "mac": "02:00:5A:41:00:A0",
                    "ip": "127.0.0.1",
                    "boot": KONDISI["boot"],
                    "firmware": "simulator-1.0",
                }
            )
            permintaan = urllib.request.Request(
                server_url,
                data=json.dumps(muatan).encode("utf-8"),
                headers={"Content-Type": "application/json"},
                method="POST",
            )
            try:
                with urllib.request.urlopen(permintaan, timeout=5) as balasan:
                    balasan.read()
                terkirim = item["id"]
                print(f"[simulator] PUSH id {item['id']} terkirim ke {server_url}")
            except (urllib.error.URLError, urllib.error.HTTPError, OSError) as galat:
                print(f"[simulator] PUSH id {item['id']} gagal: {galat}")
                break

        time.sleep(INTERVAL_DETIK)


def main() -> int:
    """Jalankan simulator sesuai argumen baris perintah.

    Returns:
        Kode keluar proses (0 bila berhenti normal).
    """
    parser = argparse.ArgumentParser(description="Simulator perangkat ESP32 untuk uji server.")
    parser.add_argument("--port", type=int, default=8099, help="Port HTTP simulator (bawaan 8099).")
    parser.add_argument("--nama", default="sensor-suhu-01", help="Nama perangkat yang dilaporkan.")
    parser.add_argument("--push", action="store_true", help="Kirim juga lewat jalur PUSH ke server.")
    parser.add_argument(
        "--server-url",
        default="http://127.0.0.1:5000/api/data",
        help="Alamat endpoint PUSH pada server monitoring.",
    )
    argumen = parser.parse_args()

    PenanganPermintaan.nama_perangkat = argumen.nama
    tambah_pembacaan()

    threading.Thread(target=pekerja_generator, daemon=True).start()
    if argumen.push:
        threading.Thread(target=pekerja_push, args=(argumen.server_url,), daemon=True).start()
        print(f"[simulator] Jalur PUSH aktif ke {argumen.server_url}")

    peladen = ThreadingHTTPServer(("0.0.0.0", argumen.port), PenanganPermintaan)
    print(f"[simulator] Perangkat {argumen.nama} berjalan di port {argumen.port} (boot {KONDISI['boot']}).")
    print("[simulator] Tekan Ctrl+C untuk berhenti.")
    try:
        peladen.serve_forever()
    except KeyboardInterrupt:
        print("\n[simulator] Dihentikan.")
    finally:
        peladen.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

