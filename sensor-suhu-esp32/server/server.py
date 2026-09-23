"""server.py

Server penerima data + penyimpan + dashboard untuk monitoring suhu & kelembapan
ESP32 (W5500 Ethernet) + DHT11.

Menjalankan:
    .venv\\Scripts\\python.exe server.py

Dua jalur data yang didukung:
1. PUSH - ESP32 mengirim HTTP POST ke POST /api/data (butuh port 5000 diizinkan
   masuk oleh Windows Firewall di komputer ini).
2. PULL - server menarik sendiri data dari ESP32 lewat GET /api/buffer?since=ID
   di thread latar belakang. Mode ini tidak butuh aturan firewall masuk sama
   sekali, jadi dipakai sebagai jalur cadangan yang selalu bisa jalan.

Kedua jalur menyimpan ke tabel yang sama, dan duplikasi dicegah dengan
UNIQUE (perangkat, boot, id_perangkat) sehingga jalur PUSH dan PULL boleh
aktif bersamaan tanpa menghasilkan baris ganda.
"""

from __future__ import annotations

import csv
import io
import json
import sqlite3
import threading
import urllib.error
import urllib.request
from contextlib import closing
from datetime import datetime, timedelta
from pathlib import Path

from flask import Flask, Response, jsonify, render_template, request

AKAR_PROYEK = Path(__file__).resolve().parent.parent
BERKAS_CONFIG = AKAR_PROYEK / "config.json"

CONFIG_BAWAAN = {
    "perangkat": [
        {"nama": "sensor-suhu-01", "ip": "192.168.41.240", "port": 80, "aktif": True}
    ],
    "server": {"host": "0.0.0.0", "port": 5000, "ip_lokal": "192.168.41.74"},
    "mode_pull": True,
    "interval_pull_detik": 5,
    "interval_tampilan_detik": 5,
    "batas_data_grafik": 60,
    "database": "data_sensor.db",
}

app = Flask(__name__)


def catat(pesan: str) -> None:
    """Cetak pesan log dengan tanda waktu ke konsol.

    Args:
        pesan: Teks yang akan dicetak.
    """
    print(f"[{datetime.now().strftime('%H:%M:%S')}] {pesan}", flush=True)


def muat_config() -> dict:
    """Baca config.json dan lengkapi dengan nilai bawaan bila ada yang kurang.

    Returns:
        dict konfigurasi yang sudah siap dipakai.
    """
    if not BERKAS_CONFIG.exists():
        catat(f"config.json tidak ditemukan di {BERKAS_CONFIG}, memakai nilai bawaan.")
        return dict(CONFIG_BAWAAN)

    # Dibaca dengan utf-8-sig supaya berkas yang disimpan memakai BOM (misalnya
    # hasil edit Notepad atau PowerShell) tetap bisa dibaca.
    with BERKAS_CONFIG.open(encoding="utf-8-sig") as berkas:
        try:
            data = json.load(berkas)
        except json.JSONDecodeError as galat:
            catat(f"config.json tidak valid ({galat}), memakai nilai bawaan.")
            return dict(CONFIG_BAWAAN)

    for kunci, nilai in CONFIG_BAWAAN.items():
        data.setdefault(kunci, nilai)
    return data


KONFIG = muat_config()
BERKAS_DB = AKAR_PROYEK / KONFIG["database"]

SKEMA_SQL = """
CREATE TABLE IF NOT EXISTS pembacaan (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    waktu         TEXT    NOT NULL,
    perangkat     TEXT    NOT NULL,
    boot          TEXT    NOT NULL,
    id_perangkat  INTEGER NOT NULL,
    suhu          REAL,
    kelembapan    REAL,
    valid         INTEGER NOT NULL DEFAULT 1,
    uptime_s      INTEGER,
    sumber        TEXT    NOT NULL DEFAULT 'push',
    UNIQUE (perangkat, boot, id_perangkat)
);

CREATE INDEX IF NOT EXISTS idx_pembacaan_waktu ON pembacaan (waktu);
CREATE INDEX IF NOT EXISTS idx_pembacaan_perangkat ON pembacaan (perangkat, waktu);

CREATE TABLE IF NOT EXISTS perangkat (
    nama                TEXT PRIMARY KEY,
    mac                 TEXT,
    ip                  TEXT,
    firmware            TEXT,
    boot                TEXT,
    id_terakhir         INTEGER DEFAULT 0,
    uptime_s            INTEGER DEFAULT 0,
    jumlah_gagal_baca   INTEGER DEFAULT 0,
    terakhir_terlihat   TEXT,
    terakhir_heartbeat  TEXT,
    sumber_terakhir     TEXT,
    catatan             TEXT
);
"""


