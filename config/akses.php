<?php

/**
 * Daftar modul halaman yang hak aksesnya bisa diatur per Bagian.
 *
 * Setiap modul memiliki keterangan:
 * - label        : nama modul yang tampil di halaman pengaturan hak akses.
 * - grup         : pengelompokan modul agar tampilan pengaturan mudah dibaca.
 * - pola         : pola NAMA ROUTE (dibandingkan memakai Str::is) yang termasuk
 *                  modul ini. Bila satu route cocok dengan beberapa pola,
 *                  dipakai pola yang paling spesifik (paling panjang).
 * - link         : nama route menu sidebar yang harus disembunyikan bila
 *                  pengguna tidak punya akses ke modul ini (null berarti
 *                  modul ini tidak punya menu di sidebar).
 * - khusus_admin : true berarti modul hanya boleh diakses role admin,
 *                  tidak bisa diberikan ke Bagian mana pun.
 *
 * Modul baru yang ditambahkan di sini otomatis muncul di halaman
 * "Hak Akses Bagian", dan hak aksesnya bisa diatur tanpa mengubah kode.
 */

return [
    'modul' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'grup'  => 'Menu Utama',
            'pola'  => ['dashboard'],
            'link'  => 'dashboard',
        ],
        'assets' => [
            'label' => 'Asset Management',
            'grup'  => 'Menu Utama',
            'pola'  => ['assets.*'],
            'link'  => 'assets.index',
        ],
        'func_locs' => [
            'label' => 'Functional Location',
            'grup'  => 'Menu Utama',
            'pola'  => ['func-locs.*', 'locations.*'],
            'link'  => 'func-locs.index',
        ],
        'technicians' => [
            'label' => 'Teknisi',
            'grup'  => 'Menu Utama',
            'pola'  => ['technicians.*'],
            'link'  => 'technicians.index',
        ],
        'cuti' => [
            'label' => 'Kelola Cuti Teknisi',
            'grup'  => 'Menu Utama',
            'pola'  => ['cuti.kelola.*'],
            'link'  => null,
        ],
        'cuti_semua' => [
            'label' => 'Semua Cuti Karyawan',
            'grup'  => 'Menu Utama',
            'pola'  => ['cuti.semua'],
            'link'  => null,
        ],
        'reports' => [
            'label' => 'Laporan',
            'grup'  => 'Menu Utama',
            'pola'  => ['reports.*'],
            'link'  => 'reports.index',
        ],
        'work_orders' => [
            'label' => 'Work Order',
            'grup'  => 'Menu Utama',
            'pola'  => ['work-orders.*'],
            'link'  => null,
        ],
        'it_assets' => [
            'label' => 'IT Assets',
            'grup'  => 'IT & Jaringan',
            'pola'  => ['it-assets.*'],
            'link'  => 'it-assets.index',
        ],
        'it_layouts' => [
            'label' => 'Denah IT',
            'grup'  => 'IT & Jaringan',
            'pola'  => ['it-assets.layouts.*'],
            'link'  => 'it-assets.layouts.index',
        ],
        'ip_addresses' => [
            'label' => 'Daftar IP',
            'grup'  => 'IT & Jaringan',
            'pola'  => ['it-assets.ip-addresses.*'],
            'link'  => 'it-assets.ip-addresses.index',
        ],
        'cm' => [
            'label' => 'Condition Monitoring',
            'grup'  => 'Reliability & Maintenance',
            'pola'  => ['cm.*'],
            'link'  => 'cm.overview',
        ],
        'reliability' => [
            'label' => 'Reliability Dashboard',
            'grup'  => 'Reliability & Maintenance',
            'pola'  => ['reliability.*'],
            'link'  => 'reliability.index',
        ],
        'collaboration' => [
            'label' => 'Kolaborasi',
            'grup'  => 'Reliability & Maintenance',
            'pola'  => ['collaboration.*'],
            'link'  => 'collaboration.index',
        ],
        'panel' => [
            'label' => 'Monitoring Ruang Panel',
            'grup'  => 'Reliability & Maintenance',
            'pola'  => ['panel.*'],
            'link'  => 'panel.index',
        ],
        'sparepart' => [
            'label' => 'Integrasi PR/PO Spare Part',
            'grup'  => 'Reliability & Maintenance',
            'pola'  => ['sparepart.*'],
            'link'  => 'sparepart.index',
        ],
        'sparepart_request' => [
            'label' => 'Request Spare Part',
            'grup'  => 'Reliability & Maintenance',
            'pola'  => ['sparepart.request.*'],
            'link'  => null,
        ],
        'ai_providers' => [
            'label' => 'AI Providers',
            'grup'  => 'Pengaturan',
            'pola'  => ['ai-providers.*'],
            'link'  => 'ai-providers.index',
        ],
        'bot' => [
            'label' => 'Bot Telegram',
            'grup'  => 'Pengaturan',
            'pola'  => ['bot.*'],
            'link'  => 'bot.index',
        ],
        'users' => [
            'label'        => 'Manajemen User',
            'grup'         => 'Pengaturan',
            'pola'         => ['users.*', 'akses.*'],
            'link'         => 'users.index',
            'khusus_admin' => true,
        ],
        'storage' => [
            'label' => 'Storage',
            'grup'  => 'Sistem',
            'pola'  => ['storage.*'],
            'link'  => 'storage.index',
        ],
    ],

    /**
     * Nama route yang SELALU boleh diakses pengguna yang sudah login dan tidak
     * terikat modul mana pun (mis. halaman logout). Dipakai oleh pengujian
     * untuk memastikan setiap route lain punya modul yang jelas.
     */
    'route_bebas_modul' => [
        'login',
        'logout',
        'telegram.webhook',
        'debug.login',
        // Halaman cuti milik teknisi: hanya butuh login + users.technician_id.
        // Teknisi hanya melihat; pencatatan oleh Admin Reguler.
        'cuti.saya',
        // Form laporan web milik teknisi: login + users.technician_id.
        'laporan.saya.create',
        // Form token publik + API pencarian lokasi/alat.
        'laporan.form',
        'laporan.simpan',
        'laporan.selesai',
        'laporan.api.tipe',
        'laporan.api.alat',
    ],

    'pesan_tolak' => 'Bagian Anda tidak memiliki akses ke halaman ini.',

    /**
     * Role yang boleh MENGUBAH data (tambah/ubah/hapus/import). Role lain
     * hanya bisa membaca: semua permintaan POST/PUT/PATCH/DELETE dari akun
     * non-penulis ditolak HanyaBacaMiddleware. Halaman yang boleh dibuka tetap
     * diatur per Bagian pada tabel bagian_accesses.
     *
     * Tambahkan 'supervisor' di sini bila supervisor juga perlu menulis.
     */
    'role_penulis' => ['admin'],

    /**
     * Kemampuan tulis sebagian untuk group teknisi tertentu (di luar
     * administrator). Akun yang terhubung teknisi dengan group di bawah ini
     * BOLEH memakai metode tulis, tetapi HANYA pada route yang termasuk
     * kemampuan yang tercantum. Semua tulisan lain tetap ditolak
     * HanyaBacaMiddleware.
     *
     * Diputuskan user 15/10/2026: Reguler Staff dan Staff Grub A-D boleh
     * mengelola laporan grub yang dinaunginya (termasuk baris kolaborasi bila
     * laporan itu induk), TIDAK boleh mengubah aset/menu lain, TIDAK boleh
     * import apa pun KECUALI import PO Spare Part, request Spare Part untuk
     * Bagian CM/PM, dan sync data CM/PM. Export tetap boleh karena route-nya GET.
     */
    'tulis_grup' => [
        'reguler_staff' => ['laporan', 'sparepart_impor', 'sparepart_request', 'cm_sync'],
        'grub_a_staff'  => ['laporan', 'sparepart_impor', 'cm_sync'],
        'grub_b_staff'  => ['laporan', 'sparepart_impor', 'cm_sync'],
        'grub_c_staff'  => ['laporan', 'sparepart_impor', 'cm_sync'],
        'grub_d_staff'  => ['laporan', 'sparepart_impor', 'cm_sync'],
    ],

    /**
     * Daftar NAMA ROUTE yang termasuk tiap kemampuan tulis sebagian.
     *
     * SENGAJA memakai daftar nama route eksplisit (bukan pola reports.*):
     * reports.store (buat laporan baru) TIDAK termasuk, sesuai keputusan user
     * bahwa staf hanya boleh MENGELOLA laporan yang sudah ada.
     */
    'tulis_kemampuan' => [
        'laporan' => [
            'reports.update',
            'reports.update-status',
            'reports.destroy',
            'reports.add-photo',
            'reports.photos.delete',
            'reports.collaborators.store',
            'reports.collaborators.destroy',
            'reports.multi-items.store',
            'reports.multi-items.destroy',
        ],
        'sparepart_impor' => [
            'sparepart.impor.proses',
        ],
        'sparepart_request' => [
            'sparepart.request.setujui',
            'sparepart.request.tolak',
            'sparepart.request.pr',
            'sparepart.request.po',
            'sparepart.request.terima',
            'sparepart.request.destroy',
        ],
        'cm_sync' => [
            'cm.sync-google-sheets',
        ],
    ],

    /**
     * Pola PATH (regex, diuji JavaScript pada halaman) untuk tiap kemampuan
     * tulis sebagian. Dipakai partial layouts/partials/mode-hanya-baca untuk
     * menyembunyikan form yang TIDAK boleh dikirim staf, dan tetap
     * menampilkan form yang boleh (mis. form edit laporan).
     *
     * Ubah pola ini sepasang dengan 'tulis_kemampuan' di atas.
     */
    'tulis_kemampuan_tampilan' => [
        'laporan'           => ['^/reports/[0-9]+(/|$)'],
        'sparepart_impor'   => ['^/sparepart/impor$'],
        'sparepart_request' => ['^/sparepart/request(/|$)'],
        'cm_sync'           => ['^/cm/sync-google-sheets$'],
    ],

    'pesan_tulis_sebagian' => 'Akun Anda hanya bisa mengelola laporan grub yang Anda naungi, ditambah import PO Spare Part, request Spare Part, dan sync data CM. Perubahan data lain ditolak.',

    'pesan_kelola_grub' => 'Akun Anda hanya bisa mengelola laporan grub yang Anda naungi.',

    /**
     * Route yang boleh memakai metode tulis walau akunnya hanya baca
     * (mis. logout supaya pengguna tetap bisa keluar).
     */
    'route_hanya_baca_bebas' => [
        'logout',
        'it-assets.notifications.read',
        'it-assets.notifications.read-all',
    ],

    'pesan_hanya_baca' => 'Akun Anda hanya bisa melihat data (read only). Hubungi administrator untuk mengubah data.',
];
