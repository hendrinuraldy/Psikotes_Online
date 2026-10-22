<?php
/**
 * Export Excel summary peserta TANPA library (tidak perlu composer / PhpSpreadsheet).
 * Membuat file .xlsx asli memakai ZipArchive bawaan PHP (ekstensi "zip").
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/peserta_summary.php';
requireRole('admin');

if (!class_exists('ZipArchive')) {
    die('Ekstensi PHP "zip" belum aktif. Di Laragon: Menu > PHP > Extensions > centang zip, lalu restart.');
}

$summary = ambilSummaryPeserta(getDB(), (int)($_GET['user_id'] ?? 0));
if (!$summary) { header('Location: hasil.php'); exit; }
$p = $summary['peserta'];
$infoSeleksi = ambilInfoSeleksiPeserta((int)($_GET['user_id'] ?? 0));

/* ---------------- Helper nama tes ---------------- */

// CFIT subtes 1-3 tidak ditampilkan; hanya CFIT 4 (yang punya IQ)
function xlsSembunyikanCfit(string $judul): bool
{
    return (bool)preg_match('/cfit\s*[123]\b/i', $judul);
}

// Nama tes + tipe: "Tes WPT - Logika", "Tes CFIT - Grafis" (angka subtes dibuang)
function xlsNamaDenganTipe(string $judul): string
{
    if (stripos($judul, 'cfit') !== false) return 'Tes CFIT - Grafis';
    if (stripos($judul, 'wpt') !== false)  return 'Tes WPT - Logika';
    return $judul;
}

// Semua tes kecuali CFIT 1-3; nomor urut dihitung ulang
$semua = array_values(array_filter($summary['sections'], function ($s) {
    return !xlsSembunyikanCfit($s['judul']);
}));

/* ---------------- Helper XLSX ---------------- */

// Gaya sel: 0 biasa, 1 header tabel, 2 tebal, 3 judul besar, 4 catatan, 5 isi tabel (border)
const XS_NORMAL = 0, XS_HEADER = 1, XS_BOLD = 2, XS_TITLE = 3, XS_NOTE = 4, XS_BODY = 5;

