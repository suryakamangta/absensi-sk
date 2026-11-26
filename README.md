# Absensi SK — Aplikasi Absensi PT. Surya Kamangta

Aplikasi absensi karyawan (Android + web) yang tersambung langsung ke **HRIS Notion** Surya Kamangta lewat **Make**.

## Cara kerja

```
HP karyawan (APK Absensi SK / browser)
        │  POST form  →  Make webhook "Absensi SK App"
        ▼
Make: "App Absensi SK + Laporan AJU (sementara digabung)"
        │  cek PIN → baca/tulis Notion
        ▼
Notion HRIS: Karyawan · Absensi · Cuti   (+ OneDrive: LAMPIRAN IZIN ABSENSI)
```

| Fitur | Keterangan |
|---|---|
| Login | Pilih nama + PIN 4 digit (kolom **PIN Absen** di database Karyawan). Hanya karyawan dengan centang **Aktif**. |
| Absen masuk / pulang | GPS HP dicek terhadap kantor **Head Office** (-6.1153294, 106.8857221), radius **20 m**. Di luar radius tetap bisa absen tapi wajib isi keterangan dan ditandai *DI LUAR RADIUS KANTOR*. Lokasi palsu (Fake GPS) ditolak. |
| Ke Notion → Absensi | 1 baris per karyawan per hari: Tanggal, Karyawan, Status Hadir, Jam Masuk (tap pertama), Jam Keluar (pulang terakhir), Terlambat (menit, dari 08:00, Sen–Jum), Lokasi Kerja, Keterangan Lokasi, Lokasi Masuk/Pulang (link Google Maps), Log Absen (semua tap). Rumus Jam Lembur yang sudah ada di HRIS tetap jalan. |
| Riwayat | 40 hari terakhir: jam masuk/pulang, telat, luar radius, lembur. |
| Izin / sakit / cuti | Masuk ke database **Cuti** dengan status *Diajukan*. Lampiran foto/PDF disimpan di OneDrive `SERVER DATA CENTER SK/LAMPIRAN IZIN ABSENSI` dan link-nya masuk ke kolom Lampiran. |
| Rekap | Hanya untuk karyawan yang dicentang **Akses Rekap Absen**: daftar hadir/telat/belum absen per tanggal. |

## Pengaturan yang sering diubah

- **Lokasi & radius kantor**: `web/index.html` → `CFG.OFFICES` (bisa tambah kantor lain, mis. Operation Office).
- **Jam masuk**: tampilan di `CFG.JAM_MASUK`; perhitungan terlambat ada di skenario Make (480 menit = 08:00).
- **Karyawan baru**: tambah di Notion Karyawan, centang Aktif, isi PIN Absen (unik).

## Build APK & hosting (GitHub)

1. Buat repo GitHub (privat boleh untuk APK; untuk GitHub Pages gratis repo harus publik).
2. Isi *Settings → Secrets and variables → Actions*:
   - `SK_KEYSTORE_B64` = isi file `sk-release.jks.b64`
   - `SK_KEYSTORE_PASSWORD`, `SK_KEY_PASSWORD`, `SK_KEY_ALIAS` = dari `keystore-info.txt`
3. Push ke branch `main`. Workflow **Build APK Absensi SK** membuat `AbsensiSK.apk` di *Releases → latest*.
4. (Opsional) *Settings → Pages → Source: GitHub Actions* → web absensi tampil di `https://<akun>.github.io/<repo>/` untuk dipakai dari browser / iPhone.

> Simpan `sk-release.jks` dan password-nya baik-baik. Update APK harus ditandatangani dengan keystore yang sama, kalau tidak HP karyawan harus uninstall dulu.

Build lokal (Android Studio): buka folder `android/`, lalu *Build → Build APK(s)*.
