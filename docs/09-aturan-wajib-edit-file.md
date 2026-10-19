# Aturan Wajib Edit File

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## ATURAN WAJIB EDIT FILE (supaya tidak gagal apply / macet)

1. File BARU atau perubahan >30% isi file: overwrite penuh (create_file
   atau tulis manual di editor), JANGAN find_and_replace/diff parsial.
2. Perubahan KECIL (<30%): find_and_replace dengan target pencarian PENDEK
   (maksimal 5-10 baris), dan target harus UNIK di file (kalau ragu suatu
   string muncul lebih dari 1 kali, sertakan konteks baris sebelum/sesudah
   yang lebih panjang supaya match presisi ke satu lokasi saja).
3. Satu file, satu bagian besar per panggilan tool. Pecah jadi beberapa
   panggilan berurutan untuk file besar (>250-300 baris), laporkan hasil
   tiap panggilan sebelum lanjut.
4. Baca ulang file dari disk LANGSUNG SEBELUM melakukan perubahan apapun
   pada file itu — baik `find_and_replace` MAUPUN overwrite penuh, tidak
   terkecuali. Ini termasuk file yang "baru saja" ditulis di giliran atau
   panggilan tool sebelumnya dalam sesi yang sama — JANGAN andalkan isi
   file yang diingat dari konteks/percakapan, karena isi di disk bisa
   sudah berubah (oleh proses lain, oleh revert, atau oleh panggilan tool
   sebelumnya yang gagal sebagian).
5. Jika tool edit GAGAL, STOP — jangan coba lagi dengan variasi teks
   berkali-kali, dan JANGAN eskalasi ke PowerShell. Laporkan: nama file,
   potongan teks yang dicari, dugaan penyebab gagal. Tunggu instruksi
   lanjutan — opsi teraman adalah manusia menulis manual langsung di editor.
6. File `.blade.php`: WAJIB overwrite penuh sebagai DEFAULT MUTLAK, BUKAN
   sekadar anjuran. `find_and_replace` parsial HANYA boleh untuk perubahan
   1 baris tunggal yang benar-benar unik di seluruh file — di luar itu
   DILARANG. **Trigger otomatis berhenti:** begitu SATU KALI panggilan
   `find_and_replace` pada file `.blade.php` gagal (string tidak
   ditemukan/tidak unik), itu adalah SINYAL WAJIB untuk langsung pindah ke
   overwrite penuh pada percobaan berikutnya — JANGAN coba variasi target
   pencarian lain, JANGAN coba replace bagian lain dulu dengan asumsi
   filenya masih baik-baik saja. Satu kegagalan find_and_replace di file
   blade = anggap file berpotensi sudah tidak sinkron dengan asumsi kamu;
   baca ulang dari disk, lalu overwrite penuh.
7. JANGAN PERNAH gunakan terminal/PowerShell/`php -r` untuk menulis isi
   file `.php` atau `.blade.php` — selalu pakai tool file bawaan (create
   file / edit file), atau tulis manual di editor kalau tool bermasalah.
8. Baca file lewat tool baca file (`view`/`read_file`) langsung, JANGAN
   verifikasi keberadaan/isi file lewat command PowerShell dengan `php -r`
   atau `file_exists()` di terminal. Untuk file kritis, verifikasi ukuran
   file (byte) sebagai pengecekan tambahan, jangan hanya percaya isi yang
   ditampilkan tool.
9. Setelah overwrite file blade/PHP, WAJIB hitung ulang kesesuaian tag
   pembuka/penutup (`<div>` vs `</div>`, `@foreach` vs `@endforeach`,
   `@push` vs `@endpush`, kurung kurawal `{` `}`) — jangan asumsikan
   berhasil hanya karena tidak ada error saat menulis.
10. **Recovery via `git checkout`/`git reset` pada satu file** (mis. file
    blade yang terbukti corrupt akibat find_and_replace gagal berulang):
    boleh dipakai sebagai langkah darurat SEKALI untuk mengembalikan file
    ke versi bersih terakhir yang ter-commit. Setelah checkout berhasil,
    WAJIB:
    - Baca ulang file itu dari disk untuk konfirmasi isinya memang bersih
      (jangan asumsikan checkout otomatis berarti bersih dan sesuai
      ekspektasi — verifikasi isi asli).
    - SISA perubahan yang belum selesai pada file itu WAJIB dilanjutkan
      dengan overwrite penuh, BUKAN kembali ke find_and_replace parsial —
      karena find_and_replace parsial yang berulang adalah PENYEBAB file
      itu corrupt di awal. Kembali ke metode yang sama akan mengulang
      kegagalan yang sama.
    - `git checkout HEAD -- <file>` mengembalikan ke commit TERAKHIR, bukan
      ke commit tertentu di masa lalu. JANGAN checkout ke commit hash lama
      dari Riwayat Insiden manapun di bawah — hash-hash itu catatan sejarah
      untuk kejadian saat itu saja, BUKAN prosedur baku untuk diulang (lihat
      catatan di Riwayat Insiden #3).
