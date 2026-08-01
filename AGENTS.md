# AGENTS.md — Template Panduan Kerja Agent

Dokumen ini adalah TEMPLATE. Isi bagian bertanda [C:\Users\rikchodam\Herd\projectoleochemical] sesuai
project yang sedang dikerjakan. Bagian aturan umum (edit file, gaya
kode, tampilan, alur sesi) berlaku untuk project apapun dan sebaiknya
tidak dihapus.

## PENTING — ROOT PROJECT

Project utama dan SATU-SATUNYA target semua perubahan:
[C:\Users\rikchodam\Herd\projectoleochemical: path absolut root project]

Setiap kali menyebutkan kondisi suatu file ("file X sudah ada", "kolom
Y begini"), WAJIB sertakan path lengkap absolut yang benar-benar dibaca
saat itu juga. Jangan mengandalkan laporan dari sesi sebelumnya tanpa
verifikasi ulang.

Jika ada lebih dari satu folder/project serupa di mesin yang sama,
sebutkan eksplisit path mana yang dimaksud setiap kali -- jangan
berasumsi berdasarkan nama folder yang mirip.

## KEPUTUSAN ARSITEKTUR / KONVENSI PROJECT (ISI SESUAI KEBUTUHAN)

Catat di sini keputusan yang sudah final agar agent tidak bertanya
ulang atau membuat asumsi sendiri. Contoh format tiap butir:

1. [Nama konsep] -> [Model/kelas terkait] (tabel/struktur: [nama]),
   kolom/field penting: [daftar kolom beserta tipe, nullable/unique,
   default, dsb]
2. [Nama tabel/struktur lain] sudah punya kolom/field berikut (JANGAN
   dibuat ulang): [daftar]
3. Relasi/kolom yang SENGAJA TIDAK ADA atau sudah dihapus dari desain:
   [sebutkan, agar agent tidak menambahkannya lagi]
4. Konvensi tema visual / desain (jika ada frontend): warna aksen,
   library (vanilla JS / framework tertentu), struktur layout/section
   yang dipakai, file referensi pola styling yang sudah benar.
5. Konvensi struktur layout wajib (jika ada): elemen yang harus ada di
   file layout utama dan tidak boleh dihapus.
6. Lokasi konfigurasi untuk hal-hal sensitif/khusus (API key, service
   pihak ketiga, dsb) dan cara mengaksesnya secara konsisten -- sebutkan
   juga jika ada key lama/usang yang TIDAK dihapus tapi TIDAK boleh
   dipakai lagi di kode baru.

Jangan menambah, mengubah, atau menganggap keputusan di atas berubah
tanpa konfirmasi eksplisit dari manusia.

## ATURAN WAJIB EDIT FILE (supaya tidak gagal apply / macet)

1. File BARU atau perubahan >30% isi file: overwrite penuh
   (create_file), JANGAN find_and_replace/diff parsial.
2. Perubahan KECIL (<30%): find_and_replace dengan target pencarian
   PENDEK (maksimal 5-10 baris) dan benar-benar unik dalam file.
3. Satu file, satu bagian besar per panggilan tool. Pecah jadi
   beberapa panggilan berurutan untuk file besar (>250-300 baris),
   laporkan hasil tiap panggilan sebelum lanjut.
4. Baca ulang file dari disk SEBELUM find_and_replace, jangan andalkan
   isi yang dibaca di awal sesi atau giliran sebelumnya.
5. Jika tool edit GAGAL, STOP -- jangan coba lagi dengan variasi teks
   berkali-kali, dan JANGAN eskalasi ke terminal/shell sebagai jalan
   pintas. Laporkan: nama file, potongan teks yang dicari, dugaan
   penyebab gagal. Tunggu instruksi lanjutan -- opsi teraman adalah
   manusia menulis manual langsung di editor.
6. File template/markup (blade, jsx, vue, html, dst): DEFAULT overwrite
   penuh, hindari find_and_replace parsial kecuali perubahan 1 baris
   tunggal yang benar-benar unik.
7. JANGAN PERNAH menulis isi file kode (php, js, py, dst) lewat
   command shell/terminal dengan heredoc atau echo -- selalu pakai
   tool file bawaan (create_file/str_replace/view), agar tidak ada
   masalah escaping karakter yang merusak file.
8. Baca file lewat tool baca file (view) langsung, JANGAN verifikasi
   keberadaan/isi file lewat command shell (misal php -r,
   file_exists(), cat, type). Untuk file kritis, verifikasi juga
   ukuran file (byte) sebagai pengecekan tambahan, jangan hanya
   percaya isi yang ditampilkan tool -- tool bisa menampilkan isi yang
   sudah tidak sesuai dengan file sebenarnya di disk.
9. Jangan berasumsi suatu file/folder/class sudah dibuat hanya karena
   didaftarkan di tempat lain (misal alias, service provider, config,
   import). Verifikasi file/class tersebut benar-benar ada di lokasi
   yang dirujuk.
10. Jika ragu apakah perubahan yang diminta akan memengaruhi file lain
    di luar yang sedang dikerjakan, sebutkan hal itu ke manusia sebelum
    melanjutkan, jangan diam-diam mengubah file lain.

## RIWAYAT INSIDEN (pelajaran, jangan diulang)

Catat setiap insiden nyata yang pernah terjadi di project ini beserta
penyebab dan cara mendeteksinya, supaya tidak terulang. Format:

1. [Nama file/area terdampak] mengalami [gejala/error]. Pemicunya:
   [penyebab teknis]. Ditemukan lewat: [cara verifikasi]. Pelajaran:
   [aturan baru yang ditambahkan akibat insiden ini].

<!-- Tambahkan insiden baru di bawah ini, nomor urut lanjut -->

## ATURAN PENULISAN KODE

- Kode ditulis rapi, terstruktur, dan konsisten dengan gaya kode yang
  sudah ada di project
- Tidak ada emoji di dalam kode, komentar, string konstanta, maupun
  nama variabel
- Komentar ditulis dalam Bahasa Indonesia yang jelas
- Setiap method baru wajib memiliki docblock singkat (parameter +
  return)
- Hapus kode yang tidak dipakai, jangan di-comment out tanpa alasan
- Gunakan early return untuk menghindari nesting yang dalam

## TAMPILAN (VIEW / BLADE / FRONTEND)

- Tampilan baru harus menyesuaikan tema visual yang sudah ada (warna,
  spacing, komponen, class naming)
- Gunakan komponen yang sudah ada di project, jangan buat ulang yang
  setara
- Tidak ada inline style kecuali untuk nilai dinamis

## ALUR KERJA SETIAP SESI BARU

1. Baca ulang file AGENTS.md ini secara penuh sebelum mulai kerja
   apapun
2. Sebutkan file apa saja yang akan disentuh sebelum mulai edit
3. Untuk fitur baru/kompleks ATAU untuk bug fix: baca dan laporkan dulu
   kondisi file terkait (dengan path absolut, lihat aturan di atas),
   TUNGGU konfirmasi manusia sebelum menulis kode
4. Setiap file yang diubah ditulis ulang lengkap (kecuali perubahan
   kecil, lihat Aturan Wajib Edit File poin 2)
5. Jika file dibutuhkan tapi belum jelas isinya, baca dulu, jangan
   mengarang
6. Jangan membuat asumsi tentang struktur data, relasi, atau konvensi
   project yang belum tercantum di bagian "Keputusan Arsitektur" di
   atas -- jika belum ada, tanyakan atau verifikasi langsung ke kode,
   jangan menebak
