<?php
/**
 * API Absensi SK — dipasang di cPanel: public_html/absensi-sk/api.php
 * Menghubungkan aplikasi absensi langsung ke Notion HRIS (tanpa Make).
 *
 * Aksi (POST form): karyawan | login | riwayat | absen | izin | rekap | lembur_* (ajukan, batal, saya, list, putus, ringkasan)
 * Kunci Notion disimpan di config.php (tidak bisa dibuka dari luar, lihat .htaccess).
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$CFG = require __DIR__ . '/config.php';

const NOTION_VERSION = '2025-09-03';
const DS_KARYAWAN = 'ff1a2b01-5d22-8337-a0d9-87310d3db010';
const DS_ABSENSI  = '17ea2b01-5d22-823c-9fe5-0795550e1237';
const DS_CUTI     = '5b1a2b01-5d22-82ff-838c-872b34bdae2e';
const JENIS_CUTI = ['Izin', 'Sakit (Surat Dokter)', 'Cuti Tahunan', 'Cuti Sakit', 'Cuti Menikah', 'Cuti Melahirkan', 'Cuti Istri Melahirkan', 'Cuti Duka', 'Cuti Tanpa Gaji'];
const CUTI_PEREMPUAN = ['Cuti Melahirkan'];
const CUTI_LAKI = ['Cuti Istri Melahirkan'];
const LOKASI_KERJA = ['Head Office', 'Operation Office', 'WFH', 'Lapangan', 'Di Luar Kantor'];
/** Tanggal merah / cuti bersama (dihitung lembur Weekend). Tambahkan di sini bila perlu. */
const LIBUR = ['2026-12-24', '2026-12-25'];
const TIPE_LEMBUR = ['Sore', 'Malam', 'Weekend', 'Lembur'];
const BLN_ID = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 200): void { out(['ok' => false, 'msg' => $msg], $code); }
function p(string $k, string $def = ''): string { return trim((string)($_POST[$k] ?? $def)); }
function clean(string $s, int $max = 200): string {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $s)), 0, $max);
}
function nid(string $id): string { return strtolower(str_replace('-', '', $id)); }

