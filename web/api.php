<?php
/**
 * API Absensi SK — dipasang di cPanel: public_html/absensi-sk/api.php
 * Menghubungkan aplikasi absensi langsung ke Notion HRIS (tanpa Make).
 *
 * Aksi (POST form): karyawan | login | riwayat | absen | izin | rekap
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
const JENIS_CUTI = ['Izin', 'Sakit (Surat Dokter)', 'Cuti Tahunan', 'Cuti Sakit', 'Cuti Menikah', 'Cuti Melahirkan', 'Cuti Duka', 'Cuti Tanpa Gaji'];
const LOKASI_KERJA = ['Head Office', 'Operation Office', 'WFH', 'Lapangan', 'Di Luar Kantor'];

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

function todayRow(string $karyawanId): ?array {
    $r = notion('POST', '/v1/data_sources/' . DS_ABSENSI . '/query', [
        'filter' => ['and' => [
            ['property' => 'Karyawan', 'relation' => ['contains' => $karyawanId]],
            ['property' => 'Tanggal', 'date' => ['equals' => date('Y-m-d')]],
        ]],
        'page_size' => 1,
    ]);
    return $r['results'][0] ?? null;
}

function rowOut(array $x, bool $withKaryawan = false): array {
    $o = [
        't'  => $x['properties']['Tanggal']['date']['start'] ?? '',
        's'  => sel($x, 'Status Kehadiran'),
        'm'  => txt($x, 'Jam Masuk'),
        'k'  => txt($x, 'Jam Keluar'),
        'l'  => txt($x, 'Keterangan Lokasi'),
        'tl' => (string)(num($x, 'Terlambat (menit)') ?? ''),
        'lb' => (string)(num($x, 'Jam Lembur') ?? ''),
    ];
    if ($withKaryawan) $o['id'] = $x['properties']['Karyawan']['relation'][0]['id'] ?? '';
    return $o;
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
        out(['ok' => true, 'data' => array_map(fn($k) => ['id' => $k['id'], 'n' => txt($k, 'Nama Lengkap'), 'j' => txt($k, 'Jabatan')], $rows)]);
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
                'ok' => true, 'id' => $kid, 'nama' => $nama, 'jabatan' => txt($k, 'Jabatan'),
                'rekap' => chk($k, 'Akses Rekap Absen') ? 1 : 0,
                'today' => $today, 'now' => $now,
                'rows' => array_map('rowOut', $r['results'] ?? []),
            ]);

        case 'absen':
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
                        $props['Jam Masuk'] = rt($now);
                        $props['Lokasi Masuk'] = ['url' => $map];
                    }
                    $msg = "Absen MASUK ulang tercatat pukul $now";
                } else {
                    $props['Jam Keluar'] = rt($now);
                    $props['Lokasi Pulang'] = ['url' => $map];
                    if ($lk === 'Di Luar Kantor' && txt($row, 'Keterangan Lokasi') !== 'DI LUAR RADIUS KANTOR') {
                        $props['Keterangan Lokasi'] = rt(txt($row, 'Keterangan Lokasi') . ' / pulang: DI LUAR RADIUS KANTOR');
                    }
                    $msg = "Absen PULANG tercatat pukul $now";
                }
                notion('PATCH', '/v1/pages/' . $row['id'], ['properties' => $props]);
            }
            out(['ok' => true, 'jam' => $now, 'msg' => $msg]);

        case 'izin':
            $jenis = p('jenis');
            if (!in_array($jenis, JENIS_CUTI, true)) fail('Jenis izin tidak dikenal.');
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

        case 'rekap':
            if (!chk($k, 'Akses Rekap Absen')) fail('Anda tidak punya akses rekap.');
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