function xlsxCol(int $n): string
{
    $s = '';
    while ($n > 0) {
        $m = ($n - 1) % 26;
        $s = chr(65 + $m) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

function xlsxEsc($v): string
{
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v);
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** $rows = array baris; tiap sel = nilai, atau [nilai, gaya]. */
function xlsxSheetXml(array $rows): string
{
    $lebar = [];
    $data = '';
    foreach ($rows as $ri => $row) {
        $r = $ri + 1;
        $data .= '<row r="' . $r . '">';
        foreach ($row as $ci => $cell) {
            [$v, $s] = is_array($cell) ? $cell : [$cell, XS_NORMAL];
            $ref = xlsxCol($ci + 1) . $r;

            if ($s !== XS_TITLE && $s !== XS_NOTE) {
                $lebar[$ci] = max($lebar[$ci] ?? 0, mb_strlen((string)$v));
            }

            if ($v === null || $v === '') {
                if ($s) { $data .= '<c r="' . $ref . '" s="' . $s . '"/>'; }
                continue;
            }
            if (is_int($v) || is_float($v)) {
                $data .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
            } else {
                $data .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">'
                       . xlsxEsc($v) . '</t></is></c>';
            }
        }
        $data .= '</row>';
    }

    $cols = '';
    foreach ($lebar as $ci => $len) {
        $w = min(60, max(8, $len + 3));
        $cols .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $w . '" customWidth="1"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
        . '<sheetData>' . $data . '</sheetData></worksheet>';
}

function xlsxStylesXml(): string
{
    $border = '<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right>'
            . '<top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="14"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function judulSheetAman(string $judul, int $no): string
{
    $bersih = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $judul);
    return mb_substr($no . '. ' . trim($bersih), 0, 31);
}

/* ---------------- Susun isi sheet ---------------- */

$sheets = [];

// Sheet 1: Ringkasan
$rows = [];
$rows[] = [['Summary Hasil Psikotes', XS_TITLE]];
$rows[] = [];
$rows[] = [['Nama', XS_BOLD], $p['nama']];
$rows[] = [['Email', XS_BOLD], $p['email']];
$rows[] = [['Jabatan Dilamar', XS_BOLD], $infoSeleksi['jabatan_dilamar'] ?: '-'];
$rows[] = [['Status Seleksi', XS_BOLD], [labelStatusSeleksi($infoSeleksi['status_seleksi']), XS_BOLD]];
$rows[] = [['Dicetak', XS_BOLD], $summary['dibuat']];
$rows[] = [];
$rows[] = array_map(fn($h) => [$h, XS_HEADER], ['No', 'Nama Tes', 'Selesai', 'Hasil Utama', 'Keterangan']);
foreach ($semua as $i => $s) {
    $rows[] = [
        [$i + 1, XS_BODY],
        [xlsNamaDenganTipe($s['judul']), XS_BODY],
        [$s['tanggal'], XS_BODY],
        [$s['hasil'], XS_BODY],
        [$s['keterangan'], XS_BODY],
    ];
}
if (!$semua) {
    $rows[] = [['Peserta ini belum menyelesaikan tes apa pun.', XS_NOTE]];
}
$sheets[] = ['nama' => 'Ringkasan', 'rows' => $rows];

// Sheet per tes
foreach ($semua as $i => $s) {
    // Potensi Akademik, WPT, dan CFIT hanya tampil di Ringkasan, tanpa sheet sendiri
    if (stripos($s['judul'], 'potensi akademik') !== false
        || stripos($s['judul'], 'wpt') !== false
        || stripos($s['judul'], 'cfit') !== false) {
        continue;
    }

    $namaTes = xlsNamaDenganTipe($s['judul']);

    $rows = [];
    $rows[] = [[$namaTes, XS_TITLE]];
    $rows[] = [];
    $rows[] = [['Selesai', XS_BOLD], $s['tanggal']];
    $rows[] = [['Hasil Utama', XS_BOLD], [$s['hasil'], XS_BOLD]];
    $rows[] = [['Keterangan', XS_BOLD], $s['keterangan']];
    $rows[] = [];

    foreach ($s['tables'] as $t) {
        $rows[] = array_map(fn($h) => [$h, XS_HEADER], $t['headers']);
        foreach ($t['rows'] as $rowData) {
            $rows[] = array_map(fn($v) => [$v, XS_BODY], $rowData);
        }
        $rows[] = [];
    }
    foreach ($s['notes'] as $n) {
        $rows[] = [[$n, XS_NOTE]];
    }

    $sheets[] = ['nama' => judulSheetAman($namaTes, $i + 1), 'rows' => $rows];
}

/* ---------------- Tulis file .xlsx ---------------- */

$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('Gagal membuat file Excel sementara.');
}

$n = count($sheets);

$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
for ($i = 1; $i <= $n; $i++) {
    $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
}
$ct .= '</Types>';
$zip->addFromString('[Content_Types].xml', $ct);

$zip->addFromString('_rels/.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>');

$wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
    . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
foreach ($sheets as $i => $sh) {
    $id = $i + 1;
    $wb .= '<sheet name="' . xlsxEsc($sh['nama']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
    $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
    $zip->addFromString('xl/worksheets/sheet' . $id . '.xml', xlsxSheetXml($sh['rows']));
}
$wb .= '</sheets></workbook>';
$rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

$zip->addFromString('xl/workbook.xml', $wb);
$zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
$zip->addFromString('xl/styles.xml', xlsxStylesXml());
$zip->close();

$namaFile = 'summary_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $p['nama']) . '.xlsx';
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $namaFile . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: max-age=0');
readfile($tmp);
unlink($tmp);
exit;