def buka_db() -> sqlite3.Connection:
    """Buka koneksi SQLite baru dengan WAL aktif supaya aman diakses banyak thread.

    Returns:
        Koneksi sqlite3 dengan row_factory sqlite3.Row.
    """
    kon = sqlite3.connect(str(BERKAS_DB), timeout=10)
    kon.row_factory = sqlite3.Row
    kon.execute("PRAGMA journal_mode=WAL")
    return kon


def siapkan_db() -> None:
    """Buat tabel dan indeks yang dibutuhkan bila belum ada."""
    with closing(buka_db()) as kon:
        kon.executescript(SKEMA_SQL)
        kon.commit()


def waktu_sekarang() -> str:
    """Ambil waktu lokal server dalam format YYYY-MM-DD HH:MM:SS.

    Returns:
        Teks waktu siap simpan.
    """
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def samakan_angka(nilai) -> float | None:
    """Ubah nilai dari JSON menjadi float, kembalikan None bila kosong/tidak sah.

    Args:
        nilai: Nilai mentah dari payload JSON.

    Returns:
        float bila nilainya angka, None bila bukan.
    """
    if nilai is None or nilai == "":
        return None
    try:
        return float(nilai)
    except (TypeError, ValueError):
        return None


def hitung_waktu_ukur(selisih_detik: float) -> str:
    """Hitung waktu pengukuran mundur dari waktu server saat ini.

    ESP32 tidak punya RTC, jadi waktu pengukuran dihitung dari selisih uptime
    perangkat. Dipakai saat mode pull mengambil data yang sempat tertahan di
    buffer RAM perangkat.

    Args:
        selisih_detik: Selisih uptime perangkat saat data diambil.

    Returns:
        Teks waktu dalam format YYYY-MM-DD HH:MM:SS.
    """
    if selisih_detik <= 0:
        return waktu_sekarang()
    return (datetime.now() - timedelta(seconds=selisih_detik)).strftime("%Y-%m-%d %H:%M:%S")


