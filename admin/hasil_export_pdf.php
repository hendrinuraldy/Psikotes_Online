<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/papikostik_scoring.php';
require_once __DIR__ . '/../includes/disc_scoring.php';
require_once __DIR__ . '/../includes/disc_data.php';
require_once __DIR__ . '/../includes/mbti_data.php';
require_once __DIR__ . '/../vendor/autoload.php';
requireRole('admin');

use Dompdf\Dompdf;
use Dompdf\Options;

$db = getDB();
$attemptId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT a.*, u.nama AS nama_peserta, u.email, t.judul AS judul_tes, t.jenis, t.tipe_psikotes
    FROM attempt a
    JOIN users u ON u.id = a.user_id
    JOIN tes t ON t.id = a.tes_id
    WHERE a.id = ? AND a.status = 'selesai'
");
$stmt->execute([$attemptId]);
$attempt = $stmt->fetch();
if (!$attempt) { header('Location: hasil.php'); exit; }

$stmt = $db->prepare("
    SELECT s.id AS soal_id, s.pertanyaan, s.gambar AS gambar_soal, o.label, o.teks, o.is_benar, o.dimensi, j.tipe_pilihan, j.ragu_ragu
    FROM jawaban j
    JOIN soal s ON s.id = j.soal_id
    LEFT JOIN opsi o ON o.id = j.opsi_id
    WHERE j.attempt_id = ?
    ORDER BY s.urutan ASC, s.id ASC
");
$stmt->execute([$attemptId]);
$jawaban = $stmt->fetchAll();

// Sisipkan gambar soal sebagai base64 supaya dompdf pasti bisa render
// tanpa tergantung akses HTTP ke folder uploads.
function gambarKeBase64(?string $namaFile): ?string {
    if (!$namaFile) return null;
    $path = __DIR__ . '/../uploads/soal/' . $namaFile;
    if (!is_file($path)) return null;
    $mime = mime_content_type($path) ?: 'image/jpeg';
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
}

// Rincian DISC (kalau ada), disimpan otomatis oleh includes/disc_scoring.php
// ke attempt.detail_skor sebagai JSON. Tampilan disamakan dengan hasil_detail.php:
// yang dipakai adalah index pola, sedangkan sifat / deskripsi / job match
// diambil dari includes/disc_data.php.
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

// Fallback lama: tally mentah per dimensi (tes psikotes tanpa mesin skor detail)
$distribusi = [];
if ($attempt['jenis'] === 'psikotes' && !$rincianDisc && !$rincianPapikostik) {
    $stmt = $db->prepare("
        SELECT o.dimensi, COUNT(*) AS jumlah
        FROM jawaban j JOIN opsi o ON o.id = j.opsi_id
        WHERE j.attempt_id = ? AND o.dimensi IS NOT NULL
        GROUP BY o.dimensi
    ");
    $stmt->execute([$attemptId]);
    foreach ($stmt->fetchAll() as $row) { $distribusi[$row['dimensi']] = (int)$row['jumlah']; }
}

// Tes eksternal tidak punya rincian jawaban tersimpan.
$tampilkanDetailJawaban = ($attempt['jenis'] !== 'eksternal');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'Helvetica', Arial, sans-serif; color: #0f172a; font-size: 12px; }
    h1 { font-size: 17px; margin: 0 0 2px; color: #0f172a; }
    .subtitle { font-size: 11px; color: #64748b; margin-bottom: 18px; }
    .info-box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; }
    .info-box table { width: 100%; border-collapse: collapse; }
    .info-box td { padding: 3px 0; font-size: 11.5px; }
    .info-box td.label { color: #64748b; width: 130px; }
    .skor-box { text-align: center; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 16px; }
    .skor-box .angka { font-size: 30px; font-weight: bold; color: #1d4ed8; }
    .skor-box .ket { font-size: 11px; color: #64748b; margin-top: 2px; }
    .flag-curang { border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; border-radius: 6px; padding: 8px 12px; font-size: 11px; margin-bottom: 14px; }
    .note-box { border: 1px solid #fde68a; background: #fffbeb; color: #92400e; border-radius: 6px; padding: 8px 12px; font-size: 11px; margin-bottom: 14px; font-style: italic; }
    h3 { font-size: 13px; margin: 18px 0 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; }
    table.rincian { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.rincian th, table.rincian td { border: 1px solid #e2e8f0; padding: 5px 6px; font-size: 10.5px; text-align: center; }
    table.rincian th { background: #0f172a; color: #fff; }
    table.rincian td.dim-label { text-align: left; font-weight: bold; }
    table.rincian td.teks-kiri { text-align: left; }
    .rincian-highlight { border: 1px solid #dcfce7; background: #f0fdf4; color: #16a34a; font-weight: bold; border-radius: 6px; padding: 6px 10px; font-size: 11px; margin-bottom: 6px; }
    .soal-item { border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; margin-bottom: 8px; page-break-inside: avoid; }
    .soal-item .pertanyaan { font-weight: bold; margin-bottom: 4px; }
    .soal-item .jawaban { padding: 5px 8px; border-radius: 4px; font-size: 11px; }
    .jawaban.benar { background: #ecfdf5; }
    .jawaban.salah { background: #fef2f2; }
    .jawaban.netral { background: #f8fafc; }
    .tag-benar { color: #059669; font-weight: bold; }
    .tag-salah { color: #dc2626; font-weight: bold; }
    .tag-ragu { color: #b45309; font-size: 10px; }
    img.soal-img { max-width: 140px; margin: 4px 0 6px; }
    .footer-note { margin-top: 20px; font-size: 9.5px; color: #94a3b8; }

    /* ===== DISC: tabel 3 kolom, header hijau soft, badan putih polos bergaris ===== */
    table.disc-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.disc-table th { background: #dcfce7; color: #166534; font-weight: bold; font-size: 10.5px;
                          padding: 6px 8px; text-align: left; border: 1px solid #86efac; }
    table.disc-table td { background: #ffffff; color: #334155; font-size: 10px; padding: 4px 8px;
                          border: 1px solid #cbd5e1; vertical-align: top; }
    table.disc-table td.disc-kom { font-size: 9px; color: #64748b; font-weight: bold; }
    table.disc-table td.disc-nama { color: #0f172a; font-weight: bold; text-transform: uppercase; }
    table.disc-table tr { page-break-inside: avoid; }
    .disc-label { background: #dcfce7; color: #166534; font-weight: bold; font-size: 11px;
                  margin: 10px 0 0; padding: 5px 10px; border: 1px solid #86efac; }
    .disc-box { background: #ffffff; border: 1px solid #86efac; border-top: 0; padding: 8px 12px;
                font-size: 10.5px; line-height: 1.55; text-align: justify; color: #1e293b; }
    .disc-info { font-size: 9px; color: #64748b; margin-top: 8px; }

    /* ===== MBTI / 16Personalities ===== */
    .tipe16 { display: inline-block; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-weight: bold; font-size: 15px; padding: 3px 14px; border-radius: 6px; }
    .mbti-desk { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 9px 12px; font-size: 11px; line-height: 1.6; text-align: justify; }
    .link-hasil { font-size: 10px; color: #2563eb; word-wrap: break-word; }
</style>
</head>
<body>
    <h1>Laporan Hasil Tes</h1>
    <div class="subtitle">Dicetak pada <?= date('d/m/Y H:i') ?> WIB</div>

    <div class="info-box">
        <table>
            <tr><td class="label">Nama Peserta</td><td>: <?= e($attempt['nama_peserta']) ?></td></tr>
            <tr><td class="label">Email</td><td>: <?= e($attempt['email']) ?></td></tr>
            <tr><td class="label">Nama Tes</td><td>: <?= e($attempt['judul_tes']) ?> (<?= ucfirst($attempt['jenis']) ?><?= $attempt['tipe_psikotes'] ? ' &middot; ' . strtoupper($attempt['tipe_psikotes']) : '' ?>)</td></tr>
            <tr><td class="label">Waktu Mulai</td><td>: <?= formatTanggal($attempt['waktu_mulai']) ?></td></tr>
            <tr><td class="label">Waktu Selesai</td><td>: <?= formatTanggal($attempt['waktu_selesai']) ?></td></tr>
        </table>
    </div>

    <?php if (!empty($attempt['flag_curang'])): ?>
    <div class="flag-curang">
        <strong>Catatan:</strong> Sesi ini tercatat memiliki <?= (int)$attempt['pelanggaran_count'] ?> indikasi pelanggaran (berpindah tab/aplikasi) selama pengerjaan.
    </div>
    <?php endif; ?>

    <?php if ($attempt['jenis'] === 'eksternal'): ?>
        <div class="skor-box">
            <div class="angka"><?= !empty($hasilEks['tipe']) ? e($hasilEks['tipe']) : '-' ?></div>
            <div class="ket">Tipe Kepribadian (diisi peserta)<?= !empty($hasilEks['desk']) ? ' &mdash; ' . e($hasilEks['desk']['nama']) : '' ?></div>
        </div>
    <?php elseif ($attempt['jenis'] === 'logika' && $iqScore !== null): ?>
        <div class="skor-box">
            <div class="angka">IQ <?= (int)$iqScore ?></div>
            <div class="ket"><?= e($iqKlasifikasi ?? '') ?> &mdash; <?= (int)$attempt['total_benar'] ?> / <?= (int)$attempt['total_soal'] ?> Benar</div>
        </div>
    <?php elseif ($attempt['jenis'] === 'logika'): ?>
        <div class="skor-box">
            <div class="angka"><?= (int)$attempt['total_skor'] ?></div>
            <div class="ket">Total Skor &mdash; <?= (int)$attempt['total_benar'] ?> / <?= (int)$attempt['total_soal'] ?> Benar</div>
        </div>
    <?php else: ?>
        <div class="skor-box">
            <div class="angka"><?= e($attempt['profil_hasil'] ?? '-') ?></div>
            <div class="ket">Profil Kepribadian &mdash; <?= (int)$attempt['total_skor'] ?> soal terjawab</div>
        </div>
    <?php endif; ?>

    <?php if ($hasilEks): ?>
        <h3>Hasil yang Diisi Peserta (16Personalities)</h3>
        <?php if (!empty($hasilEks['tipe'])): ?>
            <?php if ($hasilEks['rows']): ?>
            <table class="rincian">
                <tr><th>Sifat</th><th>Sisi</th><th>Persentase</th></tr>
                <?php foreach ($hasilEks['rows'] as $r): ?>
                <tr>
                    <td class="dim-label"><?= e($r[0]) ?></td>
                    <td class="teks-kiri"><?= e($r[1]) ?></td>
                    <td style="font-weight:bold;"><?= e($r[2]) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
                <div class="note-box">Persentase sifat tidak tersimpan untuk attempt ini.</div>
            <?php endif; ?>

            <?php if (!empty($hasilEks['desk'])): ?>
                <h3>Deskripsi Tipe Kepribadian</h3>
                <div style="margin-bottom:8px;">
                    <span class="tipe16"><?= e($hasilEks['tipe']) ?></span>
                    <strong style="margin-left:8px;"><?= e($hasilEks['desk']['nama']) ?></strong>
                </div>
                <div class="mbti-desk"><?= e($hasilEks['desk']['deskripsi']) ?></div>
            <?php endif; ?>

            <?php if (!empty($hasilEks['link'])): ?>
                <h3>Link Hasil Pribadi 16Personalities</h3>
                <div class="link-hasil"><?= e($hasilEks['link']) ?></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="note-box">Peserta belum mengisi tipe dan persentase hasil tes.</div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($rincianWpt): ?>
        <h3>Rincian Konversi IQ (<?= e($jenisIq) ?>)</h3>
        <table class="rincian">
            <tr>
                <th>Skor Mentah</th><th>Usia</th><th>Koreksi Usia</th><th>Skor Setelah Koreksi</th><th>IQ</th><th>Klasifikasi</th>
            </tr>
            <tr>
                <td><?= (int)($rincianWpt['skor_mentah'] ?? 0) ?></td>
                <td><?= isset($rincianWpt['usia']) && $rincianWpt['usia'] !== null ? (int)$rincianWpt['usia'] . ' th' : 'tidak diketahui' ?></td>
                <td>+<?= (int)($rincianWpt['koreksi_usia'] ?? 0) ?></td>
                <td><?= (int)($rincianWpt['skor_koreksi'] ?? 0) ?></td>
                <td style="font-weight:bold;"><?= (int)($rincianWpt['iq'] ?? 0) ?></td>
                <td style="font-weight:bold;"><?= e($rincianWpt['klasifikasi'] ?? '-') ?></td>
            </tr>
        </table>
    <?php endif; ?>

    <?php if ($rincianDisc): ?>
    <?php
        // Sama seperti hasil_detail.php: index pola -> sifat, deskripsi, job match
        $defMask   = discDataPola(isset($rincianDisc['index_most'])    ? (int)$rincianDisc['index_most']    : null);
        $defCore   = discDataPola(isset($rincianDisc['index_least'])   ? (int)$rincianDisc['index_least']   : null);
        $defMirror = discDataPola(isset($rincianDisc['index_selisih']) ? (int)$rincianDisc['index_selisih'] : null);

        $kolomDisc = [
            ['judul' => 'Mask Public Self',                     'kom' => DISC_KOMBINASI_40[$rincianDisc['index_most']    ?? 0] ?? '-', 'def' => $defMask],
            ['judul' => 'Core Private Self',                    'kom' => DISC_KOMBINASI_40[$rincianDisc['index_least']   ?? 0] ?? '-', 'def' => $defCore],
            ['judul' => 'Mirror Perceived Self (Profil Akhir)', 'kom' => DISC_KOMBINASI_40[$rincianDisc['index_selisih'] ?? 0] ?? '-', 'def' => $defMirror],
        ];
        $maxSifat = 0;
        foreach ($kolomDisc as $k) {
            $maxSifat = max($maxSifat, count($k['def']['sifat'] ?? []));
        }
    ?>
        <h3>Gambaran Karakter (DISC)</h3>
        <table class="disc-table">
            <thead>
                <tr>
                    <?php foreach ($kolomDisc as $k): ?>
                        <th style="width:33.33%;"><?= e($k['judul']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach ($kolomDisc as $k): ?>
                        <td class="disc-kom">Kombinasi: <?= e($k['kom']) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ($kolomDisc as $k): ?>
                        <td class="disc-nama"><?= e($k['def']['nama'] ?? '#N/A') ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php for ($r = 0; $r < $maxSifat; $r++): ?>
                <tr>
                    <?php foreach ($kolomDisc as $k): ?>
                        <td><?= e($k['def']['sifat'][$r] ?? '') ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div class="disc-label">Deskripsi Kepribadian</div>
        <div class="disc-box">
            <?= $defMirror ? e($defMirror['deskripsi']) : 'Deskripsi tidak tersedia (pola tidak ditemukan).' ?>
        </div>

        <div class="disc-label">Job Match :</div>
        <div class="disc-box">
            <?= $defMirror ? e($defMirror['job']) : 'Job match tidak tersedia (pola tidak ditemukan).' ?>
        </div>

        <div class="disc-info">
            Sifat, deskripsi, dan job match diambil dari pola hasil grafik Mirror; Mask dan Core hanya menampilkan nama pola &amp; sifatnya.
        </div>
    <?php endif; ?>

    <?php if ($rincianPapikostik): ?>
        <h3>Rincian Skor Papikostik (PAPI Kostick)</h3>
        <table class="rincian">
            <tr><th>Dim.</th><th>Nama Dimensi</th><th>Skor</th><th>Interpretasi</th></tr>
            <?php foreach (PAPIKOSTIK_DIMENSI as $h): ?>
            <tr>
                <td class="dim-label"><?= e($h) ?></td>
                <td class="teks-kiri"><?= e(PAPIKOSTIK_LABEL[$h] ?? $h) ?></td>
                <td style="font-weight:bold;"><?= (int)($rincianPapikostik['skor'][$h] ?? 0) ?></td>
                <td class="teks-kiri"><?= e($rincianPapikostik['interpretasi'][$h] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div class="rincian-highlight">
            3 Dimensi Dominan: <?= e(implode(', ', $rincianPapikostik['dominan'] ?? [])) ?>
        </div>
    <?php elseif ($distribusi): ?>
        <h3>Distribusi Dimensi</h3>
        <table class="rincian">
            <?php foreach ($distribusi as $dim => $jumlah): ?>
            <tr>
                <td class="dim-label teks-kiri"><?= e($dim) ?> &mdash; <?= e(labelDimensi($attempt['tipe_psikotes'], $dim)) ?></td>
                <td style="width:60px;"><?= $jumlah ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <?php if ($tampilkanDetailJawaban): ?>
    <h3>Rincian Jawaban</h3>
    <?php foreach ($jawaban as $idx => $j):
        $imgSrc = gambarKeBase64($j['gambar_soal']);
        $isLogika = $attempt['jenis'] === 'logika';
        $cls = $isLogika ? ($j['is_benar'] ? 'benar' : 'salah') : 'netral';
    ?>
    <div class="soal-item">
        <div class="pertanyaan">
            #<?= $idx + 1 ?>. <?= e($j['pertanyaan']) ?>
            <?php if (!empty($j['tipe_pilihan']) && $j['tipe_pilihan'] !== 'single'): ?>
                <span style="color:#4338ca; font-weight:bold; font-size:9.5px; text-transform:uppercase;"> (<?= e($j['tipe_pilihan']) ?>)</span>
            <?php endif; ?>
            <?php if (!empty($j['ragu_ragu'])): ?><span class="tag-ragu"> (ditandai ragu-ragu)</span><?php endif; ?>
        </div>
        <?php if ($imgSrc): ?><img class="soal-img" src="<?= $imgSrc ?>"><?php endif; ?>
        <div class="jawaban <?= $cls ?>">
            Jawaban: <strong><?= e($j['label'] ?? '-') ?><?= $j['teks'] ? '. ' . e($j['teks']) : '' ?></strong>
            <?php if ($isLogika): ?>
                <?= $j['is_benar'] ? '<span class="tag-benar"> (Benar)</span>' : '<span class="tag-salah"> (Salah)</span>' ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="footer-note">Dokumen ini dibuat otomatis oleh sistem Psikotes Online.</div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Bersihkan karakter khusus dari nama peserta & judul tes
$namaPeserta = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $attempt['nama_peserta']);
$judulTes    = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $attempt['judul_tes']);

$namaPeserta = trim(preg_replace('/\s+/', ' ', $namaPeserta));
$judulTes    = trim(preg_replace('/\s+/', ' ', $judulTes));

// Susun nama file: Hasil_Nama Peserta_Nama Tes.pdf
$namaFile = 'Hasil_' . $namaPeserta . '_' . $judulTes . '.pdf';

$dompdf->stream($namaFile, ['Attachment' => true]);
exit;