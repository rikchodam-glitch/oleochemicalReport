"""test_receiver.py

Penerima HTTP sangat sederhana (hanya memakai pustaka standar Python) untuk
memastikan ESP32 benar-benar bisa mengirim data ke komputer server, tanpa
membutuhkan Flask dan tanpa menulis apa pun ke database.

Kegunaan utamanya: memisahkan masalah firewall dari masalah aplikasi.
Bila ESP32 sudah menyala tetapi server.py tidak pernah menerima data, jalankan
berkas ini. Kalau di sini pun tidak ada data masuk, berarti penyebabnya bukan
server.py, melainkan jaringan atau firewall.

Menjalankan (port 5000, sama seperti server.py):
    .venv\\Scripts\\python.exe tools\\test_receiver.py

Penerima ini menampilkan setiap permintaan yang masuk ke layar dan membalas
{"status":"ok"} supaya ESP32 menganggap pengiriman berhasil.
"""

from __future__ import annotations

import argparse
import json
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

JUMLAH = {"terima": 0}


class PenerimaUji(BaseHTTPRequestHandler):
    """Handler sederhana untuk mencatat data yang masuk dari ESP32."""

    def do_POST(self) -> None:  # noqa: N802 - nama method mengikuti BaseHTTPRequestHandler
        """Catat dan balas semua permintaan POST yang masuk."""
        panjang = int(self.headers.get("Content-Length", 0) or 0)
        mentah = self.rfile.read(panjang).decode("utf-8", errors="replace") if panjang else ""
        JUMLAH["terima"] += 1

        waktu = datetime.now().strftime("%H:%M:%S")
        print(f"[{waktu}] POST dari {self.client_address[0]} ke {self.path} (ke-{JUMLAH['terima']})")
        try:
            data = json.loads(mentah) if mentah else {}
            if data.get("tipe") == "heartbeat":
                print(f"          heartbeat: perangkat {data.get('perangkat')}, uptime {data.get('uptime_s')} detik")
            else:
                print(
                    "          data: perangkat {perangkat}, id {id}, suhu {suhu} C, "
                    "kelembapan {kelembapan} %".format(**data)
                )
        except (ValueError, KeyError):
            print(f"          isi mentah: {mentah[:200]}")

        self.kirim_json({"status": "ok", "diterima": True})

    def do_GET(self) -> None:  # noqa: N802 - nama method mengikuti BaseHTTPRequestHandler
        """Balas permintaan GET dengan ringkasan jumlah data yang diterima."""
        self.kirim_json({"status": "ok", "jumlah_diterima": JUMLAH["terima"]})

    def kirim_json(self, data: dict) -> None:
        """Kirim balasan JSON lalu tutup koneksi.

        Args:
            data: Isi JSON yang dikirim.
        """
        isi = json.dumps(data).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(isi)))
        self.end_headers()
        self.wfile.write(isi)

    def log_message(self, format: str, *args) -> None:  # noqa: A002 - mengikuti tanda tangan induk
        """Senapkan log bawaan supaya konsol hanya menampilkan data yang masuk.

        Args:
            format: Format pesan log.
            *args: Argumen pesan log.
        """
        return


def main() -> int:
    """Jalankan penerima uji pada port yang diminta.

    Returns:
        Kode keluar proses (0 bila berhenti normal).
    """
    parser = argparse.ArgumentParser(description="Penerima uji untuk memeriksa jalur PUSH ESP32.")
    parser.add_argument("--port", type=int, default=5000, help="Port yang didengarkan (bawaan 5000).")
    argumen = parser.parse_args()

    print("=" * 72)
    print("  PENERIMA UJI - memastikan ESP32 bisa mengirim data ke komputer ini")
    print("=" * 72)
    print(f"  Mendengarkan di port {argumen.port}. Biarkan jendela ini terbuka.")
    print("  Matikan server.py lebih dulu supaya port 5000 tidak dipakai bersamaan.")
    print("  Tekan Ctrl+C untuk berhenti.")
    print("=" * 72)

    peladen = ThreadingHTTPServer(("0.0.0.0", argumen.port), PenerimaUji)
    try:
        peladen.serve_forever()
    except KeyboardInterrupt:
        print("\nDihentikan.")
    finally:
        peladen.server_close()

    if JUMLAH["terima"] == 0:
        print("Tidak ada data yang masuk: periksa firewall port, kabel LAN, dan IP server di firmware.")
    else:
        print(f"Total {JUMLAH['terima']} data diterima: jalur PUSH dari ESP32 bekerja.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