def simpan_pembacaan(data: dict, sumber: str, waktu: str) -> bool:
    """Simpan satu pembacaan ke tabel pembacaan.

    Args:
        data: Payload JSON dari perangkat (harus berisi perangkat, boot, id).
        sumber: Asal data, "push" atau "pull".
        waktu: Waktu pengukuran dalam format YYYY-MM-DD HH:MM:SS.

    Returns:
        True bila baris baru tersimpan, False bila data sudah ada (duplikat).
    """
    perangkat = str(data.get("perangkat", "tanpa-nama"))
    boot = str(data.get("boot", "-"))
    id_perangkat = int(data.get("id") or 0)

    with closing(buka_db()) as kon:
        kursor = kon.execute(
            """
            INSERT OR IGNORE INTO pembacaan
                (waktu, perangkat, boot, id_perangkat, suhu, kelembapan, valid, uptime_s, sumber)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                waktu,
                perangkat,
                boot,
                id_perangkat,
                samakan_angka(data.get("suhu")),
                samakan_angka(data.get("kelembapan")),
                1 if data.get("valid", True) else 0,
                int(data.get("uptime_s") or 0),
                sumber,
            ),
        )
        kon.commit()
        return kursor.rowcount > 0


def simpan_status_perangkat(data: dict, sumber: str, tipe: str) -> None:
    """Perbarui baris status perangkat (tabel perangkat) dari payload perangkat.

    Args:
        data: Payload JSON dari perangkat.
        sumber: Asal data, "push" atau "pull".
        tipe: Jenis payload, "reading" atau "heartbeat".
    """
    nama = str(data.get("perangkat", "tanpa-nama"))
    sekarang = waktu_sekarang()
    kolom_terlihat = "terakhir_terlihat" if tipe == "reading" else "terakhir_heartbeat"

    with closing(buka_db()) as kon:
        kon.execute(
            """
            INSERT INTO perangkat
                (nama, mac, ip, firmware, boot, id_terakhir, uptime_s, jumlah_gagal_baca,
                 terakhir_terlihat, terakhir_heartbeat, sumber_terakhir)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(nama) DO UPDATE SET
                mac = COALESCE(excluded.mac, perangkat.mac),
                ip = COALESCE(excluded.ip, perangkat.ip),
                firmware = COALESCE(excluded.firmware, perangkat.firmware),
                boot = COALESCE(excluded.boot, perangkat.boot),
                id_terakhir = MAX(COALESCE(excluded.id_terakhir, 0), COALESCE(perangkat.id_terakhir, 0)),
                uptime_s = COALESCE(excluded.uptime_s, perangkat.uptime_s),
                jumlah_gagal_baca = COALESCE(excluded.jumlah_gagal_baca, perangkat.jumlah_gagal_baca),
                sumber_terakhir = excluded.sumber_terakhir
            """,
            (
                nama,
                data.get("mac"),
                data.get("ip"),
                data.get("firmware"),
                data.get("boot"),
                int(data.get("id") or data.get("id_terakhir") or 0),
                int(data.get("uptime_s") or 0),
                int(data.get("jumlah_gagal_baca") or 0) if "jumlah_gagal_baca" in data else None,
                sekarang if tipe == "reading" else None,
                sekarang if tipe == "heartbeat" else None,
                sumber,
            ),
        )
        kon.execute(
            f"UPDATE perangkat SET {kolom_terlihat} = ? WHERE nama = ?",
            (sekarang, nama),
        )
        kon.commit()


def catat_catatan_perangkat(nama: str, catatan: str) -> None:
    """Simpan catatan kondisi koneksi perangkat (misalnya gagal ditarik).

    Args:
        nama: Nama perangkat.
        catatan: Teks catatan singkat.
    """
    with closing(buka_db()) as kon:
        kon.execute("UPDATE perangkat SET catatan = ? WHERE nama = ?", (catatan, nama))
        kon.commit()


def ambil_pembacaan(limit: int) -> list[dict]:
    """Ambil sejumlah pembacaan terakhir, diurutkan dari lama ke baru.

    Args:
        limit: Jumlah maksimum baris yang diambil.

    Returns:
        list dict pembacaan siap dikirim sebagai JSON.
    """
    with closing(buka_db()) as kon:
        baris = kon.execute(
            """
            SELECT id, waktu, perangkat, boot, id_perangkat, suhu, kelembapan, valid, uptime_s, sumber
            FROM pembacaan
            ORDER BY id DESC
            LIMIT ?
            """,
            (limit,),
        ).fetchall()

    return [dict(item) for item in reversed(baris)]


def ringkasan_perangkat() -> list[dict]:
    """Ambil status semua perangkat beserta penanda online/offline.

    Returns:
        list dict status perangkat.
    """
    batas_online_detik = max(30, int(KONFIG["interval_pull_detik"]) * 6)

    with closing(buka_db()) as kon:
        baris = kon.execute(
            """
            SELECT nama, mac, ip, firmware, boot, id_terakhir, uptime_s,
                   jumlah_gagal_baca, terakhir_terlihat, terakhir_heartbeat,
                   sumber_terakhir, catatan
            FROM perangkat
            ORDER BY nama
            """
        ).fetchall()

    hasil = []
    for item in baris:
        data = dict(item)
        acuan = data.get("terakhir_terlihat") or data.get("terakhir_heartbeat")
        online = False
        if acuan:
            try:
                selisih = (datetime.now() - datetime.strptime(acuan, "%Y-%m-%d %H:%M:%S")).total_seconds()
                online = selisih <= batas_online_detik
            except ValueError:
                online = False
        data["online"] = online
        hasil.append(data)
    return hasil


# ---------------------------------------------------------------------------
# ROUTE HTTP
# ---------------------------------------------------------------------------


@app.get("/")
def halaman_dashboard():
    """Tampilkan halaman dashboard monitoring."""
    return render_template(
        "dashboard.html",
        nama_server="Monitoring Suhu & Kelembapan",
        ip_server=KONFIG["server"]["ip_lokal"],
        port_server=KONFIG["server"]["port"],
        interval_tampilan=KONFIG["interval_tampilan_detik"],
        batas_grafik=KONFIG["batas_data_grafik"],
        mode_pull=KONFIG["mode_pull"],
    )


@app.post("/api/data")
def terima_data():
    """Terima data yang dikirim ESP32 lewat HTTP POST (jalur PUSH).

    Returns:
        JSON berisi status penerimaan.
    """
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return jsonify(status="gagal", pesan="payload JSON tidak valid"), 400

    tipe = str(data.get("tipe", "reading"))
    nama = str(data.get("perangkat", "tanpa-nama"))

    if tipe == "heartbeat":
        simpan_status_perangkat(data, "push", tipe)
        catat(f"PUSH heartbeat dari {nama} (uptime {data.get('uptime_s', 0)} s, gagal baca {data.get('jumlah_gagal_baca', 0)}).")
        return jsonify(status="ok", tipe=tipe, tersimpan=False)

    waktu = waktu_sekarang()
    tersimpan = simpan_pembacaan(data, "push", waktu)
    simpan_status_perangkat(data, "push", "reading")

    if tersimpan:
        catat(f"PUSH {nama} #{data.get('id')}: suhu {data.get('suhu')} C, kelembapan {data.get('kelembapan')} %.")
    else:
        catat(f"PUSH {nama} #{data.get('id')} diabaikan (sudah ada di database).")

    return jsonify(status="ok", tipe=tipe, tersimpan=tersimpan, waktu=waktu)


@app.get("/api/data")
def daftar_data():
    """Kembalikan daftar pembacaan terakhir (dari lama ke baru) untuk dashboard.

    Returns:
        JSON berisi daftar pembacaan.
    """
    limit = request.args.get("limit", default=int(KONFIG["batas_data_grafik"]), type=int)
    limit = max(1, min(limit, 2000))
    return jsonify(perangkat=ringkasan_perangkat(), data=ambil_pembacaan(limit))


@app.get("/api/latest")
def data_terakhir():
    """Kembalikan satu pembacaan terakhir beserta status perangkat.

    Returns:
        JSON berisi pembacaan terakhir (null bila belum ada).
    """
    daftar = ambil_pembacaan(1)
    return jsonify(
        ada_data=bool(daftar),
        data=daftar[0] if daftar else None,
        perangkat=ringkasan_perangkat(),
    )


@app.get("/api/perangkat")
def data_perangkat():
    """Kembalikan status semua perangkat yang pernah mengirim data.

    Returns:
        JSON berisi daftar status perangkat.
    """
    return jsonify(perangkat=ringkasan_perangkat())


@app.get("/api/health")
def kesehatan_server():
    """Kembalikan kondisi ringkas server (jumlah data, mode, waktu server).

    Returns:
        JSON status server.
    """
    with closing(buka_db()) as kon:
        jumlah = kon.execute("SELECT COUNT(*) AS jumlah FROM pembacaan").fetchone()["jumlah"]

    return jsonify(
        status="ok",
        waktu_server=waktu_sekarang(),
        jumlah_pembacaan=jumlah,
        mode_pull=bool(KONFIG["mode_pull"]),
        interval_pull_detik=KONFIG["interval_pull_detik"],
        database=str(BERKAS_DB.name),
    )


@app.get("/api/export.csv")
def ekspor_csv():
    """Unduh data pembacaan dalam format CSV.

    Returns:
        Response berisi berkas CSV.
    """
    limit = request.args.get("limit", default=5000, type=int)
    limit = max(1, min(limit, 200000))
    daftar = ambil_pembacaan(limit)

    penampung = io.StringIO()
    penulis = csv.writer(penampung)
    penulis.writerow(["waktu", "perangkat", "id_perangkat", "suhu_c", "kelembapan_persen", "valid", "uptime_s", "sumber"])
    for item in daftar:
        penulis.writerow([
            item["waktu"],
            item["perangkat"],
            item["id_perangkat"],
            item["suhu"],
            item["kelembapan"],
            item["valid"],
            item["uptime_s"],
            item["sumber"],
        ])

    nama_berkas = f"data_sensor_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
    return Response(
        penampung.getvalue(),
        mimetype="text/csv",
        headers={"Content-Disposition": f"attachment; filename={nama_berkas}"},
    )


# ---------------------------------------------------------------------------
# MODE PULL (server menarik data sendiri dari ESP32)
# ---------------------------------------------------------------------------


def ambil_json(url: str, batas_detik: float = 4.0) -> dict | None:
    """Ambil isi URL dan uraikan sebagai JSON.

    Args:
        url: Alamat lengkap yang dipanggil.
        batas_detik: Batas waktu permintaan HTTP.

    Returns:
        dict hasil urai JSON, atau None bila gagal.
    """
    try:
        with urllib.request.urlopen(url, timeout=batas_detik) as balasan:
            return json.loads(balasan.read().decode("utf-8", errors="replace"))
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, ValueError, OSError):
        return None


def status_db_perangkat(nama: str) -> dict:
    """Ambil baris status perangkat dari database.

    Args:
        nama: Nama perangkat.

    Returns:
        dict berisi boot dan id_terakhir; kosong bila belum pernah tercatat.
    """
    with closing(buka_db()) as kon:
        baris = kon.execute(
            "SELECT boot, id_terakhir FROM perangkat WHERE nama = ?", (nama,)
        ).fetchone()
    return dict(baris) if baris is not None else {}


def tarik_dari_perangkat(perangkat: dict) -> int:
    """Tarik data baru dari satu perangkat ESP32 (mode pull).

    Data yang tertahan di buffer RAM perangkat tetap tersimpan walaupun server
    sempat mati, karena permintaan memakai penanda "since" (id terakhir yang
    sudah dimiliki server).

    Args:
        perangkat: Konfigurasi perangkat dari config.json.

    Returns:
        Jumlah baris baru yang berhasil disimpan.
    """
    nama = str(perangkat.get("nama", "tanpa-nama"))
    basis = f"http://{perangkat.get('ip')}:{int(perangkat.get('port', 80))}"

    status = ambil_json(f"{basis}/api/status", batas_detik=3.0)
    if not isinstance(status, dict):
        catat_catatan_perangkat(nama, f"Tidak bisa dihubungi pada {waktu_sekarang()}.")
        return 0

    status.setdefault("perangkat", nama)
    tersimpan_db = status_db_perangkat(nama)
    boot_baru = str(status.get("boot") or "-")
    boot_lama = str(tersimpan_db.get("boot") or "-")

    if boot_lama != boot_baru:
        sejak = 0
        catat(f"Perangkat {nama} memulai sesi boot baru ({boot_baru}), menarik seluruh buffer.")
    else:
        sejak = int(tersimpan_db.get("id_terakhir") or 0)

    isi = ambil_json(f"{basis}/api/buffer?since={sejak}", batas_detik=5.0)
    daftar = isi.get("data", []) if isinstance(isi, dict) else []
    uptime_sekarang = int(status.get("uptime_s") or 0)

    jumlah_baru = 0
    boot_isi = isi.get("boot") if isinstance(isi, dict) else None
    for item in daftar:
        item["perangkat"] = nama
        item["boot"] = str(boot_isi or boot_baru)
        selisih = max(0, uptime_sekarang - int(item.get("uptime_s") or 0))
        waktu = hitung_waktu_ukur(selisih)
        if simpan_pembacaan(item, "pull", waktu):
            jumlah_baru += 1

    tipe_status = "reading" if jumlah_baru > 0 else "heartbeat"
    simpan_status_perangkat(status, "pull", tipe_status)
    catat_catatan_perangkat(nama, "" if jumlah_baru else "Terhubung, belum ada data baru.")

    if jumlah_baru:
        catat(f"PULL {nama}: {jumlah_baru} data baru tersimpan (id terakhir {status.get('id_terakhir')}).")

    return jumlah_baru


def pekerja_pull(berhenti: threading.Event) -> None:
    """Thread latar belakang yang menarik data dari semua perangkat aktif.

    Args:
        berhenti: Event penanda thread harus berhenti.
    """
    interval = max(2, int(KONFIG["interval_pull_detik"]))
    daftar = [p for p in KONFIG.get("perangkat", []) if p.get("aktif", True)]
    catat(f"Mode pull aktif, interval {interval} detik, {len(daftar)} perangkat aktif.")

    while not berhenti.is_set():
        for perangkat in daftar:
            try:
                tarik_dari_perangkat(perangkat)
            except Exception as galat:  # jaring pengaman supaya thread tidak mati
                catat(f"Galat saat menarik data {perangkat.get('nama')}: {galat}")
        berhenti.wait(interval)


def main() -> None:
    """Siapkan database, jalankan thread pull, lalu jalankan web server."""
    siapkan_db()

    berhenti = threading.Event()
    if KONFIG["mode_pull"]:
        benang = threading.Thread(target=pekerja_pull, args=(berhenti,), daemon=True)
        benang.start()
    else:
        catat("Mode pull dimatikan di config.json, server hanya menerima data PUSH.")

    host = KONFIG["server"]["host"]
    port = int(KONFIG["server"]["port"])
    catat(f"Dashboard siap di http://{KONFIG['server']['ip_lokal']}:{port} (bind {host}).")
    catat(f"Database: {BERKAS_DB}")

    try:
        app.run(host=host, port=port, threaded=True, debug=False)
    finally:
        berhenti.set()


if __name__ == "__main__":
    main()



