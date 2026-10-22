<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/peserta_summary.php';
require_once __DIR__ . '/../includes/disc_data.php';
requireRole('admin');

$db = getDB();
$userId = (int)($_GET['user_id'] ?? 0);
$summary = ambilSummaryPeserta($db, $userId);
if (!$summary) { header('Location: hasil.php'); exit; }

$peserta = $summary['peserta'];
$ai = 0;

/**
 * Untuk section DISC: ambil nama profil dari Core Private Self (index_least)
 * langsung dari attempt.detail_skor, supaya headline selalu Core
 * meskipun profil_hasil lama masih berisi pola Mirror.
 * Mengembalikan null kalau bukan DISC / data tidak ada.
 */
function profilCoreDisc(PDO $db, int $attemptId): ?string
{
    $st = $db->prepare("
        SELECT a.detail_skor, t.tipe_psikotes
        FROM attempt a
        JOIN tes t ON t.id = a.tes_id
        WHERE a.id = ?
    ");
    $st->execute([$attemptId]);
    $row = $st->fetch();
    if (!$row || $row['tipe_psikotes'] !== 'disc' || empty($row['detail_skor'])) {
        return null;
    }
    $d = json_decode($row['detail_skor'], true);
    if (!is_array($d) || !isset($d['index_least'])) {
        return null;
    }
    $pola = discDataPola((int)$d['index_least']);
    return $pola['nama'] ?? null;
}

$pageTitle = 'Summary Peserta';
$pageDesc = e($peserta['nama']) . ' — semua tes';
$activeMenu = 'hasil';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    .summary-table th, .summary-table td { text-align:center; font-size:13.5px; }
    .summary-table td:nth-child(2) { text-align:left; }
    .summary-note { font-size:13px; color:#475569; margin:4px 0 0; }
    .hasil-utama { font-size:22px; font-weight:700; }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <a href="hasil.php" class="link-kembali fw-semibold" style="font-size:13px; color:var(--brand);">&larr; Kembali ke Hasil</a>
    <?php if ($summary['sections']): ?>
    <div class="d-flex gap-2">
        <a href="peserta_summary_export_pdf.php?user_id=<?= (int)$peserta['id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-file-earmark-pdf me-1"></i> PDF
        </a>
        <a href="peserta_summary_export_excel.php?user_id=<?= (int)$peserta['id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-file-earmark-excel me-1"></i> Excel
        </a>
    </div>
    <?php endif; ?>
</div>

<div class="card-modern mt-3 mb-3" style="--d: <?= $ai++ ?>">
    <h5 class="fw-bold mb-1"><?= e($peserta['nama']) ?></h5>
    <p class="text-muted mb-0" style="font-size:13.5px;">
        <?= e($peserta['email']) ?> &middot; <?= count($summary['sections']) ?> tes selesai
    </p>
</div>

<?php if (!$summary['sections']): ?>
    <div class="card-modern text-center text-muted py-4" style="--d: <?= $ai++ ?>">
        <i class="bi bi-inbox fs-2 opacity-50 d-block mb-2"></i>
        Peserta ini belum menyelesaikan tes apa pun.
    </div>
<?php endif; ?>

<?php foreach ($summary['sections'] as $sec): ?>
<?php
    // Headline DISC = Core Private Self; tes lain tetap memakai $sec['hasil']
    $hasilTampil = profilCoreDisc($db, (int)$sec['attempt_id']) ?? $sec['hasil'];
?>
<div class="card-modern mb-3" style="--d: <?= $ai++ ?>">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h6 class="fw-bold mb-1"><?= e($sec['judul']) ?></h6>
            <div class="text-muted small">Selesai: <?= e($sec['tanggal']) ?></div>
        </div>
        <div class="text-end">
            <div class="hasil-utama"><?= e($hasilTampil) ?></div>
            <?php if ($sec['keterangan'] !== ''): ?>
                <div class="text-muted small"><?= e($sec['keterangan']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <?= renderSectionTablesHtml($sec) ?>
    </div>

    <a href="hasil_detail.php?id=<?= (int)$sec['attempt_id'] ?>" class="small" style="color:var(--brand);">
        Lihat detail jawaban &rarr;
    </a>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>