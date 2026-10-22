<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/peserta_summary.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Dompdf via Composer
requireRole('admin');

use Dompdf\Dompdf;
use Dompdf\Options;

$summary = ambilSummaryPeserta(getDB(), (int)($_GET['user_id'] ?? 0));
if (!$summary) { header('Location: hasil.php'); exit; }

$p = $summary['peserta'];
$infoSeleksi = ambilInfoSeleksiPeserta((int)($_GET['user_id'] ?? 0));

// Tes IQ (WPT / CFIT): tidak punya kartu rincian, cukup baris di Ringkasan Hasil
function pdfIsTesIq(string $judul): bool
{
    return stripos($judul, 'wpt') !== false || stripos($judul, 'cfit') !== false;
}

// CFIT subtes 1-3 tidak ditampilkan; hanya CFIT 4 (yang punya IQ)
function pdfSembunyikanCfit(string $judul): bool
{
    return (bool)preg_match('/cfit\s*[123]\b/i', $judul);
}

// Nama tes + tipe: WPT - Logika, CFIT - Grafis (angka subtes dibuang)
function pdfNamaDenganTipe(string $judul): string
{
    if (stripos($judul, 'cfit') !== false) return 'Tes CFIT - Grafis';
    if (stripos($judul, 'wpt') !== false)  return $judul . ' - Logika';
    return $judul;
}

// Beri tebal pada label catatan (mis. "Deskripsi Kepribadian:", "Job Match:")
function pdfRapikanCatatan(string $html): string
{
    return preg_replace(
        '/<p class="summary-note">(Deskripsi Kepribadian|Job Match|Deskripsi tipe kepribadian|Link hasil|Konversi IQ [A-Z]+|3 dimensi dengan skor tertinggi):/u',
        '<p class="summary-note"><strong>$1:</strong>',
        $html
    );
}

// Buang warna inline (background & color) dari tabel supaya CSS hijau DISC bisa berlaku
function pdfBuangWarnaInline(string $html): string
{
    return preg_replace_callback('/\sstyle="([^"]*)"/i', function ($m) {
        $s = preg_replace('/(?<![-\w])background(?:-color)?\s*:[^;]*;?/i', '', $m[1]);
        $s = preg_replace('/(?<![-\w])color\s*:[^;]*;?/i', '', $s);
        $s = trim($s);
        return $s === '' ? '' : ' style="' . $s . '"';
    }, $html);
}

// Ringkasan Hasil: semua tes kecuali CFIT 1-3 (termasuk Tes Potensi Akademik)
$semua = array_values(array_filter($summary['sections'], function ($s) {
    return !pdfSembunyikanCfit($s['judul']);
}));

// Kartu per tes: hanya yang punya tabel/catatan dan bukan WPT/CFIT.
// Key array (nomor urut di ringkasan) sengaja dipertahankan.
$sections = array_filter($semua, function ($s) {
    return (!empty($s['tables']) || !empty($s['notes'])) && !pdfIsTesIq($s['judul']);
});

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 30px 34px 46px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; line-height: 1.45; }

    /* ===== Banner judul ===== */
    /* ===== Banner judul (indigo soft) ===== */
