"""check_connectivity.py

Periksa konektivitas bertahap dari komputer server ke perangkat ESP32 dan
sebaliknya. Dipakai saat commissioning setelah firmware di-upload ke board.

Menjalankan:
    .venv\\Scripts\\python.exe tools\\check_connectivity.py

Opsi:
    --ip 192.168.41.240      IP perangkat ESP32 (bawaan dari config.json)
    --server 192.168.41.74   IP komputer server (bawaan dari config.json)
    --port 5000              port server monitoring (bawaan dari config.json)
    --tanpa-push             lewati pengujian jalur PUSH ke server
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

AKAR = Path(__file__).resolve().parent.parent
BERKAS_CONFIG = AKAR / "config.json"

BARIS_HASIL: list[tuple[str, str, str]] = []


def catat(golongan: str, judul: str, keterangan: str = "") -> None:
    """Simpan dan cetak satu baris hasil pemeriksaan.

    Args:
        golongan: "OK", "GAGAL", atau "INFO".
        judul: Judul singkat pemeriksaan.
        keterangan: Penjelasan tambahan (opsional).
    """
    BARIS_HASIL.append((golongan, judul, keterangan))
    print(f"  [{golongan:5}] {judul}")
    if keterangan:
        print(f"          {keterangan}")


def muat_config() -> dict:
    """Baca config.json bila ada.

    Returns:
        dict konfigurasi, atau dict kosong bila berkas tidak ditemukan.
    """
    if not BERKAS_CONFIG.exists():
        return {}
    # utf-8-sig dipakai supaya berkas dengan BOM tetap terbaca.
    with BERKAS_CONFIG.open(encoding="utf-8-sig") as berkas:
        try:
            return json.load(berkas)
        except json.JSONDecodeError:
            return {}


def ping(alamat: str) -> tuple[bool, str]:
    """Kirim ping ke alamat tujuan.

    Args:
        alamat: IP tujuan.

    Returns:
        tuple (berhasil, ringkasan keluaran).
    """
    perintah = ["ping", "-n", "2", "-w", "1500", alamat]
    try:
        proses = subprocess.run(perintah, capture_output=True, text=True, timeout=15)
    except (subprocess.SubprocessError, OSError) as galat:
        return False, f"Perintah ping gagal dijalankan: {galat}"

    keluaran = (proses.stdout or "") + (proses.stderr or "")
    baris_balasan = [baris.strip() for baris in keluaran.splitlines() if "TTL=" in baris]
    if proses.returncode == 0 and baris_balasan:
        return True, baris_balasan[0]
    return False, "Tidak ada balasan ping."


def ambil_json(url: str, batas_detik: float = 4.0) -> tuple[bool, object]:
    """Panggil URL dengan metode GET dan uraikan JSON-nya.

    Args:
        url: Alamat yang dipanggil.
        batas_detik: Batas waktu permintaan.

    Returns:
        tuple (berhasil, data dict atau pesan galat).
    """
    try:
        with urllib.request.urlopen(url, timeout=batas_detik) as balasan:
            return True, json.loads(balasan.read().decode("utf-8", errors="replace"))
    except urllib.error.HTTPError as galat:
        return False, f"HTTP {galat.code}"
    except (urllib.error.URLError, TimeoutError, ValueError, OSError) as galat:
        return False, f"Tidak bisa dihubungi ({galat})"


def kirim_json(url: str, data: dict, batas_detik: float = 5.0) -> tuple[bool, object]:
    """Kirim data JSON dengan metode POST.

    Args:
        url: Alamat tujuan.
        data: Isi JSON yang dikirim.
        batas_detik: Batas waktu permintaan.

    Returns:
        tuple (berhasil, data balasan atau pesan galat).
    """
    isi = json.dumps(data).encode("utf-8")
    permintaan = urllib.request.Request(
        url, data=isi, headers={"Content-Type": "application/json"}, method="POST"
    )
    try:
        with urllib.request.urlopen(permintaan, timeout=batas_detik) as balasan:
            return True, json.loads(balasan.read().decode("utf-8", errors="replace"))
    except urllib.error.HTTPError as galat:
        return False, f"HTTP {galat.code}"
    except (urllib.error.URLError, TimeoutError, ValueError, OSError) as galat:
        return False, f"Tidak bisa dihubungi ({galat})"


def jalankan_pemeriksaan(ip_perangkat: str, ip_server: str, port_server: int, uji_push: bool) -> bool:
    """Jalankan seluruh pemeriksaan konektivitas secara berurutan.

    Args:
        ip_perangkat: IP perangkat ESP32.
        ip_server: IP komputer server monitoring.
        port_server: Port server monitoring.
        uji_push: True bila jalur PUSH ikut diuji.

    Returns:
        True bila tidak ada pemeriksaan penting yang gagal.
    """
    print()
    print("=" * 72)
    print("  PEMERIKSAAN KONEKTIVITAS SENSOR SUHU & KELEMBAPAN")
    print("=" * 72)
    print(f"  Perangkat ESP32 : {ip_perangkat}")
    print(f"  Server          : {ip_server}:{port_server}")
    print("=" * 72)

    print("\n1. Konektivitas jaringan ke perangkat ESP32")
    berhasil_ping, catatan_ping = ping(ip_perangkat)
    if berhasil_ping:
        catat("OK", "Perangkat menjawab ping", catatan_ping)
    else:
        catat(
            "GAGAL",
            "Perangkat tidak menjawab ping",
            "Cek kabel LAN ke switch/POE, status link di Serial Monitor, dan "
            "pastikan IP perangkat berada di subnet yang sama (192.168.41.x).",
        )

    print("\n2. HTTP server di perangkat (endpoint /api/status)")
    berhasil_status, isi_status = ambil_json(f"http://{ip_perangkat}/api/status")
    if berhasil_status and isinstance(isi_status, dict):
        catat(
            "OK",
            "Perangkat membalas JSON status",
            f"firmware {isi_status.get('firmware')}, IP {isi_status.get('ip')}, boot {isi_status.get('boot')}",
        )
        if isi_status.get("w5500_terdeteksi"):
            catat("OK", "Modul W5500 terdeteksi oleh perangkat")
        else:
            catat(
                "GAGAL",
                "Modul W5500 tidak terdeteksi",
                "Periksa wiring SPI: SCK=18, MISO=19, MOSI=23, CS=5, VCC=3V3, GND=GND.",
            )
        if isi_status.get("link"):
            catat("OK", "Link kabel LAN aktif (LinkON)")
        else:
            catat("GAGAL", "Link kabel LAN mati", "Cek kabel LAN, port switch, dan keluaran POE Splitter.")
        catat(
            "INFO",
            "Uptime dan nomor pembacaan",
            f"{isi_status.get('uptime_s')} detik, id terakhir {isi_status.get('id_terakhir')}",
        )
        catat(
            "INFO",
            "Statistik pengiriman",
            f"sukses {isi_status.get('jumlah_kirim_sukses')}, gagal {isi_status.get('jumlah_kirim_gagal')}, "
            f"gagal baca sensor {isi_status.get('jumlah_gagal_baca')}",
        )
    else:
        catat("GAGAL", "Tidak bisa membaca /api/status", str(isi_status))

    print("\n3. Data sensor terakhir di perangkat (endpoint /api/latest)")
    berhasil_latest, isi_latest = ambil_json(f"http://{ip_perangkat}/api/latest")
    if berhasil_latest and isinstance(isi_latest, dict) and isi_latest.get("ada_data"):
        data = isi_latest.get("data", {})
        catat(
            "OK",
            "Sensor membaca data",
            f"suhu {data.get('suhu')} C, kelembapan {data.get('kelembapan')} %, pembacaan #{data.get('id')}",
        )
    elif berhasil_latest:
        catat(
            "GAGAL",
            "Perangkat belum menghasilkan data sensor valid",
            "Cek wiring DHT11: VCC=3V3, GND=GND, OUT=GPIO4, dan pastikan modulnya benar DHT11.",
        )
    else:
        catat("GAGAL", "Tidak bisa membaca /api/latest", str(isi_latest))

    print("\n4. Server monitoring di komputer ini")
    berhasil_health, isi_health = ambil_json(f"http://127.0.0.1:{port_server}/api/health")
    if berhasil_health and isinstance(isi_health, dict):
        catat(
            "OK",
            "server.py berjalan",
            f"jumlah pembacaan {isi_health.get('jumlah_pembacaan')}, "
            f"mode pull {isi_health.get('mode_pull')}, database {isi_health.get('database')}",
        )
    else:
        catat(
            "GAGAL",
            "server.py belum berjalan atau tidak merespons",
            "Jalankan run_server.bat lebih dulu, lalu ulangi pemeriksaan ini.",
        )

    if uji_push:
        print("\n5. Uji jalur PUSH ke server (dari komputer ini)")
        muatan = {
            "perangkat": "uji-konektivitas",
            "tipe": "reading",
            "mac": "02:00:5A:41:00:FF",
            "ip": ip_server,
            "boot": "UJICOBA0",
            "id": 900001,
            "uptime_s": 1,
            "suhu": 27.5,
            "kelembapan": 60.0,
            "valid": True,
            "firmware": "uji",
        }
        berhasil_kirim, balasan = kirim_json(f"http://127.0.0.1:{port_server}/api/data", muatan)
        if berhasil_kirim and isinstance(balasan, dict) and balasan.get("status") == "ok":
            catat("OK", "Server menerima data uji", f"tersimpan: {balasan.get('tersimpan')}")
            catat(
                "INFO",
                "Catatan penting",
                "Uji ini lewat jalur lokal komputer, jadi belum membuktikan ESP32 bisa masuk. "
                "Bukti sebenarnya adalah munculnya baris 'PUSH <perangkat> #...' di log server.py yang berasal dari ESP32.",
            )
        else:
            catat("GAGAL", "Server menolak data uji", str(balasan))

    print("\n" + "=" * 72)
    jumlah_gagal = sum(1 for golongan, _, _ in BARIS_HASIL if golongan == "GAGAL")
    if jumlah_gagal == 0:
        print("  HASIL: semua pemeriksaan penting berhasil.")
    else:
        print(f"  HASIL: {jumlah_gagal} pemeriksaan gagal, lihat langkah perbaikan di atas.")
    print("=" * 72)
    print()

    return jumlah_gagal == 0


def main() -> int:
    """Baca argumen, jalankan pemeriksaan, dan kembalikan kode keluar.

    Returns:
        Kode keluar proses (0 sukses, 1 ada kegagalan).
    """
    konfigurasi = muat_config()
    daftar_perangkat = konfigurasi.get("perangkat") or [{}]
    bagian_server = konfigurasi.get("server") or {}

    parser = argparse.ArgumentParser(description="Pemeriksaan konektivitas sensor suhu ESP32.")
    parser.add_argument(
        "--ip",
        default=daftar_perangkat[0].get("ip", "192.168.41.240"),
        help="IP perangkat ESP32.",
    )
    parser.add_argument(
        "--server",
        default=bagian_server.get("ip_lokal", "192.168.41.74"),
        help="IP komputer server monitoring.",
    )
    parser.add_argument(
        "--port",
        type=int,
        default=int(bagian_server.get("port", 5000)),
        help="Port server monitoring.",
    )
    parser.add_argument("--tanpa-push", action="store_true", help="Lewati pengujian jalur PUSH.")
    argumen = parser.parse_args()

    sukses = jalankan_pemeriksaan(
        argumen.ip, argumen.server, argumen.port, not argumen.tanpa_push
    )
    return 0 if sukses else 1


if __name__ == "__main__":
    sys.exit(main())


