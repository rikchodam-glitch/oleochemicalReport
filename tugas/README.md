# Papan Klaim Pekerjaan (`tugas/`)

Folder ini adalah papan pekerjaan bersama supaya beberapa teknisi (dan beberapa
asisten AI) tidak mengerjakan berkas yang sama pada waktu yang sama.

Aturan singkat:

1. Satu orang = satu berkas, dengan nama `tugas/<nama>.md`. Karena setiap orang
   hanya menulis berkasnya sendiri, folder ini **tidak pernah menimbulkan
   konflik** saat penggabungan.
2. Berkasnya dibuat/diperbarui otomatis oleh
   `powershell -File tools\mulai-tugas.ps1 -Nama <nama> -Tugas <ringkas>`
   pada saat memulai tugas baru.
3. Sebelum mengubah berkas, jalankan `git fetch origin main` lalu periksa
   pekerjaan orang lain:
   `Get-ChildItem tugas\*.md | ForEach-Object { $_ }`.
   Kalau ada yang sedang menyentuh berkas/modul yang sama, **koordinasi dulu
   lewat grup** sebelum menyunting berkas itu.
4. Setelah tugas di-merge, ubah `Status` berkasnya menjadi `SELESAI` dan kosongkan
   daftar berkas yang disentuh (jangan dihapus, biar jadi riwayat).

Berkas di folder ini **BUKAN** dokumentasi sistem dan bukan pengganti
`AGENTS.md`. Isinya hanya penanda siapa-sedang-apa-supaya-tidak-tabrakan.