.banner { background: #eef2ff; border: 1px solid #c7d2fe; color: #3730a3; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px; }
.banner .judul { font-size: 18px; font-weight: bold; letter-spacing: .3px; margin: 0 0 2px; color: #312e81; }
.banner .sub { font-size: 9px; color: #6366f1; margin: 0 0 12px; }
.banner table { width: 100%; border-collapse: collapse; }
.banner td { padding: 0; vertical-align: top; font-size: 10px; color: #3730a3; }
.banner .lbl { font-size: 8px; color: #6366f1; text-transform: uppercase; letter-spacing: .6px; }
.banner .val { font-size: 12px; font-weight: bold; color: #1e1b4b; }

    /* ===== Judul bagian ===== */
    .judul-bagian { font-size: 12px; font-weight: bold; color: #312e81; margin: 0 0 6px; }

    /* ===== Tabel ringkasan ===== */
    .ringkasan { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .ringkasan th { background: #eef2ff; color: #3730a3; font-size: 9px; text-transform: uppercase; letter-spacing: .4px;
                    padding: 6px 8px; text-align: left; border-bottom: 2px solid #c7d2fe; }
    .ringkasan td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; font-size: 9.5px; }
    .ringkasan tr.zebra td { background: #f8fafc; }
    .ringkasan .hasil-sel { font-weight: bold; color: #4f46e5; }
    .ringkasan tr { page-break-inside: avoid; }

    /* ===== Kartu per tes ===== */
    .section { margin-bottom: 20px; }
    .section-head { width: 100%; border-collapse: collapse; background: #f1f5f9; border-left: 5px solid #4f46e5; margin-bottom: 8px; page-break-after: avoid; }
    .section-head td { padding: 9px 12px; vertical-align: middle; }
    .section-head .no { font-size: 9px; color: #6366f1; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; }
    .section-head .nama { font-size: 13px; font-weight: bold; color: #0f172a; }
    .section-head .tgl { font-size: 8.5px; color: #64748b; }
    .section-head .hasil { font-size: 14px; font-weight: bold; color: #4f46e5; text-align: right; }
    .section-head .ket { font-size: 8.5px; color: #64748b; text-align: right; }

    /* ===== Tabel rincian ===== */
    .summary-table { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
    .summary-table th { background: #eef2ff; color: #3730a3; font-size: 8.5px; padding: 5px 7px; text-align: center;
                        border: 1px solid #c7d2fe; }
    .summary-table td { font-size: 9px; padding: 4px 7px; text-align: center; border: 1px solid #e2e8f0; vertical-align: top; }
    .summary-table tr { page-break-inside: avoid; }

    /* ===== Tabel rincian DISC saja: header hijau soft, badan polos bergaris ===== */
    .sec-disc table th,
    .sec-disc table thead td,
    .sec-disc table tr:first-child td { background: #dcfce7 !important; color: #166534 !important; border: 1px solid #86efac !important; }
    .sec-disc table td { background: #ffffff !important; color: #1e293b !important; border: 1px solid #cbd5e1 !important; }
    .sec-disc table tr:first-child td,
    .sec-disc table thead td { background: #dcfce7 !important; color: #166534 !important; border: 1px solid #86efac !important; }

    /* ===== Catatan ===== */
    .summary-note { font-size: 9px; color: #334155; margin: 0 0 6px; padding: 7px 10px; background: #f8fafc;
                    border-left: 3px solid #cbd5e1; text-align: justify; word-wrap: break-word; }

    .kosong { text-align: center; color: #64748b; padding: 30px 0; font-size: 11px; }
</style>
</head>
<body>

    <div class="banner">
        <div class="judul">Summary Hasil Psikotes</div>
        <div class="sub">Rangkuman hasil seluruh tes peserta</div>
        <table>
            <tr>
                <td style="width:40%;"><div class="lbl">Nama</div><div class="val"><?= e($p['nama']) ?></div></td>
                <td style="width:36%;"><div class="lbl">Email</div><div class="val"><?= e($p['email']) ?></div></td>
                <td style="width:24%;"><div class="lbl">Dicetak</div><div class="val"><?= e($summary['dibuat']) ?></div></td>
            </tr>
            <tr>
                <td style="padding-top:8px;"><div class="lbl">Jabatan Dilamar</div><div class="val"><?= e($infoSeleksi['jabatan_dilamar'] ?: '-') ?></div></td>
                <td style="padding-top:8px;"><div class="lbl">Status Seleksi</div><div class="val"><?= e(labelStatusSeleksi($infoSeleksi['status_seleksi'])) ?></div></td>
                <td></td>
            </tr>
        </table>
    </div>

    <?php if (!$semua): ?>
        <div class="kosong">Belum ada hasil tes untuk ditampilkan.</div>
    <?php else: ?>

    <div class="judul-bagian">Ringkasan Hasil</div>
    <table class="ringkasan">
        <thead>
            <tr>
                <th style="width:24px;">No</th>
                <th>Nama Tes</th>
                <th style="width:78px;">Selesai</th>
                <th style="width:110px;">Hasil Utama</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($semua as $i => $s): ?>
            <tr class="<?= $i % 2 ? 'zebra' : '' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= e(pdfNamaDenganTipe($s['judul'])) ?></td>
                <td><?= e($s['tanggal']) ?></td>
                <td class="hasil-sel"><?= e($s['hasil']) ?></td>
                <td><?= e($s['keterangan']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php foreach ($sections as $i => $s): ?>
        <?php
            $isDisc = stripos($s['judul'], 'disc') !== false;
            $isiRincian = pdfRapikanCatatan(renderSectionTablesHtml($s));
            if ($isDisc) $isiRincian = pdfBuangWarnaInline($isiRincian);
        ?>
        <div class="section<?= $isDisc ? ' sec-disc' : '' ?>">
            <table class="section-head">
                <tr>
                    <td>
                        <div class="no">Tes <?= $i + 1 ?></div>
                        <div class="nama"><?= e($s['judul']) ?></div>
                        <div class="tgl">Selesai: <?= e($s['tanggal']) ?></div>
                    </td>
                    <td style="width:42%;">
                        <div class="hasil"><?= e($s['hasil']) ?></div>
                        <?php if ($s['keterangan'] !== ''): ?>
                            <div class="ket"><?= e($s['keterangan']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?= $isiRincian ?>
        </div>
    <?php endforeach; ?>

    <?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Footer: nama peserta di kiri, nomor halaman di kanan
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
$w = $canvas->get_width();
$h = $canvas->get_height();
$canvas->page_text(34, $h - 30, 'Summary Hasil Psikotes - ' . $p['nama'], $font, 8, [0.45, 0.5, 0.58]);
$canvas->page_text($w - 100, $h - 30, 'Halaman {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.45, 0.5, 0.58]);

$namaFile = 'summary_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $p['nama']) . '.pdf';
$dompdf->stream($namaFile, ['Attachment' => true]);
exit;