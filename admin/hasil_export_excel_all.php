<?php
require_once __DIR__ . '/../includes/attempt_auto.php';
finalisasiAttemptKadaluarsa(getDB());

$db = getDB();
$pesertaId = (int)($_GET['peserta_id'] ?? 0);
$tesId = (int)($_GET['tes_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];
if ($pesertaId > 0) { $where[] = 'a.user_id = ?'; $params[] = $pesertaId; }
if ($tesId > 0) { $where[] = 'a.tes_id = ?'; $params[] = $tesId; }

if ($search !== '') {
    if ($pesertaId > 0) {
        // Sama seperti halaman detail peserta: cari hanya di nama tes
        $where[] = 't.judul LIKE ?';
        $params[] = "%{$search}%";
    } else {
        $where[] = '(u.nama LIKE ? OR u.email LIKE ? OR t.judul LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT a.*, u.nama AS nama_peserta, u.email, t.judul AS judul_tes, t.jenis, t.tipe_psikotes
    FROM attempt a
    JOIN users u ON u.id = a.user_id
    JOIN tes t ON t.id = a.tes_id
    $whereSql
    ORDER BY a.waktu_mulai DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$hasilList = $stmt->fetchAll();

$namaFile = 'Rekap_Hasil_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $namaFile . '"');
header('Cache-Control: max-age=0');

// BOM supaya karakter UTF-8 tampil benar di Excel
echo "\xEF\xBB\xBF";

$totalSelesai = count(array_filter($hasilList, fn($h) => $h['status'] === 'selesai'));
$totalBerlangsung = count($hasilList) - $totalSelesai;
$totalPelanggaran = array_sum(array_map(fn($h) => (int)$h['pelanggaran_count'], $hasilList));
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>Rekap Hasil</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
<x:FreezePanes/>
<x:FrozenNoSplit/>
<x:SplitHorizontal>11</x:SplitHorizontal>
<x:TopRowBottomPane>11</x:TopRowBottomPane>
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

    .title-band {
        background: #0f172a;
        color: #ffffff;
        font-size: 16px;
        font-weight: bold;
        padding: 10px 8px;
        letter-spacing: 0.5px;
    }
    .subtitle-band {
        background: #1e293b;
        color: #cbd5e1;
        font-size: 11px;
        padding: 4px 8px;
    }

    .info-label {
        background: #eff6ff;
        color: #0f172a;
        font-weight: bold;
        padding: 6px 10px;
        border: 1px solid #dbeafe;
        width: 160px;
    }
    .info-value {
        background: #ffffff;
        color: #1e293b;
        padding: 6px 10px;
        border: 1px solid #e2e8f0;
    }
    .info-value-warning {
        background: #fef2f2;
        color: #dc2626;
        font-weight: bold;
        padding: 6px 10px;
        border: 1px solid #fee2e2;
    }

    .section-header {
        background: #3b82f6;
        color: #ffffff;
        font-weight: bold;
        font-size: 13px;
        padding: 8px;
        border: 1px solid #2563eb;
    }

    .th-data {
        background: #0f172a;
        color: #ffffff;
        font-weight: bold;
        padding: 8px;
        border: 1px solid #0f172a;
        text-align: center;
        vertical-align: middle;
    }

    .cell-no {
        text-align: center;
        vertical-align: middle;
        border: 1px solid #e2e8f0;
        padding: 6px 8px;
        color: #64748b;
        font-weight: bold;
    }
    .cell-text {
        border: 1px solid #e2e8f0;
        padding: 6px 8px;
        vertical-align: middle;
        mso-number-format: '\@';
    }
    .cell-center {
        border: 1px solid #e2e8f0;
        padding: 6px 8px;
        vertical-align: middle;
        text-align: center;
        mso-number-format: '\@';
    }
    .cell-status-selesai {
        border: 1px solid #e2e8f0;
        padding: 6px 8px;
        text-align: center;
        vertical-align: middle;
        background: #f0fdf4;
        color: #16a34a;
        font-weight: bold;
    }
    .cell-status-berlangsung {
        border: 1px solid #e2e8f0;
        padding: 6px 8px;
        text-align: center;
        vertical-align: middle;
        background: #eff6ff;
        color: #2563eb;
        font-weight: bold;
    }
    .cell-skor {
        border: 1px solid #e2e8f0;
        padding: 6px 8px;
        text-align: center;
        vertical-align: middle;
        background: #f5f3ff;
        color: #6d28d9;
        font-weight: bold;
        font-size: 13px;
    }
    .cell-pelanggaran-ada {
        border: 1px solid #e2e8f0;
        padding: 6px 8px;
        text-align: center;
        vertical-align: middle;
        background: #fef2f2;
        color: #dc2626;
        font-weight: bold;
    }
    .cell-pelanggaran-nihil {
        border: 1px solid #e2e8f0;
        padding: 6px 8px;
        text-align: center;
        vertical-align: middle;
        color: #94a3b8;
    }

    .row-even .cell-no,
    .row-even .cell-text,
    .row-even .cell-center {
        background: #f8fafc;
    }
</style>
</head>
<body>

<table>
    <col style="width:40px;">
    <col style="width:190px;">
    <col style="width:210px;">
    <col style="width:180px;">
    <col style="width:90px;">
    <col style="width:100px;">
    <col style="width:150px;">
    <col style="width:150px;">
    <col style="width:70px;">
    <col style="width:140px;">
    <col style="width:150px;">
    <col style="width:90px;">

    <tr><td class="title-band" colspan="12">REKAP HASIL TES SELURUH PESERTA</td></tr>
    <tr><td class="subtitle-band" colspan="12">Psikotes Online System &middot; Diunduh <?= formatTanggal(date('Y-m-d H:i:s')) ?></td></tr>
    <tr><td colspan="12" style="font-size:4px; line-height:4px;">&nbsp;</td></tr>

    <tr>
        <td class="info-label" colspan="2">Total Data</td>
        <td class="info-value" colspan="10"><?= count($hasilList) ?> pengerjaan tes</td>
    </tr>
    <tr>
        <td class="info-label" colspan="2">Selesai / Berlangsung</td>
        <td class="info-value" colspan="10"><?= $totalSelesai ?> Selesai &middot; <?= $totalBerlangsung ?> Berlangsung</td>
    </tr>
    <?php if ($totalPelanggaran > 0): ?>
    <tr>
        <td class="info-label" colspan="2">Total Indikasi Pelanggaran</td>
        <td class="info-value-warning" colspan="10"><?= $totalPelanggaran ?> kali (seluruh peserta)</td>
    </tr>
    <?php endif; ?>

    <tr><td colspan="12" style="font-size:6px; line-height:6px;">&nbsp;</td></tr>

    <tr><td class="section-header" colspan="12">DETAIL PENGERJAAN (<?= count($hasilList) ?> Data)</td></tr>

    <tr>
        <th class="th-data">No</th>
        <th class="th-data">Nama Peserta</th>
        <th class="th-data">Email</th>
        <th class="th-data">Tes</th>
        <th class="th-data">Jenis</th>
        <th class="th-data">Status</th>
        <th class="th-data">Waktu Mulai</th>
        <th class="th-data">Waktu Selesai</th>
        <th class="th-data">Skor / IQ</th>
        <th class="th-data">Benar / Total Soal</th>
        <th class="th-data">Profil (Psikotes/Eksternal)</th>
        <th class="th-data">Pelanggaran</th>
    </tr>

    <?php foreach ($hasilList as $idx => $h): ?>
        <?php
            // Skor: kalau tes memakai skala IQ (WPT), tampilkan IQ + klasifikasi.
            // Selain itu, tampilkan total_skor seperti biasa.
            $skorLabel = '-';
            if ($h['status'] === 'selesai') {
                if ($h['jenis'] === 'logika' && !empty($h['iq_score'])) {
                    $skorLabel = 'IQ ' . (int)$h['iq_score'] . (!empty($h['iq_klasifikasi']) ? ' (' . $h['iq_klasifikasi'] . ')' : '');
                } elseif ($h['jenis'] !== 'eksternal') {
                    $skorLabel = (int)$h['total_skor'];
                }
            }

            // Profil: berlaku untuk tes psikotes (DISC/MBTI/Papikostik) maupun
            // tes eksternal (diisi manual oleh admin setelah tinjau screenshot).
            $tampilkanProfil = in_array($h['jenis'], ['psikotes', 'eksternal'], true);
        ?>
    <tr class="<?= $idx % 2 === 1 ? 'row-even' : '' ?>">
        <td class="cell-no"><?= $idx + 1 ?></td>
        <td class="cell-text"><?= e($h['nama_peserta']) ?></td>
        <td class="cell-text"><?= e($h['email']) ?></td>
        <td class="cell-text"><?= e($h['judul_tes']) ?></td>
        <td class="cell-center"><?= ucfirst($h['jenis']) ?><?= $h['tipe_psikotes'] ? ' &middot; ' . strtoupper($h['tipe_psikotes']) : '' ?></td>
        <td class="<?= $h['status'] === 'selesai' ? 'cell-status-selesai' : 'cell-status-berlangsung' ?>">
            <?= $h['status'] === 'selesai' ? 'Selesai' : 'Berlangsung' ?>
        </td>
        <td class="cell-text"><?= formatTanggal($h['waktu_mulai']) ?></td>
        <td class="cell-text"><?= $h['waktu_selesai'] ? formatTanggal($h['waktu_selesai']) : '-' ?></td>
        <td class="<?= $h['status'] === 'selesai' && $skorLabel !== '-' ? 'cell-skor' : 'cell-center' ?>">
            <?= is_int($skorLabel) ? $skorLabel : e((string)$skorLabel) ?>
        </td>
        <td class="cell-center"><?= $h['status'] === 'selesai' && $h['jenis'] === 'logika' ? (int)$h['total_benar'] . ' dari ' . (int)$h['total_soal'] : '-' ?></td>
        <td class="cell-text"><?= $h['status'] === 'selesai' && $tampilkanProfil ? e($h['profil_hasil'] ?? '-') : '-' ?></td>
        <td class="<?= (int)$h['pelanggaran_count'] > 0 ? 'cell-pelanggaran-ada' : 'cell-pelanggaran-nihil' ?>">
            <?= (int)$h['pelanggaran_count'] > 0 ? (int)$h['pelanggaran_count'] . ' kali' : '-' ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

</body>
</html>