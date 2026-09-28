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
