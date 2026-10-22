<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/papikostik_scoring.php';
require_once __DIR__ . '/../includes/disc_scoring.php';
require_once __DIR__ . '/../includes/disc_data.php';
require_once __DIR__ . '/../includes/mbti_data.php';
requireRole('admin');

$db = getDB();
$attemptId = (int)($_GET['id'] ?? 0);

// 1. Ambil data attempt beserta nama peserta dan judul tes
$stmt = $db->prepare("
    SELECT a.*, u.nama AS nama_peserta, u.email, t.judul AS judul_tes, t.jenis, t.tipe_psikotes
    FROM attempt a
    JOIN users u ON u.id = a.user_id
    JOIN tes t ON t.id = a.tes_id
    WHERE a.id = ?
");
$stmt->execute([$attemptId]);
$attempt = $stmt->fetch();

if (!$attempt) {
    header('Location: hasil.php');
    exit;
}

// 2. Ambil data jawaban peserta
$stmt = $db->prepare("
    SELECT s.urutan, s.pertanyaan, o.label, o.teks, o.is_benar, o.nilai, j.tipe_pilihan, j.ragu_ragu
    FROM jawaban j
    JOIN soal s ON s.id = j.soal_id
    JOIN opsi o ON o.id = j.opsi_id
    WHERE j.attempt_id = ?
    ORDER BY s.urutan ASC, s.id ASC, j.tipe_pilihan ASC, o.label ASC
");
$stmt->execute([$attemptId]);
$jawaban = $stmt->fetchAll();

// 2b. Variabel rincian skor - disamakan dengan hasil_detail.php

// Rincian DISC: yang dipakai index pola; sifat / deskripsi / job match
// diambil dari includes/disc_data.php (sama seperti di hasil_detail.php).
$rincianDisc = null;
if ($attempt['tipe_psikotes'] === 'disc' && !empty($attempt['detail_skor'])) {
    $rincianDisc = json_decode($attempt['detail_skor'], true);
}

// Rincian skor Papikostik (kalau ada)
$rincianPapikostik = null;
if ($attempt['tipe_psikotes'] === 'papikostik' && !empty($attempt['detail_skor'])) {
    $rincianPapikostik = json_decode($attempt['detail_skor'], true);
}

// Rincian konversi IQ (WPT / CFIT)
$iqScore = $attempt['iq_score'] ?? null;
$iqKlasifikasi = $attempt['iq_klasifikasi'] ?? null;
$rincianWpt = null;
$jenisIq = 'WPT';
if ($attempt['jenis'] === 'logika' && !empty($attempt['detail_skor'])) {
    $tmpDetail = json_decode($attempt['detail_skor'], true);
    if (is_array($tmpDetail)) {
        if (isset($tmpDetail['wpt']) && is_array($tmpDetail['wpt'])) {
            $rincianWpt = $tmpDetail['wpt'];
        } elseif (isset($tmpDetail['cfit']) && is_array($tmpDetail['cfit'])) {
            $rincianWpt = $tmpDetail['cfit'];
            $jenisIq = 'CFIT';
        }
    }
}
$teksPenentu = strtolower(($attempt['tipe_psikotes'] ?? '') . ' ' . ($attempt['judul_tes'] ?? ''));
if (strpos($teksPenentu, 'cfit') !== false) {
    $jenisIq = 'CFIT';
} elseif (strpos($teksPenentu, 'wpt') !== false) {
    $jenisIq = 'WPT';
}

// Hasil tes eksternal 16Personalities (diisi sendiri oleh peserta)
$hasilEks = ($attempt['jenis'] === 'eksternal') ? mbtiHasilEksternal($attempt) : null;

// Tes 'logika' punya kolom Benar/Salah per jawaban.
$isLogikaSederhana = ($attempt['jenis'] === 'logika');

// Tes eksternal tidak punya rincian jawaban tersimpan.
$tampilkanDetailJawaban = ($attempt['jenis'] !== 'eksternal');

// Jumlah kolom tabel Detail Jawaban: No, Pertanyaan, Jawaban Dipilih, Ragu-ragu (+ Status kalau logika)
$totalKolomJawaban = $isLogikaSederhana ? 5 : 4;

// Helper DISC: satu kolom "pola + sifat" sebagai array baris teks
$discDef = function ($idx) {
    return discDataPola($idx !== null ? (int)$idx : null);
};

// 3. Nama file: Hasil_Nama Peserta_Nama Tes.xls
$namaPeserta = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $attempt['nama_peserta']);
$judulTes    = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $attempt['judul_tes']);