/* ---------------- Notion ---------------- */
function notion(string $method, string $path, ?array $body = null): array {
    global $CFG;
    $base = $CFG['notion_base'] ?? 'https://api.notion.com';
    $ch = curl_init($base . $path);
    $headers = [
        'Authorization: Bearer ' . $CFG['notion_token'],
        'Notion-Version: ' . NOTION_VERSION,
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Tidak bisa terhubung ke Notion: ' . $err);
    $j = json_decode($res, true) ?: [];
    if ($code >= 400) {
        if ($code === 404) throw new NotFound($j['message'] ?? 'not found');
        throw new RuntimeException('Notion ' . $code . ': ' . ($j['message'] ?? $res));
    }
    return $j;
}
class NotFound extends RuntimeException {}

function query(string $ds, array $body): array {
    $all = [];
    $cursor = null;
    do {
        if ($cursor) $body['start_cursor'] = $cursor;
        $r = notion('POST', "/v1/data_sources/$ds/query", $body);
        $all = array_merge($all, $r['results'] ?? []);
        $cursor = ($r['has_more'] ?? false) ? ($r['next_cursor'] ?? null) : null;
    } while ($cursor && count($all) < 500);
    return $all;
}

function txt(array $page, string $prop): string {
    $p = $page['properties'][$prop] ?? null;
    if (!$p) return '';
    $arr = $p['title'] ?? $p['rich_text'] ?? [];
    return trim(implode('', array_map(fn($t) => $t['plain_text'] ?? '', $arr)));
}
function rt(string $s): array { return ['rich_text' => [['text' => ['content' => mb_substr($s, 0, 1900)]]]]; }
function num(array $page, string $prop) {
    $p = $page['properties'][$prop] ?? null;
    if (!$p) return null;
    if (($p['type'] ?? '') === 'formula') return $p['formula']['number'] ?? null;
    return $p['number'] ?? null;
}
function sel(array $page, string $prop): string { return $page['properties'][$prop]['select']['name'] ?? ''; }
function chk(array $page, string $prop): bool { return (bool)($page['properties'][$prop]['checkbox'] ?? false); }

/* ---------------- Keamanan PIN (kunci 15 menit setelah 5x salah) ---------------- */
function lockFile(string $id): string {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/pin_' . preg_replace('/[^a-f0-9]/', '', nid($id)) . '.json';
}
function pinLocked(string $id): bool {
    $f = lockFile($id);
    if (!is_file($f)) return false;
    $d = json_decode((string)@file_get_contents($f), true) ?: [];
    return ($d['until'] ?? 0) > time();
}
function pinFail(string $id): void {
    $f = lockFile($id);
    $d = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    $n = ($d['n'] ?? 0) + 1;
    $d = ['n' => $n, 'until' => $n >= 5 ? time() + 900 : 0];
    if ($n >= 5) $d['n'] = 0;
    @file_put_contents($f, json_encode($d));
}
function pinOk(string $id): void { @unlink(lockFile($id)); }

/** Ambil karyawan + cek PIN. Mengembalikan halaman Notion karyawan. */
function auth(): array {
    $id = p('id'); $pin = p('pin');
    if (!preg_match('/^[0-9a-fA-F-]{32,36}$/', $id)) fail('Akun tidak ditemukan.');
    if (!preg_match('/^\d{4,6}$/', $pin)) fail('PIN salah atau akun tidak aktif.');
    if (pinLocked($id)) fail('Terlalu banyak PIN salah. Coba lagi 15 menit lagi.');
    try { $k = notion('GET', "/v1/pages/$id"); }
    catch (NotFound $e) { fail('Akun tidak ditemukan.'); }
    $parent = nid($k['parent']['data_source_id'] ?? '');
    if ($parent !== nid(DS_KARYAWAN) || ($k['in_trash'] ?? $k['archived'] ?? false)) fail('Akun tidak ditemukan.');
    $real = txt($k, 'PIN Absen');
    if ($real === '' || !hash_equals($real, $pin) || !chk($k, 'Aktif')) {
        pinFail($id);
        fail('PIN salah atau akun tidak aktif.');
    }
    pinOk($id);
    return $k;
}

function todayRow(string $karyawanId): ?array { return dayRow($karyawanId, date('Y-m-d')); }

function rowOut(array $x, bool $withKaryawan = false): array {
    $o = [
        't'  => $x['properties']['Tanggal']['date']['start'] ?? '',
        's'  => sel($x, 'Status Kehadiran'),
        'm'  => txt($x, 'Jam Masuk'),
        'k'  => txt($x, 'Jam Keluar'),
        'l'  => txt($x, 'Keterangan Lokasi'),
        'tl' => (string)(num($x, 'Terlambat (menit)') ?? ''),
        'lb' => (string)(num($x, 'Jam Lembur') ?? ''),
        'pid' => $x['id'],
        'st' => sel($x, 'Status Lembur'),
        'tp' => sel($x, 'Tipe Lembur'),
        'nm' => (string)(num($x, 'Nominal Lembur') ?? ''),
        'lt' => (string)(num($x, 'Transport Lembur') ?? ''),
        'lm' => (string)(num($x, 'Makan Lembur') ?? ''),
        'kl' => txt($x, 'Keterangan Lembur'),
        'ap' => txt($x, 'Approval Lembur'),
    ];
    if ($withKaryawan) $o['id'] = $x['properties']['Karyawan']['relation'][0]['id'] ?? '';
    return $o;
}

/* ---------------- Lembur ---------------- */
/** Tipe lembur otomatis (pola rekap Excel): libur -> Weekend; pulang >= 20:00 atau < 05:00 -> Malam; selain itu Sore.
 *  Karyawan "Lembur Flat" (mis. Norma, Franky): hari kerja selalu "Lembur". */
function tipeOtomatis(string $tgl, string $jamKeluar, bool $flat = false): string {
    $dow = (int)date('N', strtotime($tgl));
    if ($dow >= 6 || in_array($tgl, LIBUR, true)) return 'Weekend';
    if ($flat) return 'Lembur';
    if (preg_match('/^(\d{1,2}):(\d{2})/', $jamKeluar, $m)) {
        $min = (int)$m[1] * 60 + (int)$m[2];
        if ($min >= 20 * 60 || $min < 5 * 60) return 'Malam';
    }
    return 'Sore';
}
/** Tipe + penggantian transport & makan lembur dari tarif karyawan. t/m = null jika tarif belum diisi. */
function tarifLembur(string $karyawanId, string $tgl, string $jamKeluar, string $pilih = 'auto'): array {
    $k = notion('GET', "/v1/pages/$karyawanId");
    $flat = chk($k, 'Lembur Flat');
    $auto = !in_array($pilih, TIPE_LEMBUR, true);
    $tp = $auto ? tipeOtomatis($tgl, $jamKeluar, $flat) : $pilih;
    if ($flat && in_array($tp, ['Sore', 'Malam'], true)) $tp = 'Lembur';
    if (!$flat && $tp === 'Lembur') $tp = tipeOtomatis($tgl, $jamKeluar, false);
    $kol = $tp === 'Lembur' ? 'Sore' : $tp;
    $t = num($k, "Lembur $kol Transport");
    $m = num($k, "Lembur $kol Uang Makan");
    return ['tipe' => $tp, 'auto' => $auto, 't' => $t === null ? null : (float)$t, 'm' => $t === null ? null : (float)($m ?? 0)];
}
function propsTarif(array $tr): array {
    if ($tr['t'] === null) return ['Tipe Lembur' => ['select' => ['name' => $tr['tipe']]]];
    return [
        'Tipe Lembur' => ['select' => ['name' => $tr['tipe']]],
        'Transport Lembur' => ['number' => $tr['t']],
        'Makan Lembur' => ['number' => $tr['m']],
        'Nominal Lembur' => ['number' => $tr['t'] + $tr['m']],
    ];
}
/** Periode rekap lembur: tutup buku hari kerja terakhir tiap bulan (seperti rekap Excel). */
function tutupBuku(int $y, int $mo): string {
    $d = strtotime(sprintf('%04d-%02d-01', $y, $mo) . ' +1 month -1 day');
    while ((int)date('N', $d) >= 6) $d -= 86400;
    return date('Y-m-d', $d);
}
function bulanLembur(string $tgl): string {
    $y = (int)substr($tgl, 0, 4); $mo = (int)substr($tgl, 5, 2);
    if ($tgl > tutupBuku($y, $mo)) { $mo++; if ($mo > 12) { $mo = 1; $y++; } }
    return sprintf('%04d-%02d %s', $y, $mo, BLN_ID[$mo]);
}
function lemburOut(array $x): array {
    $o = rowOut($x, true);
    $o['nama'] = preg_replace('/\s+-\s+\d{4}-\d{2}-\d{2}$/', '', txt($x, 'Judul'));
    return $o;
}
function rowAbsensi(string $pid): array {
    try { $row = notion('GET', "/v1/pages/$pid"); } catch (NotFound $e) { fail('Data lembur tidak ditemukan.'); }
    if (nid($row['parent']['data_source_id'] ?? '') !== nid(DS_ABSENSI)) fail('Data lembur tidak ditemukan.');
    return $row;
}
function dayRow(string $karyawanId, string $tgl): ?array {
    $r = notion('POST', '/v1/data_sources/' . DS_ABSENSI . '/query', [
        'filter' => ['and' => [
            ['property' => 'Karyawan', 'relation' => ['contains' => $karyawanId]],
            ['property' => 'Tanggal', 'date' => ['equals' => $tgl]],
        ]],
        'page_size' => 1,
    ]);
    return $r['results'][0] ?? null;
}
function pendingCount(): int {
    $r = notion('POST', '/v1/data_sources/' . DS_ABSENSI . '/query', [
        'filter' => ['property' => 'Status Lembur', 'select' => ['equals' => 'Diajukan']], 'page_size' => 100,
    ]);
    return count($r['results'] ?? []);
}

/* ---------------- Aksi ---------------- */
try {
    if (empty($CFG['notion_token']) || str_starts_with($CFG['notion_token'], 'ISI_')) fail('Server belum dikonfigurasi (token Notion kosong).');
    $action = p('action');

    if ($action === 'karyawan') {
        $rows = query(DS_KARYAWAN, [
            'filter' => ['property' => 'Aktif', 'checkbox' => ['equals' => true]],
            'sorts' => [['property' => 'Nama Lengkap', 'direction' => 'ascending']],
            'page_size' => 100,
        ]);
        // 'x' = 1 untuk Direktur/Approver Lembur: tidak wajib absen, tidak dihitung "belum absen" di monitor.
        out(['ok' => true, 'data' => array_map(fn($k) => ['id' => $k['id'], 'n' => txt($k, 'Nama Lengkap'), 'j' => txt($k, 'Jabatan'), 'x' => chk($k, 'Approver Lembur') ? 1 : 0], $rows)]);
    }

    $k = auth();
    $kid = $k['id'];
    $nama = txt($k, 'Nama Lengkap');
    $now = date('H:i');
    $today = date('Y-m-d');

    switch ($action) {
        case 'login':
        case 'riwayat':
            $r = notion('POST', '/v1/data_sources/' . DS_ABSENSI . '/query', [
                'filter' => ['property' => 'Karyawan', 'relation' => ['contains' => $kid]],
                'sorts' => [['property' => 'Tanggal', 'direction' => 'descending']],
                'page_size' => 40,
            ]);
            out([
                'ok' => true, 'id' => $kid, 'nama' => $nama, 'jabatan' => txt($k, 'Jabatan'), 'jk' => sel($k, 'Jenis Kelamin'),
                'rekap' => (chk($k, 'Akses Rekap Absen') || chk($k, 'Approver Lembur')) ? 1 : 0,
                'appr' => chk($k, 'Approver Lembur') ? 1 : 0,
                'lview' => (chk($k, 'Approver Lembur') || chk($k, 'Akses Rekap Absen')) ? 1 : 0,
                'pending' => (chk($k, 'Approver Lembur') || chk($k, 'Akses Rekap Absen')) ? pendingCount() : 0,
                'today' => $today, 'now' => $now,
                'rows' => array_map('rowOut', $r['results'] ?? []),
            ]);

        case 'absen':
            // Direktur (Approver Lembur) tidak absen; aplikasinya hanya untuk monitor & persetujuan lembur.
            if (chk($k, 'Approver Lembur')) fail('Akun Direktur tidak perlu absen. Gunakan menu Monitor dan Lembur.');
            $tipe = p('tipe');
            if (!in_array($tipe, ['masuk', 'pulang'], true)) fail('Tipe absen tidak dikenal.');
            $lat = (float)p('lat'); $lng = (float)p('lng');
            if (!$lat || !$lng) fail('Lokasi tidak terkirim. Coba lagi.');
            $lk = in_array(p('lk'), LOKASI_KERJA, true) ? p('lk') : 'Di Luar Kantor';
            $lok = clean(p('lok'), 60);
            $jarak = (int)p('jarak');
            $ket = clean(p('ket'), 150);
            $map = 'https://www.google.com/maps?q=' . round($lat, 7) . ',' . round($lng, 7);
            $log = trim("$now " . strtoupper($tipe) . " $lok ({$jarak}m) $ket");

            $row = todayRow($kid);
            if (!$row) {
                $props = [
                    'Judul' => ['title' => [['text' => ['content' => "$nama - $today"]]]],
                    'Tanggal' => ['date' => ['start' => $today]],
                    'Karyawan' => ['relation' => [['id' => $kid]]],
                    'Status Kehadiran' => ['select' => ['name' => 'Hadir']],
                    'Lokasi Kerja' => ['select' => ['name' => $lk]],
                    'Keterangan Lokasi' => rt($lok),
                    'Log Absen' => rt($log),
                ];
                if ($tipe === 'masuk') {
                    $mins = (int)date('G') * 60 + (int)date('i');
                    $dow = (int)date('w');
                    $late = ($dow === 0 || $dow === 6) ? 0 : max(0, $mins - (int)($CFG['jam_masuk_menit'] ?? 480));
                    $props['Jam Masuk'] = rt($now);
                    $props['Lokasi Masuk'] = ['url' => $map];
                    $props['Terlambat (menit)'] = ['number' => $late];
                } else {
                    $props['Jam Keluar'] = rt($now);
                    $props['Lokasi Pulang'] = ['url' => $map];
                }
                notion('POST', '/v1/pages', ['parent' => ['type' => 'data_source_id', 'data_source_id' => DS_ABSENSI], 'properties' => $props]);
                $msg = $tipe === 'masuk' ? "Absen MASUK tercatat pukul $now" : "Absen PULANG tercatat pukul $now (absen masuk hari ini tidak ditemukan)";
            } else {
                $old = txt($row, 'Log Absen');
                $props = ['Log Absen' => rt(($old !== '' ? "$old | " : '') . $log)];
                if ($tipe === 'masuk') {
                    if (txt($row, 'Jam Masuk') === '') {
                        $mins = (int)date('G') * 60 + (int)date('i');
                        $dow = (int)date('w');
                        $props['Jam Masuk'] = rt($now);
                        $props['Lokasi Masuk'] = ['url' => $map];
                        $props['Terlambat (menit)'] = ['number' => ($dow === 0 || $dow === 6) ? 0 : max(0, $mins - (int)($CFG['jam_masuk_menit'] ?? 480))];
                        if (sel($row, 'Lokasi Kerja') === '') $props['Lokasi Kerja'] = ['select' => ['name' => $lk]];
                        if (txt($row, 'Keterangan Lokasi') === '') $props['Keterangan Lokasi'] = rt($lok);
                        $msg = "Absen MASUK tercatat pukul $now";
                    } else {
                        $msg = "Absen MASUK ulang tercatat pukul $now";
                    }
                } else {
                    $props['Jam Keluar'] = rt($now);
                    $props['Lokasi Pulang'] = ['url' => $map];
                    if ($lk === 'Di Luar Kantor' && txt($row, 'Keterangan Lokasi') !== 'DI LUAR RADIUS KANTOR') {
                        $props['Keterangan Lokasi'] = rt(txt($row, 'Keterangan Lokasi') . ' / pulang: DI LUAR RADIUS KANTOR');
                    }
                    $msg = "Absen PULANG tercatat pukul $now";
                    if (sel($row, 'Status Lembur') === 'Disetujui' && str_contains(txt($row, 'Approval Lembur'), 'tipe otomatis')) {
                        $props += propsTarif(tarifLembur($kid, $today, $now));
                    }
                }
                notion('PATCH', '/v1/pages/' . $row['id'], ['properties' => $props]);
            }
            out(['ok' => true, 'jam' => $now, 'msg' => $msg]);

        case 'izin':
            $jenis = p('jenis');
            if (!in_array($jenis, JENIS_CUTI, true)) fail('Jenis izin tidak dikenal.');
            // Cuti khusus perempuan (mis. Cuti Melahirkan) tidak bisa diajukan karyawan laki-laki.
            if (in_array($jenis, CUTI_PEREMPUAN, true) && sel($k, 'Jenis Kelamin') === 'Laki-laki') fail("$jenis hanya untuk karyawan perempuan.");
            if (in_array($jenis, CUTI_LAKI, true) && sel($k, 'Jenis Kelamin') === 'Perempuan') fail("$jenis hanya untuk karyawan laki-laki.");
            $mulai = p('mulai');
            $selesai = trim(p('selesai'), "\" ");
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai)) fail('Tanggal mulai tidak valid.');
            if ($selesai !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai) || $selesai < $mulai)) fail('Tanggal selesai tidak valid.');
            $alasan = clean(p('alasan'), 300);
            $props = [
                'Judul' => ['title' => [['text' => ['content' => "$nama - $jenis $mulai"]]]],
                'Karyawan' => ['relation' => [['id' => $kid]]],
                'Jenis Cuti' => ['select' => ['name' => $jenis]],
                'Status Approval' => ['select' => ['name' => 'Diajukan']],
                'Tanggal Cuti' => ['date' => ['start' => $mulai, 'end' => ($selesai !== '' && $selesai !== $mulai) ? $selesai : null]],
                'Alasan' => rt($alasan),
            ];
            $fdata = $_POST['fdata'] ?? '';
            if ($fdata !== '') {
                $bin = base64_decode($fdata, true);
                if ($bin === false || strlen($bin) > 5 * 1024 * 1024) fail('Lampiran rusak atau terlalu besar (maks 5 MB).');
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($bin) ?: 'application/octet-stream';
                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) fail('Lampiran harus foto (JPG/PNG) atau PDF.');
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
                $fname = date('Ymd-His') . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $nama) . '.' . $ext;
                $fileId = notionUpload($bin, $fname, $mime);
                $props['Lampiran'] = ['files' => [['type' => 'file_upload', 'file_upload' => ['id' => $fileId], 'name' => $fname]]];
            }
            notion('POST', '/v1/pages', ['parent' => ['type' => 'data_source_id', 'data_source_id' => DS_CUTI], 'properties' => $props]);
            out(['ok' => true, 'msg' => "Pengajuan $jenis terkirim, menunggu persetujuan."]);

        case 'lembur_ajukan':
            $tgl = p('tgl', $today);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) fail('Tanggal tidak valid.');
            if ($tgl > $today) fail('Lembur hanya bisa diajukan untuk hari ini atau hari yang sudah lewat.');
            if ($tgl < date('Y-m-d', strtotime('-7 days'))) fail('Pengajuan lembur maksimal 7 hari ke belakang.');
            $alasan = clean(p('alasan'), 300);
            if (mb_strlen($alasan) < 5) fail('Tulis alasan / pekerjaan lembur (min. 5 huruf).');
            $rencana = p('rencana');
            if ($rencana !== '' && preg_match('/^\d{2}:\d{2}$/', $rencana)) $alasan .= " (rencana selesai $rencana)";
            $props = [
                'Status Lembur' => ['select' => ['name' => 'Diajukan']],
                'Keterangan Lembur' => rt($alasan),
                'Approval Lembur' => rt('Diajukan ' . date('d/m H:i') . ', menunggu persetujuan'),
                'Tipe Lembur' => ['select' => null],
                'Nominal Lembur' => ['number' => null],
                'Transport Lembur' => ['number' => null],
                'Makan Lembur' => ['number' => null],
                'Bulan Lembur' => ['select' => ['name' => bulanLembur($tgl)]],
            ];
            $row = dayRow($kid, $tgl);
            if ($row) {
                if (sel($row, 'Status Lembur') === 'Disetujui') fail('Lembur tanggal ini sudah disetujui.');
                if (sel($row, 'Status Lembur') === 'Ditolak') fail('Lembur tanggal ini sudah ditolak Pak Richard.');
                notion('PATCH', '/v1/pages/' . $row['id'], ['properties' => $props]);
            } else {
                unset($props['Tipe Lembur'], $props['Nominal Lembur'], $props['Transport Lembur'], $props['Makan Lembur']);
                $props += [
                    'Judul' => ['title' => [['text' => ['content' => "$nama - $tgl"]]]],
                    'Tanggal' => ['date' => ['start' => $tgl]],
                    'Karyawan' => ['relation' => [['id' => $kid]]],
                    'Status Kehadiran' => ['select' => ['name' => 'Hadir']],
                ];
                notion('POST', '/v1/pages', ['parent' => ['type' => 'data_source_id', 'data_source_id' => DS_ABSENSI], 'properties' => $props]);
            }
            out(['ok' => true, 'msg' => 'Pengajuan lembur terkirim ke atasan, menunggu persetujuan.']);

        case 'lembur_saya':
            $r = notion('POST', '/v1/data_sources/' . DS_ABSENSI . '/query', [
                'filter' => ['and' => [
                    ['property' => 'Karyawan', 'relation' => ['contains' => $kid]],
                    ['property' => 'Status Lembur', 'select' => ['is_not_empty' => true]],
                ]],
                'sorts' => [['property' => 'Tanggal', 'direction' => 'descending']],
                'page_size' => 30,
            ]);
            out(['ok' => true, 'today' => $today, 'rows' => array_map('rowOut', $r['results'] ?? [])]);

        case 'lembur_batal':
            // Karyawan membatalkan pengajuan lemburnya sendiri selama belum diputuskan Direktur.
            $row = rowAbsensi(p('row'));
            $owner = $row['properties']['Karyawan']['relation'][0]['id'] ?? '';
            if (nid($owner) !== nid($kid)) fail('Pengajuan lembur ini bukan milik Anda.');
            if (sel($row, 'Status Lembur') !== 'Diajukan' || chk($row, 'Disetujui Direktur')) fail('Pengajuan ini sudah diputuskan Direktur, tidak bisa dibatalkan.');
            $tglRow = $row['properties']['Tanggal']['date']['start'] ?? $today;
            if (txt($row, 'Jam Masuk') === '' && txt($row, 'Jam Keluar') === '' && txt($row, 'Log Absen') === '') {
                // Baris dibuat hanya untuk pengajuan lembur (tanpa absen): hapus agar tidak tercatat hadir.
                notion('PATCH', '/v1/pages/' . $row['id'], ['in_trash' => true]);
            } else {
                notion('PATCH', '/v1/pages/' . $row['id'], ['properties' => [
                    'Status Lembur' => ['select' => null],
                    'Keterangan Lembur' => rt(''),
                    'Bulan Lembur' => ['select' => null],
                    'Approval Lembur' => rt('Pengajuan dibatalkan oleh ' . $nama . ', ' . date('d/m/Y H:i') . ' via App'),
                ]]);
            }
            out(['ok' => true, 'msg' => 'Pengajuan lembur tanggal ' . date('d/m/Y', strtotime($tglRow)) . ' dibatalkan.']);

        case 'lembur_list':
            // Approver Lembur (Pak Richard) bisa menyetujui; Akses Rekap Absen (HRD) hanya melihat.
            if (!chk($k, 'Approver Lembur') && !chk($k, 'Akses Rekap Absen')) fail('Anda tidak punya akses melihat lembur.');
            $pend = query(DS_ABSENSI, [
                'filter' => ['property' => 'Status Lembur', 'select' => ['equals' => 'Diajukan']],
                'sorts' => [['property' => 'Tanggal', 'direction' => 'ascending']], 'page_size' => 100,
            ]);
            $done = notion('POST', '/v1/data_sources/' . DS_ABSENSI . '/query', [
                'filter' => ['and' => [
                    ['or' => [
                        ['property' => 'Status Lembur', 'select' => ['equals' => 'Disetujui']],
                        ['property' => 'Status Lembur', 'select' => ['equals' => 'Ditolak']],
                    ]],
                    ['property' => 'Tanggal', 'date' => ['on_or_after' => date('Y-m-d', strtotime('-14 days'))]],
                ]],
                'sorts' => [['property' => 'Tanggal', 'direction' => 'descending']], 'page_size' => 60,
            ]);
            out(['ok' => true, 'me' => $kid, 'appr' => chk($k, 'Approver Lembur') ? 1 : 0, 'pending' => array_map('lemburOut', $pend), 'done' => array_map('lemburOut', $done['results'] ?? [])]);

        case 'lembur_putus':
            if (!chk($k, 'Approver Lembur')) fail('Anda tidak punya akses persetujuan lembur.');
            $row = rowAbsensi(p('row'));
            $owner = $row['properties']['Karyawan']['relation'][0]['id'] ?? '';
            if ($owner === '') fail('Data lembur tidak punya karyawan.');
            if (nid($owner) === nid($kid)) fail('Lembur Anda sendiri harus disetujui approver lain.');
            $keputusan = p('keputusan');
            $catatan = clean(p('catatan'), 150);
            $tglRow = $row['properties']['Tanggal']['date']['start'] ?? $today;
            $stamp = date('d/m/Y H:i');
            if ($keputusan === 'tolak') {
                notion('PATCH', '/v1/pages/' . $row['id'], ['properties' => [
                    'Status Lembur' => ['select' => ['name' => 'Ditolak']],
                    'Nominal Lembur' => ['number' => 0],
                    'Transport Lembur' => ['number' => 0],
                    'Makan Lembur' => ['number' => 0],
                    'Bulan Lembur' => ['select' => ['name' => bulanLembur($tglRow)]],
                    'Approval Lembur' => rt("Ditolak oleh $nama, $stamp via App" . ($catatan !== '' ? " | $catatan" : '')),
                ]]);
                out(['ok' => true, 'msg' => 'Lembur ditolak.']);
            }
            if ($keputusan !== 'setuju') fail('Keputusan tidak dikenal.');
            $tr = tarifLembur($owner, $tglRow, txt($row, 'Jam Keluar'), p('tipe', 'auto'));
            $props = propsTarif($tr) + [
                'Status Lembur' => ['select' => ['name' => 'Disetujui']],
                'Bulan Lembur' => ['select' => ['name' => bulanLembur($tglRow)]],
                'Approval Lembur' => rt("Disetujui oleh $nama, $stamp via App" . ($tr['auto'] ? ' · tipe otomatis' : '') . ($catatan !== '' ? " | $catatan" : '')),
            ];
            notion('PATCH', '/v1/pages/' . $row['id'], ['properties' => $props]);
            $rp = $tr['t'] !== null ? ' Rp ' . number_format($tr['t'] + $tr['m'], 0, ',', '.') . ' (transport ' . number_format($tr['t'], 0, ',', '.') . ' + makan ' . number_format($tr['m'], 0, ',', '.') . ')' : ' (tarif lembur karyawan ini belum diisi di HRIS)';
            out(['ok' => true, 'msg' => "Lembur disetujui: {$tr['tipe']}$rp."]);

        case 'lembur_ringkasan':
            if (!chk($k, 'Approver Lembur') && !chk($k, 'Akses Rekap Absen')) fail('Anda tidak punya akses melihat lembur.');
            $bln = p('bulan', substr(bulanLembur($today), 0, 7));
            if (!preg_match('/^(\d{4})-(\d{2})$/', $bln, $mm)) fail('Bulan tidak valid.');
            $label = sprintf('%s %s', $bln, BLN_ID[(int)$mm[2]]);
            $rows = query(DS_ABSENSI, ['filter' => ['property' => 'Bulan Lembur', 'select' => ['equals' => $label]], 'page_size' => 100]);
            $per = [];
            foreach ($rows as $x) {
                $st = sel($x, 'Status Lembur');
                $nm = lemburOut($x)['nama'];
                $per[$nm] ??= ['nama' => $nm, 'Sore' => 0, 'Malam' => 0, 'Weekend' => 0, 'Lembur' => 0, 'transport' => 0, 'makan' => 0, 'total' => 0, 'diajukan' => 0, 'ditolak' => 0];
                if ($st === 'Disetujui') {
                    $tp = sel($x, 'Tipe Lembur');
                    if (isset($per[$nm][$tp])) $per[$nm][$tp]++;
                    $per[$nm]['transport'] += (float)(num($x, 'Transport Lembur') ?? 0);
                    $per[$nm]['makan'] += (float)(num($x, 'Makan Lembur') ?? 0);
                    $per[$nm]['total'] += (float)(num($x, 'Nominal Lembur') ?? 0);
                } elseif ($st === 'Diajukan') $per[$nm]['diajukan']++;
                elseif ($st === 'Ditolak') $per[$nm]['ditolak']++;
            }
            $per = array_values($per);
            usort($per, fn($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['nama'], $b['nama']));
            $y = (int)$mm[1]; $mo = (int)$mm[2]; $py = $mo === 1 ? $y - 1 : $y; $pm = $mo === 1 ? 12 : $mo - 1;
            $mulai = date('Y-m-d', strtotime(tutupBuku($py, $pm) . ' +1 day'));
            foreach ($rows as $x) { $d = $x['properties']['Tanggal']['date']['start'] ?? ''; if ($d !== '' && $d < $mulai) $mulai = $d; }
            out(['ok' => true, 'bulan' => $bln, 'label' => $label, 'mulai' => $mulai, 'akhir' => tutupBuku($y, $mo), 'rows' => $per]);

        case 'rekap':
            if (!chk($k, 'Akses Rekap Absen') && !chk($k, 'Approver Lembur')) fail('Anda tidak punya akses rekap.');
            $tgl = p('tgl', $today);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = $today;
            $rows = query(DS_ABSENSI, ['filter' => ['property' => 'Tanggal', 'date' => ['equals' => $tgl]], 'page_size' => 100]);
            out(['ok' => true, 'tgl' => $tgl, 'rows' => array_map(fn($x) => rowOut($x, true), $rows)]);

        default:
            fail('Aksi tidak dikenal.');
    }
} catch (Throwable $e) {
    error_log('[absensi-sk] ' . $e->getMessage());
    fail('Server sedang bermasalah, coba lagi. (' . mb_substr($e->getMessage(), 0, 120) . ')');
}

/** Upload file ke Notion (single part) dan kembalikan file_upload id. */
function notionUpload(string $bin, string $fname, string $mime): string {
    global $CFG;
    $u = notion('POST', '/v1/file_uploads', ['mode' => 'single_part', 'filename' => $fname, 'content_type' => $mime]);
    $base = $CFG['notion_base'] ?? 'https://api.notion.com';
    $tmp = tempnam(sys_get_temp_dir(), 'skup');
    file_put_contents($tmp, $bin);
    $ch = curl_init($base . '/v1/file_uploads/' . $u['id'] . '/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $CFG['notion_token'], 'Notion-Version: ' . NOTION_VERSION],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($tmp, $mime, $fname)],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    if ($code >= 400 || $res === false) throw new RuntimeException('Upload lampiran gagal (' . $code . ')');
    return $u['id'];
}
