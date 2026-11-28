<?php
// Salin file ini menjadi config.php lalu isi token Notion.
// Token dibuat di https://www.notion.so/profile/integrations (Internal Integration),
// lalu halaman HRIS dihubungkan ke integrasi itu (menu ••• → Connections).
return [
    'notion_token'    => 'ISI_TOKEN_NOTION_DI_SINI',
    'jam_masuk_menit' => 480, // 08:00 = 8 x 60
    // Opsional: ID data source Payroll. Kosongkan = dicari otomatis (database berjudul Payroll yang punya relasi ke Karyawan).
    'ds_payroll'      => '',
];