$namaPeserta = trim(preg_replace('/\s+/', ' ', $namaPeserta));
$judulTes    = trim(preg_replace('/\s+/', ' ', $judulTes));

$filename = "Hasil_" . $namaPeserta . "_" . $judulTes . ".xls";

// 4. Header download Excel (.xls / HTML Table format)
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>Hasil Tes</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
</x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
    body { font-family: 'Calibri', 'Segoe UI', sans-serif; }
    table { border-collapse: collapse; }
    td, th { font-size: 12px; }

    .title-band { background: #0f172a; color: #ffffff; font-size: 16px; font-weight: bold; padding: 10px 8px; letter-spacing: 0.5px; }
    .subtitle-band { background: #1e293b; color: #cbd5e1; font-size: 11px; padding: 4px 8px; }

    .info-label { background: #eff6ff; color: #0f172a; font-weight: bold; padding: 6px 10px; border: 1px solid #dbeafe; width: 160px; }
    .info-value { background: #ffffff; color: #1e293b; padding: 6px 10px; border: 1px solid #e2e8f0; }
    .info-value-highlight { background: #f0fdf4; color: #16a34a; font-weight: bold; padding: 6px 10px; border: 1px solid #dcfce7; font-size: 14px; }
    .info-value-warning { background: #fef2f2; color: #dc2626; font-weight: bold; padding: 6px 10px; border: 1px solid #fee2e2; }
    .info-value-note { background: #fffbeb; color: #92400e; padding: 6px 10px; border: 1px solid #fef3c7; font-style: italic; }

    .section-header { background: #3b82f6; color: #ffffff; font-weight: bold; font-size: 13px; padding: 8px; border: 1px solid #2563eb; }

    .th-header { background: #0f172a; color: #ffffff; font-weight: bold; padding: 8px; border: 1px solid #0f172a; text-align: center; vertical-align: middle; }

    .cell-no { text-align: center; vertical-align: top; border: 1px solid #e2e8f0; padding: 6px 8px; color: #64748b; font-weight: bold; }
    .cell-text { border: 1px solid #e2e8f0; padding: 6px 8px; vertical-align: top; mso-number-format: '\@'; }
    .cell-center { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: center; vertical-align: top; mso-number-format: '\@'; }
    .cell-status-benar { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: center; vertical-align: top; background: #f0fdf4; color: #16a34a; font-weight: bold; }
    .cell-status-salah { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: center; vertical-align: top; background: #fef2f2; color: #dc2626; font-weight: bold; }
    .cell-ragu { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: center; vertical-align: top; color: #d97706; font-weight: bold; }
    .cell-skor { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: center; vertical-align: middle; font-weight: bold; }
    .badge-huruf-cell { background: #1e293b; color: #fff; font-weight: bold; text-align: center; }

    .row-even .cell-no, .row-even .cell-text, .row-even .cell-center { background: #f8fafc; }

    /* ===== DISC: Gambaran Karakter (sama seperti kotak pink di hasil_detail) ===== */
    .disc-judul { background: #ff99cc; color: #e00000; font-weight: bold; text-decoration: underline; font-size: 14px; padding: 8px 12px 2px 12px; border-left: 2px solid #800080; }
    .disc-komb { background: #ff99cc; color: #5b2a5b; font-size: 11px; padding: 0 12px 4px 12px; border-left: 2px solid #800080; }
    .disc-pola { background: #ff99cc; color: #000000; font-weight: bold; font-size: 12px; padding: 0 12px 4px 12px; border-left: 2px solid #800080; text-transform: uppercase; }
    .disc-sifat { background: #ff99cc; color: #800080; font-weight: bold; font-size: 12px; padding: 1px 12px; border-left: 2px solid #800080; }
    .disc-pad { background: #ff99cc; font-size: 4px; line-height: 4px; border-left: 2px solid #800080; }
    .disc-label { color: #e00000; font-weight: bold; font-size: 13px; padding: 10px 6px 3px 6px; }
    .disc-box { background: #ffcc99; color: #111111; font-size: 12px; padding: 10px 14px; border: 2px solid #333333; text-align: justify; vertical-align: top; }

    /* ===== MBTI ===== */
    .mbti-desk { background: #f8fafc; color: #1e293b; font-size: 12px; padding: 10px 14px; border: 1px solid #e2e8f0; text-align: justify; vertical-align: top; }
</style>
</head>
<body>

<table>
    <col style="width:60px;">
    <col style="width:420px;">
    <col style="width:220px;">
    <?php if ($isLogikaSederhana): ?><col style="width:90px;"><?php endif; ?>
    <col style="width:90px;">

    <tr><td class="title-band" colspan="<?= $totalKolomJawaban ?>">LAPORAN HASIL TES</td></tr>
    <tr><td class="subtitle-band" colspan="<?= $totalKolomJawaban ?>">Psikotes Online System &middot; Diunduh <?= formatTanggal(date('Y-m-d H:i:s')) ?></td></tr>
    <tr><td colspan="<?= $totalKolomJawaban ?>" style="font-size:4px; line-height:4px;">&nbsp;</td></tr>

    <tr>
        <td class="info-label" colspan="2">Nama Peserta</td>
        <td class="info-value" colspan="<?= $totalKolomJawaban - 2 ?>"><?= e($attempt['nama_peserta']) ?></td>
    </tr>
    <tr>
        <td class="info-label" colspan="2">Email</td>
        <td class="info-value" colspan="<?= $totalKolomJawaban - 2 ?>"><?= e($attempt['email']) ?></td>
    </tr>
    <tr>
        <td class="info-label" colspan="2">Nama Tes</td>
        <td class="info-value" colspan="<?= $totalKolomJawaban - 2 ?>"><?= e($attempt['judul_tes']) ?> (<?= ucfirst($attempt['jenis']) ?><?= $attempt['tipe_psikotes'] ? ' &middot; ' . strtoupper($attempt['tipe_psikotes']) : '' ?>)</td>
    </tr>
    <tr>
        <td class="info-label" colspan="2">Waktu Mulai</td>
        <td class="info-value" colspan="<?= $totalKolomJawaban - 2 ?>"><?= formatTanggal($attempt['waktu_mulai']) ?></td>
    </tr>
    <tr>
        <td class="info-label" colspan="2">Waktu Selesai</td>
        <td class="info-value" colspan="<?= $totalKolomJawaban - 2 ?>"><?= formatTanggal($attempt['waktu_selesai']) ?></td>
    </tr>

    <?php if ($attempt['jenis'] === 'eksternal'): ?>
        <tr>
            <td class="info-label" colspan="2">Tipe Kepribadian (diisi peserta)</td>
            <td class="info-value-highlight" colspan="<?= $totalKolomJawaban - 2 ?>">
                <?= !empty($hasilEks['tipe']) ? e($hasilEks['tipe']) . (!empty($hasilEks['desk']) ? ' &middot; ' . e($hasilEks['desk']['nama']) : '') : 'Belum diisi' ?>
            </td>
        </tr>
        <tr>
            <td class="info-label" colspan="2">Link Hasil Pribadi</td>
            <td class="info-value" colspan="<?= $totalKolomJawaban - 2 ?>">
                <?= !empty($hasilEks['link']) ? e($hasilEks['link']) : 'Peserta belum menempelkan link hasil' ?>
            </td>
        </tr>
    <?php elseif ($attempt['jenis'] === 'logika' && $iqScore !== null): ?>
        <tr>
            <td class="info-label" colspan="2">Skor IQ</td>
            <td class="info-value-highlight" colspan="<?= $totalKolomJawaban - 2 ?>">IQ <?= (int)$iqScore ?> &middot; <?= e($iqKlasifikasi ?? '-') ?></td>
        </tr>
        <tr>
            <td class="info-label" colspan="2">Jawaban Benar</td>
            <td class="info-value" colspan="<?= $totalKolomJawaban - 2 ?>"><?= (int)$attempt['total_benar'] ?> dari <?= (int)$attempt['total_soal'] ?></td>
        </tr>
    <?php elseif ($attempt['jenis'] === 'logika'): ?>
        <tr>
            <td class="info-label" colspan="2">Total Skor</td>
            <td class="info-value-highlight" colspan="<?= $totalKolomJawaban - 2 ?>"><?= (int)$attempt['total_skor'] ?></td>
        </tr>
        <tr>
            <td class="info-label" colspan="2">Jawaban Benar</td>
            <td class="info-value" colspan="<?= $totalKolomJawaban - 2 ?>"><?= (int)$attempt['total_benar'] ?> dari <?= (int)$attempt['total_soal'] ?></td>
        </tr>
    <?php else: ?>
        <tr>
            <td class="info-label" colspan="2">Profil Kepribadian</td>
            <td class="info-value-highlight" colspan="<?= $totalKolomJawaban - 2 ?>"><?= e($attempt['profil_hasil'] ?? '-') ?></td>
        </tr>
    <?php endif; ?>

    <?php if (!empty($attempt['flag_curang'])): ?>
    <tr>
        <td class="info-label" colspan="2">Indikasi Pelanggaran</td>
        <td class="info-value-warning" colspan="<?= $totalKolomJawaban - 2 ?>"><?= (int)$attempt['pelanggaran_count'] ?> kali (berpindah tab/aplikasi)</td>
    </tr>
    <?php endif; ?>
</table>

<?php if ($hasilEks && !empty($hasilEks['tipe'])): ?>
<table style="margin-top:14px;">
    <col style="width:200px;"><col style="width:260px;"><col style="width:140px;">
    <tr><td class="section-header" colspan="3">HASIL YANG DIISI PESERTA (16PERSONALITIES)</td></tr>
    <?php if ($hasilEks['rows']): ?>
    <tr>
        <th class="th-header">Sifat</th>
        <th class="th-header">Sisi</th>
        <th class="th-header">Persentase</th>
    </tr>
    <?php foreach ($hasilEks['rows'] as $i => $r): ?>
    <tr class="<?= $i % 2 === 1 ? 'row-even' : '' ?>">
        <td class="cell-text" style="font-weight:bold;"><?= e($r[0]) ?></td>
        <td class="cell-text"><?= e($r[1]) ?></td>
        <td class="cell-skor"><?= e($r[2]) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php else: ?>
    <tr><td class="info-value-note" colspan="3">Persentase sifat tidak tersimpan untuk attempt ini.</td></tr>
    <?php endif; ?>

    <?php if (!empty($hasilEks['desk'])): ?>
    <tr><td colspan="3" style="font-size:4px; line-height:4px;">&nbsp;</td></tr>
    <tr><td class="section-header" colspan="3">DESKRIPSI TIPE KEPRIBADIAN &middot; <?= e($hasilEks['tipe']) ?> (<?= e($hasilEks['desk']['nama']) ?>)</td></tr>
    <tr><td class="mbti-desk" colspan="3"><?= e($hasilEks['desk']['deskripsi']) ?></td></tr>
    <?php endif; ?>
</table>
<?php endif; ?>

<?php if ($rincianWpt): ?>
<table style="margin-top:14px;">
    <col style="width:150px;"><col style="width:120px;"><col style="width:120px;"><col style="width:150px;"><col style="width:80px;"><col style="width:170px;">
    <tr><td class="section-header" colspan="6">RINCIAN KONVERSI IQ (<?= e($jenisIq) ?>)</td></tr>
    <tr>
        <th class="th-header">Skor Mentah (Benar)</th>
        <th class="th-header">Usia</th>
        <th class="th-header">Koreksi Usia</th>
        <th class="th-header">Skor Setelah Koreksi</th>
        <th class="th-header">IQ</th>
        <th class="th-header">Klasifikasi</th>
    </tr>
    <tr>
        <td class="cell-center"><?= (int)($rincianWpt['skor_mentah'] ?? 0) ?></td>
        <td class="cell-center"><?= isset($rincianWpt['usia']) && $rincianWpt['usia'] !== null ? (int)$rincianWpt['usia'] . ' th' : 'tidak diketahui' ?></td>
        <td class="cell-center">+<?= (int)($rincianWpt['koreksi_usia'] ?? 0) ?></td>
        <td class="cell-center"><?= (int)($rincianWpt['skor_koreksi'] ?? 0) ?></td>
        <td class="cell-skor"><?= (int)($rincianWpt['iq'] ?? 0) ?></td>
        <td class="cell-center" style="font-weight:bold;"><?= e($rincianWpt['klasifikasi'] ?? '-') ?></td>
    </tr>
</table>
<?php endif; ?>

<?php if ($rincianDisc): ?>
<?php
    $defMask   = $discDef($rincianDisc['index_most']    ?? null);
    $defCore   = $discDef($rincianDisc['index_least']   ?? null);
    $defMirror = $discDef($rincianDisc['index_selisih'] ?? null);

    $sifatMask = $defMask ? array_values($defMask['sifat']) : ['Pola tidak ditemukan'];
    $sifatCore = $defCore ? array_values($defCore['sifat']) : ['Pola tidak ditemukan'];
    $sifatMirr = $defMirror ? array_values($defMirror['sifat']) : ['Pola tidak ditemukan'];
    $maksBaris = max(count($sifatMask), count($sifatCore));
?>
<table style="margin-top:14px;">
    <col style="width:450px;"><col style="width:450px;">
    <tr><td class="section-header" colspan="2">GAMBARAN KARAKTER (DISC)</td></tr>

    <!-- Mask Public Self (kiri) & Core Private Self (kanan) -->
    <tr>
        <td class="disc-judul">Mask Public Self</td>
        <td class="disc-judul" style="border-right:2px solid #800080;">Core Private Self</td>
    </tr>
    <tr>
        <td class="disc-komb">Gaya di depan orang lain &middot; kombinasi <?= e(DISC_KOMBINASI_40[$rincianDisc['index_most'] ?? 0] ?? '-') ?></td>
        <td class="disc-komb" style="border-right:2px solid #800080;">Gaya asli di bawah tekanan &middot; kombinasi <?= e(DISC_KOMBINASI_40[$rincianDisc['index_least'] ?? 0] ?? '-') ?></td>
    </tr>
    <tr>
        <td class="disc-pola"><?= $defMask ? e($defMask['nama']) : '#N/A' ?></td>
        <td class="disc-pola" style="border-right:2px solid #800080;"><?= $defCore ? e($defCore['nama']) : '#N/A' ?></td>
    </tr>
    <?php for ($r = 0; $r < $maksBaris; $r++): ?>
    <tr>
        <td class="disc-sifat"><?= isset($sifatMask[$r]) ? e($sifatMask[$r]) : '&nbsp;' ?></td>
        <td class="disc-sifat" style="border-right:2px solid #800080;"><?= isset($sifatCore[$r]) ? e($sifatCore[$r]) : '&nbsp;' ?></td>
    </tr>
    <?php endfor; ?>
    <tr><td class="disc-pad" style="height:10px;">&nbsp;</td><td class="disc-pad" style="height:10px; border-right:2px solid #800080;">&nbsp;</td></tr>

    <!-- Mirror Perceived Self (Profil Akhir) -->
    <tr>
        <td class="disc-judul" colspan="2" style="border-right:2px solid #800080;">Mirror Perceived Self (Profil Akhir)</td>
    </tr>
    <tr>
        <td class="disc-komb" colspan="2" style="border-right:2px solid #800080;">Gaya inti (selisih Most &minus; Least) &middot; kombinasi <?= e(DISC_KOMBINASI_40[$rincianDisc['index_selisih'] ?? 0] ?? '-') ?></td>
    </tr>
    <tr>
        <td class="disc-pola" colspan="2" style="border-right:2px solid #800080;"><?= $defMirror ? e($defMirror['nama']) : '#N/A' ?></td>
    </tr>
    <?php foreach ($sifatMirr as $s): ?>
    <tr>
        <td class="disc-sifat" colspan="2" style="border-right:2px solid #800080;"><?= e($s) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr><td class="disc-pad" colspan="2" style="height:10px; border-right:2px solid #800080; border-bottom:2px solid #800080;">&nbsp;</td></tr>

    <!-- Deskripsi Kepribadian & Job Match (dari pola Mirror) -->
    <tr><td class="disc-label" colspan="2">Deskripsi Kepribadian</td></tr>
    <tr><td class="disc-box" colspan="2"><?= $defMirror ? e($defMirror['deskripsi']) : 'Deskripsi tidak tersedia (pola tidak ditemukan).' ?></td></tr>
    <tr><td class="disc-label" colspan="2">Job Match :</td></tr>
    <tr><td class="disc-box" colspan="2"><?= $defMirror ? e($defMirror['job']) : 'Job match tidak tersedia (pola tidak ditemukan).' ?></td></tr>
</table>
<?php endif; ?>

<?php if ($rincianPapikostik): ?>
<table style="margin-top:14px;">
    <col style="width:50px;"><col style="width:330px;"><col style="width:60px;"><col style="width:520px;">
    <tr><td class="section-header" colspan="4">RINCIAN SKOR PAPIKOSTIK (PAPI KOSTICK)</td></tr>
    <tr>
        <th class="th-header">Dim.</th>
        <th class="th-header">Nama Dimensi</th>
        <th class="th-header">Skor</th>
        <th class="th-header">Interpretasi</th>
    </tr>
    <?php foreach (PAPIKOSTIK_DIMENSI as $idx => $h): ?>
    <tr class="<?= $idx % 2 === 1 ? 'row-even' : '' ?>">
        <td class="badge-huruf-cell"><?= e($h) ?></td>
        <td class="cell-text"><?= e(PAPIKOSTIK_LABEL[$h] ?? $h) ?></td>
        <td class="cell-skor"><?= (int)($rincianPapikostik['skor'][$h] ?? 0) ?></td>
        <td class="cell-text"><?= e($rincianPapikostik['interpretasi'][$h] ?? '-') ?></td>
    </tr>
    <?php endforeach; ?>
    <tr><td colspan="4" style="font-size:4px; line-height:4px;">&nbsp;</td></tr>
    <tr>
        <td class="info-label" colspan="1">3 Dimensi Dominan</td>
        <td class="info-value-highlight" colspan="3"><?= e(implode(', ', $rincianPapikostik['dominan'] ?? [])) ?></td>
    </tr>
</table>
<?php endif; ?>

<?php if ($tampilkanDetailJawaban): ?>
<table style="margin-top:14px;">
    <col style="width:60px;">
    <col style="width:420px;">
    <col style="width:220px;">
    <?php if ($isLogikaSederhana): ?><col style="width:90px;"><?php endif; ?>
    <col style="width:90px;">

    <tr><td class="section-header" colspan="<?= $totalKolomJawaban ?>">DETAIL JAWABAN (<?= count($jawaban) ?> Baris)</td></tr>

    <tr>
        <th class="th-header">No</th>
        <th class="th-header">Pertanyaan</th>
        <th class="th-header">Jawaban Dipilih</th>
        <?php if ($isLogikaSederhana): ?><th class="th-header">Status</th><?php endif; ?>
        <th class="th-header">Ragu-ragu</th>
    </tr>

    <?php foreach ($jawaban as $idx => $j): ?>
    <tr class="<?= $idx % 2 === 1 ? 'row-even' : '' ?>">
        <td class="cell-no"><?= $idx + 1 ?></td>
        <td class="cell-text">
            <?= nl2br(e($j['pertanyaan'])) ?>
            <?php if (!empty($j['tipe_pilihan']) && $j['tipe_pilihan'] !== 'single'): ?>
                <br><span style="color:#4338ca; font-weight:bold; font-size:10.5px; text-transform:uppercase;">(<?= e($j['tipe_pilihan']) ?>)</span>
            <?php endif; ?>
        </td>
        <td class="cell-text"><?= e($j['label'] ?? '-') ?><?= $j['teks'] ? '. ' . e($j['teks']) : '' ?></td>
        <?php if ($isLogikaSederhana): ?>
            <td class="<?= $j['is_benar'] ? 'cell-status-benar' : 'cell-status-salah' ?>"><?= $j['is_benar'] ? 'BENAR' : 'SALAH' ?></td>
        <?php endif; ?>
        <td class="cell-ragu"><?= !empty($j['ragu_ragu']) ? 'YA' : '-' ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

</body>
</html>