<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');

$db = getDB();
$userId = $_SESSION['user_id'];
$attemptId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT a.*, t.judul AS judul_tes, t.jenis, t.tipe_psikotes
    FROM attempt a JOIN tes t ON t.id = a.tes_id
    WHERE a.id = ? AND a.user_id = ?
");
$stmt->execute([$attemptId, $userId]);
$attempt = $stmt->fetch();
if (!$attempt || $attempt['status'] !== 'selesai') { 
    header('Location: dashboard.php'); 
    exit; 
}

// Catatan: kolom is_benar & nilai sengaja TIDAK di-select di sini,
// karena peserta tidak boleh melihat kunci jawaban / status benar-salah.
// Satu soal (terutama tipe 'multiple') bisa punya lebih dari satu baris
// jawaban, jadi hasilnya dikelompokkan per soal_id di PHP, bukan
// ditampilkan sebagai 1 baris = 1 nomor soal.
$stmt = $db->prepare("
    SELECT s.id AS soal_id, s.urutan, s.pertanyaan, s.gambar AS gambar_soal, o.label, o.teks, j.ragu_ragu
    FROM jawaban j
    JOIN soal s ON s.id = j.soal_id
    LEFT JOIN opsi o ON o.id = j.opsi_id
    WHERE j.attempt_id = ? AND j.opsi_id IS NOT NULL
    ORDER BY s.urutan ASC, s.id ASC, o.label ASC
");
$stmt->execute([$attemptId]);
$jawabanRows = $stmt->fetchAll();

$jawabanPerSoal = [];
foreach ($jawabanRows as $row) {
    $sid = $row['soal_id'];
    if (!isset($jawabanPerSoal[$sid])) {
        $jawabanPerSoal[$sid] = [
            'pertanyaan'   => $row['pertanyaan'],
            'gambar_soal'  => $row['gambar_soal'],
            'ragu_ragu'    => $row['ragu_ragu'],
            'pilihan'      => [],
        ];
    }
    $jawabanPerSoal[$sid]['pilihan'][] = [
        'label' => $row['label'],
        'teks'  => $row['teks'],
    ];
}

$pageTitle = 'Ringkasan Hasil Ujian';
$pageDesc = $attempt['judul_tes'];
$activeMenu = 'riwayat';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    /* Custom Styling Hasil Detail */
    .btn-back-link {
        color: #64748b;
        font-size: 0.875rem;
        font-weight: 600;
        text-decoration: none;
        transition: color 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-back-link:hover {
        color: #2563eb;
    }

    .status-summary-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 32px 24px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    }
    .status-icon-badge {
        width: 64px;
        height: 64px;
        background-color: #f0fdf4;
        color: #16a34a;
        border: 1px solid #dcfce7;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.75rem;
        margin-bottom: 16px;
    }

    .alert-warning-modern {
        background-color: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 12px;
        padding: 14px 18px;
        color: #991b1b;
        font-size: 0.875rem;
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }

    .detail-container-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 28px;
    }

    .question-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px;
        transition: border-color 0.2s ease;
    }
    .question-card:hover {
        border-color: #cbd5e1;
    }

    .badge-ragu {
        background-color: #fffbeb;
        color: #d97706;
        border: 1px solid #fef3c7;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 6px;
    }

    .answer-box {
        background-color: #ffffff;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #3b82f6;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 0.875rem;
        color: #1e293b;
    }
    .answer-box + .answer-box {
        margin-top: 8px;
    }

    .img-soal-preview {
        max-width: 200px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
</style>

<!-- Top Navigation Link -->
<div class="mb-3">
    <a href="dashboard.php" class="btn-back-link">
        <i class="bi bi-arrow-left"></i> Kembali ke Dashboard
    </a>
</div>

<!-- Warning Flag / Indikasi Pelanggaran -->
<?php if (!empty($attempt['flag_curang'])): ?>
<div class="alert-warning-modern mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 text-danger"></i>
    <div>
        <strong class="d-block mb-1 text-dark">Indikasi Pelanggaran Terdeteksi</strong>
        Sesi pengerjaan ini mencatat <strong><?= (int)$attempt['pelanggaran_count'] ?> kali</strong> aktivitas tidak aktif (seperti berpindah tab, membuka jendela lain, atau meninggalkan layar aplikasi).
    </div>
</div>
<?php endif; ?>

<!-- Status Penilaian & Konfirmasi Selesai -->
<div class="status-summary-card mb-4">
    <div class="status-icon-badge">
        <i class="bi bi-check-lg"></i>
    </div>
    <h5 class="fw-bold text-dark mb-1">Tes Berhasil Diselesaikan</h5>
    <p class="text-muted small mb-3" style="max-width: 460px; margin: 0 auto;">
        Jawaban Anda telah tersimpan dengan aman di sistem. Penilaian akhir dan hasil akumulasi skor akan ditinjau serta diinformasikan oleh tim HRD/Admin.
    </p>
    <div class="d-inline-flex gap-3 text-muted small border-top pt-3">
        <span><i class="bi bi-journal-check me-1"></i> Modul: <strong><?= e($attempt['judul_tes']) ?></strong></span>
        <span>&bull;</span>
        <span><i class="bi bi-check2-circle me-1"></i> Terjawab: <strong><?= count($jawabanPerSoal) ?> Soal</strong></span>
    </div>
</div>

<!-- Rincian Jawaban Peserta -->
<div class="detail-container-modern">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h6 class="fw-bold text-dark mb-1">Rincian Jawaban Terekam</h6>
            <p class="text-muted small mb-0">Daftar pertanyaan dan opsi yang telah Anda pilih selama ujian</p>
        </div>
        <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill small font-monospace">
            <?= count($jawabanPerSoal) ?> Butir Soal
        </span>
    </div>

    <?php if (empty($jawabanPerSoal)): ?>
        <div class="text-center py-4 text-muted small">
            <i class="bi bi-file-earmark-x d-block fs-3 mb-1 opacity-50"></i>
            Tidak ada rincian jawaban yang tersimpan untuk tes ini.
        </div>
    <?php endif; ?>

    <div class="d-flex flex-column gap-3">
        <?php $idx = 0; foreach ($jawabanPerSoal as $j): $idx++; ?>
        <div class="question-card">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="fw-semibold text-dark" style="font-size: 0.925rem;">
                    <span class="text-muted me-1">#<?= $idx ?>.</span> <?= e($j['pertanyaan']) ?>
                </div>
                <?php if (!empty($j['ragu_ragu'])): ?>
                    <span class="badge-ragu flex-shrink-0">
                        <i class="bi bi-flag-fill me-1"></i> Ragu-ragu
                    </span>
                <?php endif; ?>
            </div>

            <!-- Preview Gambar Soal Jika Ada -->
            <?php if ($j['gambar_soal']): ?>
                <div class="my-2">
                    <img src="../uploads/soal/<?= e($j['gambar_soal']) ?>" alt="Soal Gambar" class="img-soal-preview">
                </div>
            <?php endif; ?>

            <!-- Pilihan Jawaban Peserta (bisa lebih dari 1 untuk soal multiple) -->
            <div class="mt-3">
                <span class="text-muted small d-block mb-1" style="font-size: 0.75rem;">
                    <?= count($j['pilihan']) > 1 ? 'Pilihan Anda (' . count($j['pilihan']) . '):' : 'Pilihan Anda:' ?>
                </span>
                <?php foreach ($j['pilihan'] as $p): ?>
                    <div class="answer-box">
                        <strong class="text-dark"><?= e($p['label']) ?>.</strong> <?= e($p['teks']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>