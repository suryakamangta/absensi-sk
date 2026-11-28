# Absensi SK — Aplikasi Absensi PT. Surya Kamangta

Aplikasi absensi karyawan (Android + web) yang tersambung langsung ke **HRIS Notion** Surya Kamangta lewat API PHP di hosting perusahaan (cPanel), tanpa Make.

## Cara kerja

```
HP karyawan (APK Absensi SK / browser)
        │  POST form  →  https://suryakamangta.co.id/absensi-sk/api.php
        ▼
api.php (cPanel)  — cek PIN (kunci 15 menit setelah 5x salah) → Notion API
        ▼
Notion HRIS: Karyawan · Absensi · Cuti (lampiran izin diunggah langsung ke Notion)
```

| Fitur | Keterangan |
|---|---|
| Login | Pilih nama + PIN 4 digit (kolom **PIN Absen** di database Karyawan). Hanya karyawan dengan centang **Aktif**. |
| Absen masuk / pulang | GPS HP dicek terhadap kantor **Head Office** (-6.1153294, 106.8857221), radius **50 m**. Di luar radius tetap bisa absen tapi wajib isi keterangan dan ditandai *DI LUAR RADIUS KANTOR*. Lokasi palsu (Fake GPS) ditolak. |
| Ke Notion → Absensi | 1 baris per karyawan per hari: Tanggal, Karyawan, Status Hadir, Jam Masuk (tap pertama), Jam Keluar (pulang terakhir), Terlambat (menit, dari 08:00, Sen–Jum), Lokasi Kerja, Keterangan Lokasi, Lokasi Masuk/Pulang (link Google Maps), Log Absen (semua tap). Rumus Jam Lembur yang sudah ada di HRIS tetap jalan. |
| Riwayat | 40 hari terakhir: jam masuk/pulang, telat, luar radius, lembur. |
| Izin / sakit / cuti | Masuk ke database **Cuti** dengan status *Diajukan*. Lampiran foto/PDF (maks 5 MB) langsung tersimpan di kolom Lampiran Notion. |
| Rekap | Hanya untuk karyawan yang dicentang **Akses Rekap Absen**: daftar hadir/telat/belum absen per tanggal. |
| Rekap bulanan | Direktur & HRD: per karyawan hadir/hari kerja, terlambat, izin, lupa pulang, lembur & biayanya. |
| Cuti | Sisa cuti tahunan (jatah 12 hari setelah 12 bulan kerja, atau kolom **Jatah Cuti** bila ada), daftar pengajuan sendiri, dan persetujuan cuti oleh HRD (**Akses Rekap Absen**) / Direktur → kolom **Status Approval**. |
| Slip gaji | Menu Akun, wajib ketik ulang PIN. Data dari database **Payroll** (dicari otomatis; bisa dipaksa lewat `ds_payroll` di config.php). Baris berstatus Draft tidak ditampilkan. |
| Profil & ubah data | Karyawan melihat datanya sendiri dan meminta perubahan; HRD menyetujui → langsung tersimpan ke Notion. Pengingat kolom tanggal kontrak/masa berlaku ≤ 60 hari. Permintaan disimpan di `data/ubah_data.json`. |
| Pengumuman | HRD/Direktur memasang pengumuman (tampil di Absen & Monitor). Disimpan di `data/pengumuman.json`. |
| Ganti PIN | PIN baru 6 digit (login menerima 4–6 digit). |
| Pengingat absen | APK 1.2.0+: notifikasi 07:50 hari kerja bila belum absen masuk (bisa dimatikan di menu Akun). |

## Pengaturan yang sering diubah

- **Lokasi & radius kantor**: `web/index.html` → `CFG.OFFICES` (bisa tambah kantor lain, mis. Operation Office).
- **Jam masuk**: tampilan di `CFG.JAM_MASUK`; perhitungan terlambat di `config.php` → `jam_masuk_menit` (480 = 08:00).
- **Karyawan baru**: tambah di Notion Karyawan, centang Aktif, isi PIN Absen (unik).

## Pemasangan di cPanel

Folder `public_html/absensi-sk/` berisi: `index.html`, `api.php`, `.htaccess`, `config.php`.

1. Buat Internal Integration di https://www.notion.so/profile/integrations (workspace Suryakamangta), salin tokennya.
2. Di Notion buka halaman **HRIS** → ••• → **Connections** → tambahkan integrasi tersebut.
3. Isi token di `config.php` (salin dari `config.sample.php`). File ini tidak ikut ke GitHub dan diblokir dari luar oleh `.htaccess`.
4. Buka https://suryakamangta.co.id/absensi-sk/ dan coba login.

## Build APK (GitHub)

1. Buat repo GitHub (privat boleh untuk APK; untuk GitHub Pages gratis repo harus publik).
2. Isi *Settings → Secrets and variables → Actions*:
   - `SK_KEYSTORE_B64` = isi file `sk-release.jks.b64`
   - `SK_KEYSTORE_PASSWORD`, `SK_KEY_PASSWORD`, `SK_KEY_ALIAS` = dari `keystore-info.txt`
3. Push ke branch `main`. Workflow **Build APK Absensi SK** membuat `AbsensiSK.apk` di *Releases → latest*.
4. Web absensi di-hosting di **https://suryakamangta.co.id/absensi-sk/** (cPanel → File Manager → `public_html/absensi-sk/index.html`). APK membuka alamat ini, dan otomatis memakai salinan bawaan APK kalau alamat itu tidak bisa dibuka. Mengubah tampilan cukup dengan mengganti `index.html` di cPanel.

> Simpan `sk-release.jks` dan password-nya baik-baik. Update APK harus ditandatangani dengan keystore yang sama, kalau tidak HP karyawan harus uninstall dulu.

Build lokal (Android Studio): buka folder `android/`, lalu *Build → Build APK(s)*.
