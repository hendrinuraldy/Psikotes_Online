<?php
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');

$db = getDB();
$userId = $_SESSION['user_id'];
$tesId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tes WHERE id = ? AND status = 'aktif'");
$stmt->execute([$tesId]);
$tes = $stmt->fetch();
if (!$tes) { header('Location: dashboard.php'); exit; }

$isForcedChoice = ($tes['format_soal'] ?? null) === 'forced_choice';
$isPapikostik = ($tes['jenis'] ?? '') === 'psikotes' && ($tes['tipe_psikotes'] ?? '') === 'papikostik';
// DISC (forced-choice) & Papikostik: semua soal wajib terisi sebelum boleh submit
$wajibLengkap = $isForcedChoice || $isPapikostik;

// Deteksi subtes CFIT (1-4) dari judul tes, mis. "CFIT 1" atau "CFIT Subtes 2".
$cfitNo = 0;
if (($tes['jenis'] ?? '') === 'logika' && preg_match('/cfit\D*([1-4])/i', (string)($tes['judul'] ?? ''), $mCfit)) {
    $cfitNo = (int)$mCfit[1];
}
// CFIT 2: peserta harus memilih TEPAT 2 gambar (0 = tanpa batas khusus)
$maxPilihan = ($cfitNo === 2) ? 2 : 0;

// Ambil atau buat attempt yang sedang berlangsung
$stmt = $db->prepare("SELECT * FROM attempt WHERE user_id = ? AND tes_id = ? AND status = 'berlangsung' ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId, $tesId]);
$attempt = $stmt->fetch();

// Kalau sudah pernah selesai, arahkan ke hasil
$stmt = $db->prepare("SELECT id FROM attempt WHERE user_id = ? AND tes_id = ? AND status = 'selesai' ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId, $tesId]);
$selesai = $stmt->fetch();
if ($selesai && !$attempt) {
    header('Location: hasil_detail.php?id=' . $selesai['id']);
    exit;
}

if (!$attempt) {
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT INTO attempt (user_id, tes_id, waktu_mulai, status) VALUES (?, ?, ?, 'berlangsung')");
    $stmt->execute([$userId, $tesId, $now]);
    $attemptId = $db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM attempt WHERE id = ?");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
}

// Hitung sisa waktu (dihitung dari waktu_mulai server)
$mulaiTs = strtotime($attempt['waktu_mulai']);
$durasiDetik = (int)$tes['durasi_menit'] * 60;
$sisaDetik = $durasiDetik - (time() - $mulaiTs);
if ($sisaDetik <= 0) {
    require_once __DIR__ . '/../includes/attempt_auto.php';
    finalisasiAttemptKadaluarsa($db);

    $nextTesId = tesGrupBerikutnya($db, $userId, $tesId);
    header('Location: ' . ($nextTesId ? 'tes.php?id=' . $nextTesId : 'hasil_detail.php?id=' . $attempt['id']));
    exit;
}

$soalList = $db->prepare("SELECT * FROM soal WHERE tes_id = ? ORDER BY urutan ASC, id ASC");
$soalList->execute([$tesId]);
$soalList = $soalList->fetchAll();
foreach ($soalList as &$s) {
    $stmt = $db->prepare("SELECT * FROM opsi WHERE soal_id = ? ORDER BY label ASC");
    $stmt->execute([$s['id']]);
    $s['opsi'] = $stmt->fetchAll();
}
unset($s);

// Tes logika (WPT, TPA/Potensi Akademik, CFIT, dll) yang semua soalnya pilihan tunggal
$isLogikaSingle = ($tes['jenis'] ?? '') === 'logika';
foreach ($soalList as $sx) {
    if (($sx['tipe_soal'] ?? 'single') === 'multiple') { $isLogikaSingle = false; break; }
}

// jawabanTersimpan sekarang array per soal (untuk single/multiple biasa),
// DAN dua map terpisah most/least (untuk soal forced-choice DISC), diambil
// dari kolom tipe_pilihan yang sama-sama disimpan di tabel jawaban.
$jawabanTersimpan = [];
$mostTersimpan = [];
$leastTersimpan = [];
$raguTersimpan = [];
$stmt = $db->prepare("SELECT soal_id, opsi_id, ragu_ragu, tipe_pilihan FROM jawaban WHERE attempt_id = ?");
$stmt->execute([$attempt['id']]);
foreach ($stmt->fetchAll() as $j) {
    if ($j['opsi_id'] !== null) {
        if ($j['tipe_pilihan'] === 'most') {
            $mostTersimpan[$j['soal_id']] = (int)$j['opsi_id'];
        } elseif ($j['tipe_pilihan'] === 'least') {
            $leastTersimpan[$j['soal_id']] = (int)$j['opsi_id'];
        } else {
            $jawabanTersimpan[$j['soal_id']][] = (int)$j['opsi_id'];
        }
    }
    if ($j['ragu_ragu']) $raguTersimpan[$j['soal_id']] = true;
}

// Status "sudah terjawab" untuk soal forced-choice: HARUS ada Most DAN
// Least, dan keduanya harus pernyataan yang berbeda.
function fcSudahLengkap(int $soalId, array $mostTersimpan, array $leastTersimpan): bool {
    return isset($mostTersimpan[$soalId]) && isset($leastTersimpan[$soalId])
        && $mostTersimpan[$soalId] !== $leastTersimpan[$soalId];
}

$pageTitle = $tes['judul'];
$activeMenu = 'dashboard';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    /* Professional Testing UI */
    .test-header-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 20px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    }

    .timer-badge-modern {
        background-color: #eff6ff;
        color: #2563eb;
        border: 1px solid #bfdbfe;
        font-size: 0.95rem;
        font-weight: 700;
        padding: 8px 16px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-variant-numeric: tabular-nums;
        transition: all 0.3s ease;
    }
    .timer-badge-modern.low {
        background-color: #fef2f2;
        color: #dc2626;
        border-color: #fecaca;
        animation: pulseTimer 1s infinite alternate;
    }
    .timer-wrap {
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }
    .timer-label {
        color: #dc2626;
        font-size: 0.85rem;
        font-weight: 700;
        white-space: nowrap;
    }
    /* Timer melayang: muncul hanya saat timer asli di header sudah tidak terlihat */
    .timer-float {
        position: fixed;
        top: 12px;
        right: 16px;
        z-index: 1030;
        display: none;
        align-items: center;
        gap: 10px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 6px 10px;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.14);
    }
    .timer-float.show { display: inline-flex; }

    @keyframes pulseTimer {
        from { opacity: 1; }
        to { opacity: 0.6; }
    }

    /* Banner status koneksi */
    .net-banner {
        position: fixed;
        top: 0; left: 0; right: 0;
        z-index: 15000;
        display: none;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 10px 16px;
        font-size: 0.85rem;
        font-weight: 600;
        text-align: center;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
    }
    .net-banner.show { display: flex; }
    .net-banner.offline  { background: #dc2626; color: #fff; }
    .net-banner.unstable { background: #f59e0b; color: #fff; }
    .net-banner.restored { background: #16a34a; color: #fff; }

    /* Indikator simpan di header */
    .save-status {
        font-size: 0.75rem;
        font-weight: 600;
        color: #166534;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .save-status.saving  { color: #2563eb; }
    .save-status.pending { color: #b45309; }

    .progress-track {
        height: 6px;
        background: #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #2563eb, #3b82f6);
        transition: width 0.3s ease;
    }

    .test-layout {
        display: flex;
        gap: 24px;
        align-items: flex-start;
    }

    /* Navigation Side Panel */
    .soal-nav-panel {
        width: 250px;
        flex-shrink: 0;
        position: sticky;
        top: 20px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    }
    .soal-nav-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 8px;
        max-height: 280px;
        overflow-y: auto;
        padding-right: 2px;
    }
    .soal-nav-btn {
        width: 100%;
        aspect-ratio: 1;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        font-size: 0.85rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .soal-nav-btn:hover { border-color: #2563eb; color: #2563eb; }
    .soal-nav-btn.answered { background: #dcfce7; border-color: #86efac; color: #166534; }
    .soal-nav-btn.ragu { background: #fef3c7; border-color: #fde047; color: #92400e; }
    .soal-nav-btn.active { outline: 2px solid #2563eb; outline-offset: 1px; font-weight: 700; }

    .nav-legend {
        font-size: 0.75rem;
        color: #64748b;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        display: inline-block;
    }

    /* Main Test Area */
    .test-main { flex: 1; min-width: 0; }

    .question-card-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        display: none;
    }
    .question-card-modern.active { display: block; }

    .question-type-hint {
        font-size: 0.75rem;
        color: #64748b;
        margin-bottom: 10px;
    }

    .option-choice-modern {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .option-choice-modern:hover {
        background-color: #f1f5f9;
        border-color: #cbd5e1;
    }
    .option-choice-modern.selected {
        background-color: #eff6ff;
        border-color: #3b82f6;
    }

    .option-letter-box {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        color: #334155;
        flex-shrink: 0;
    }
    .option-choice-modern.selected .option-letter-box {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }

    /* --- Forced-choice (Most/Least) UI --- */
    .fc-row {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px 16px;
        margin-bottom: 10px;
        background: #f8fafc;
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }
    .fc-text {
        flex: 1;
        min-width: 200px;
        font-size: 0.9rem;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .fc-choices {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }
    .fc-btn {
        border: 1px solid #cbd5e1;
        background: #ffffff;
        border-radius: 8px;
        padding: 7px 12px;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: #475569;
        transition: all 0.15s ease;
        user-select: none;
    }
    .fc-btn:hover { border-color: #94a3b8; }
    .fc-btn.most.active {
        background: #dcfce7;
        border-color: #16a34a;
        color: #166534;
    }
    .fc-btn.least.active {
        background: #fee2e2;
        border-color: #dc2626;
        color: #991b1b;
    }
    .fc-status-banner {
        font-size: 0.8rem;
        border-radius: 10px;
        padding: 10px 14px;
        margin-bottom: 14px;
    }
    .fc-status-banner.incomplete {
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
    }
    .fc-status-banner.complete {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #166534;
    }

    .btn-ragu-toggle {
        border: 1px solid #fde047;
        background: #fffbeb;
        color: #b45309;
        border-radius: 8px;
        padding: 8px 16px;
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }
    .btn-ragu-toggle.active {
        background: #d97706;
        color: #ffffff;
        border-color: #d97706;
    }

    .violation-toast-modern {
        position: fixed;
        top: 20px;
        right: 20px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: 12px 18px;
        border-radius: 12px;
        font-size: 0.875rem;
        font-weight: 600;
        z-index: 9999;
        display: none;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        max-width: 340px;
    }

    @keyframes shakeWarn {
        0%, 100% { transform: translateX(0); }
        20% { transform: translateX(-6px); }
        40% { transform: translateX(6px); }
        60% { transform: translateX(-4px); }
        80% { transform: translateX(4px); }
    }
    .shake-warn { animation: shakeWarn 0.4s ease-in-out; }

    .soal-image-box img {
        max-width: 100%;
        width: auto;
        max-height: 480px;
        object-fit: contain;
        cursor: zoom-in;
        background: #fff;
    }
    .opsi-image-box img {
        max-width: 100%;
        width: 100%;
        max-width: 220px;
        height: auto;
        object-fit: contain;
        cursor: zoom-in;
        background: #fff;
    }
    .image-lightbox-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.9);
        z-index: 10000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        overflow: auto;
    }
    .image-lightbox-overlay.show { display: flex; }
    .image-lightbox-overlay img {
        max-width: 90vw;
        max-height: 85vh;
        object-fit: contain;
        cursor: zoom-in;
        border-radius: 8px;
        background: #fff;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        transition: max-width 0.15s ease, max-height 0.15s ease, width 0.15s ease;
    }
    .image-lightbox-overlay img.zoomed {
        max-width: none;
        max-height: none;
        width: 180%;
        cursor: zoom-out;
    }
    .image-lightbox-close {
        position: fixed;
        top: 18px;
        right: 22px;
        z-index: 10001;
        background: #ffffff;
        border: none;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 4px 10px rgba(0,0,0,0.25);
    }
    .image-hint {
        font-size: 0.72rem;
        color: #94a3b8;
        margin-top: 4px;
    }

    /* SweetAlert harus tampil di atas lightbox & toast */
    .swal2-container { z-index: 20000 !important; }

    @media (max-width: 860px) {
        .test-layout { flex-direction: column; }
        .soal-nav-panel { width: 100%; position: static; order: 2; }
        .soal-image-box img { max-height: 320px; }
        .fc-row { flex-direction: column; align-items: stretch; }
        .fc-choices { justify-content: flex-end; }
    }
</style>

<div class="test-shell" id="testRoot"
     data-attempt-id="<?= $attempt['id'] ?>"
     data-log-url="log_pelanggaran.php"
     data-save-url="simpan_jawaban.php"
     data-forced-choice="<?= $isForcedChoice ? '1' : '0' ?>"
     data-wajib-lengkap="<?= $wajibLengkap ? '1' : '0' ?>"
     data-max-pilihan="<?= (int)$maxPilihan ?>">

    <!-- Header Ujian & Timer -->
    <div class="test-header-card mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-0"><?= e($tes['judul']) ?></h5>
                <span class="text-muted small"><i class="bi bi-file-text me-1"></i>Total <?= count($soalList) ?> Pertanyaan</span>
                <div class="save-status mt-1" id="saveStatus"><i class="bi bi-cloud-check-fill"></i> Semua jawaban tersimpan</div>
            </div>
            <div class="timer-wrap">
                <span class="timer-label">Sisa Waktu</span>
                <div class="timer-badge-modern" id="timerBadge">
                    <i class="bi bi-clock-fill"></i>
                    <span id="timerText">--:--</span>
                </div>
            </div>
        </div>
        <div class="progress-track mt-3">
            <div class="progress-fill" id="progressBar" style="width:0%"></div>
        </div>
    </div>

    <?php if ($isForcedChoice): ?>
    <div class="alert alert-light border small mb-3" style="background:#f8fafc; font-size:13px;">
        <i class="bi bi-info-circle me-1 text-primary"></i>
        Untuk tiap soal, ada 4 pernyataan (A-D). Tandai <strong>satu</strong> pernyataan yang
        <strong>PALING SESUAI</strong> menggambarkan diri Anda, dan <strong>satu pernyataan lain</strong> yang
        <strong>PALING TIDAK SESUAI</strong> menggambarkan diri Anda. Keduanya harus pernyataan yang berbeda.
        <strong>Semua soal wajib diisi</strong> sebelum tes dapat dikumpulkan.
    </div>
    <?php elseif ($isPapikostik): ?>
    <div class="alert alert-light border small mb-3" style="background:#f8fafc; font-size:13px;">
        <i class="bi bi-info-circle me-1 text-primary"></i>
        Pilih <strong>satu jawaban</strong> yang <strong>paling menggambarkan diri Anda</strong>.
        <strong>Semua soal wajib diisi</strong> sebelum tes dapat dikumpulkan.
    </div>
    <?php elseif ($cfitNo === 1 || $cfitNo === 3): ?>
    <div class="alert alert-light border small mb-3" style="background:#f8fafc; font-size:13px;">
        <i class="bi bi-info-circle me-1 text-primary"></i>
        Pilih jawaban yang sesuai dengan <strong>perubahan pola</strong>.
    </div>
    <?php elseif ($cfitNo === 2): ?>
    <div class="alert alert-light border small mb-3" style="background:#f8fafc; font-size:13px;">
        <i class="bi bi-info-circle me-1 text-primary"></i>
        Pilih <strong>2 gambar</strong> yang memiliki <strong>kesamaan</strong>.
        Soal dianggap terjawab setelah <strong>2 gambar</strong> terpilih.
    </div>
    <?php elseif ($cfitNo === 4): ?>
    <div class="alert alert-light border small mb-3" style="background:#f8fafc; font-size:13px;">
        <i class="bi bi-info-circle me-1 text-primary"></i>
        Pilih gambar yang <strong>dapat digabungkan</strong> atau <strong>memiliki titik potong</strong>.
    </div>
    <?php elseif ($isLogikaSingle): ?>
    <div class="alert alert-light border small mb-3" style="background:#f8fafc; font-size:13px;">
        <i class="bi bi-info-circle me-1 text-primary"></i>
       
    </div>
    <?php endif; ?>

    <div class="test-layout">
        <!-- Panel Navigasi Soal -->
        <div class="soal-nav-panel">
            <h6 class="fw-bold text-dark mb-2" style="font-size:0.9rem;">Navigasi Soal</h6>
            <div class="soal-nav-grid mb-3" id="soalNavGrid">
                <?php foreach ($soalList as $idx => $s):
                    $isRagu = isset($raguTersimpan[$s['id']]);
                    $isAnswered = $isForcedChoice
                        ? fcSudahLengkap($s['id'], $mostTersimpan, $leastTersimpan)
                        : ($maxPilihan > 0
                            ? count($jawabanTersimpan[$s['id']] ?? []) === $maxPilihan
                            : !empty($jawabanTersimpan[$s['id']]));
                    $cls = $isRagu ? 'ragu' : ($isAnswered ? 'answered' : '');
                ?>
                <button type="button" class="soal-nav-btn <?= $cls ?>" data-idx="<?= $idx ?>" id="navbtn-<?= $idx ?>"><?= $idx + 1 ?></button>
                <?php endforeach; ?>
            </div>

            <div class="nav-legend border-top pt-3 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="legend-dot" style="background:#dcfce7;border:1px solid #86efac;"></span> Terjawab
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="legend-dot" style="background:#fef3c7;border:1px solid #fde047;"></span> Ragu-ragu
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="legend-dot" style="background:#fff;border:1px solid #cbd5e1;"></span> Belum Dijawab
                </div>
            </div>

            <button type="button" class="btn btn-primary w-100 fw-semibold rounded-3 py-2" id="btnSelesai">
                <i class="bi bi-check2-circle me-1"></i> Selesaikan Tes
            </button>
        </div>

        <!-- Section Lembar Soal -->
        <div class="test-main">
            <form id="formTes" method="POST" action="proses_tes.php">
                <input type="hidden" name="attempt_id" value="<?= $attempt['id'] ?>">

                <?php foreach ($soalList as $idx => $s):
                    $isRagu = isset($raguTersimpan[$s['id']]);
                    $tipeSoal = $s['tipe_soal'] ?? 'single';
                    $isMultiple = $tipeSoal === 'multiple';
                    $jawabanArr = $jawabanTersimpan[$s['id']] ?? [];
                    $mostVal = $mostTersimpan[$s['id']] ?? null;
                    $leastVal = $leastTersimpan[$s['id']] ?? null;
                ?>
                <div class="question-card-modern <?= $idx === 0 ? 'active' : '' ?>" id="qwrap-<?= $idx ?>" data-soal-id="<?= $s['id'] ?>" data-tipe="<?= $tipeSoal ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-light text-primary border px-3 py-2 rounded-pill font-monospace fw-bold">
                            Soal <?= $idx + 1 ?> dari <?= count($soalList) ?>
                        </span>
                    </div>

                    <?php if ($isForcedChoice): ?>

                        <div class="fc-status-banner <?= fcSudahLengkap($s['id'], $mostTersimpan, $leastTersimpan) ? 'complete' : 'incomplete' ?>" id="fcStatus-<?= $idx ?>">
                            <?= fcSudahLengkap($s['id'], $mostTersimpan, $leastTersimpan)
                                ? '<i class="bi bi-check-circle-fill me-1"></i> Sudah lengkap: 1 Paling Sesuai + 1 Paling Tidak Sesuai dipilih.'
                                : '<i class="bi bi-exclamation-circle me-1"></i> Belum lengkap. Pilih 1 pernyataan Paling Sesuai dan 1 pernyataan Paling Tidak Sesuai.' ?>
                        </div>

                        <?php if ($s['gambar']): ?>
                            <div class="mb-3 soal-image-box">
                                <img src="../uploads/soal/<?= e($s['gambar']) ?>" class="rounded-3 border" alt="Gambar soal" onclick="openImageZoom(this.src)">
                                <div class="image-hint"><i class="bi bi-zoom-in"></i> Klik gambar untuk memperbesar</div>
                            </div>
                        <?php endif; ?>

                        <div class="fc-group" data-soal="<?= $s['id'] ?>">
                            <?php foreach ($s['opsi'] as $o):
                                $oid = (int)$o['id'];
                                $isMost = $mostVal === $oid;
                                $isLeast = $leastVal === $oid;
                            ?>
                            <div class="fc-row">
                                <div class="fc-text">
                                    <span class="option-letter-box"><?= e($o['label']) ?></span>
                                    <span><?= e($o['teks']) ?></span>
                                </div>
                                <div class="fc-choices">
                                    <button type="button" class="fc-btn most <?= $isMost ? 'active' : '' ?>" data-opsi="<?= $oid ?>" data-role="most">
                                        <i class="bi bi-hand-thumbs-up-fill"></i> Paling Sesuai
                                    </button>
                                    <button type="button" class="fc-btn least <?= $isLeast ? 'active' : '' ?>" data-opsi="<?= $oid ?>" data-role="least">
                                        <i class="bi bi-hand-thumbs-down-fill"></i> Paling Tidak Sesuai
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>

                        <?php if ($isMultiple && $maxPilihan === 0): ?>
                            <div class="question-type-hint">
                                <i class="bi bi-info-circle me-1"></i>Boleh pilih lebih dari satu jawaban.
                            </div>
                        <?php endif; ?>

                        <div class="fw-semibold text-dark fs-6 mb-3" style="line-height: 1.6;">
                            <?= nl2br(e($s['pertanyaan'])) ?>
                        </div>

                        <?php if ($s['gambar']): ?>
                            <div class="mb-3 soal-image-box">
                                <img src="../uploads/soal/<?= e($s['gambar']) ?>" class="rounded-3 border" alt="Gambar soal" onclick="openImageZoom(this.src)">
                                <div class="image-hint"><i class="bi bi-zoom-in"></i> Klik gambar untuk memperbesar</div>
                            </div>
                        <?php endif; ?>

                        <div class="options-group mb-4">
                            <?php foreach ($s['opsi'] as $o):
                                $checked = in_array((int)$o['id'], $jawabanArr, true);
                                $inputType = $isMultiple ? 'checkbox' : 'radio';
                                $inputName = $isMultiple
                                    ? "jawaban[{$s['id']}][]"
                                    : "jawaban[{$s['id']}]";
                            ?>
                            <label class="option-choice-modern <?= $checked ? 'selected' : '' ?>" data-soal="<?= $s['id'] ?>">
                                <input type="<?= $inputType ?>" name="<?= $inputName ?>" value="<?= $o['id'] ?>" style="display:none;" <?= $checked ? 'checked' : '' ?>>
                                <div class="option-letter-box"><?= e($o['label']) ?></div>
                                <div class="text-dark small pt-1" style="line-height: 1.5;">
                                    <?= e($o['teks']) ?>
                                    <?php if ($o['gambar']): ?>
                                        <div class="opsi-image-box mt-2">
                                            <img src="../uploads/soal/<?= e($o['gambar']) ?>" class="rounded-2 border" alt="Gambar opsi <?= e($o['label']) ?>" onclick="event.stopPropagation(); openImageZoom(this.src);">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>

                    <input type="hidden" class="ragu-input" name="ragu[<?= $s['id'] ?>]" value="<?= $isRagu ? 1 : 0 ?>">

                    <!-- Navigation Action Buttons -->
                    <div class="d-flex justify-content-between align-items-center pt-3 border-top gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-2 px-3" id="btnPrev-<?= $idx ?>" onclick="goTo(<?= $idx - 1 ?>)" <?= $idx === 0 ? 'disabled' : '' ?>>
                            <i class="bi bi-chevron-left me-1"></i> Sebelumnya
                        </button>

                        <button type="button" class="btn-ragu-toggle <?= $isRagu ? 'active' : '' ?>" data-idx="<?= $idx ?>" onclick="toggleRagu(<?= $idx ?>)">
                            <i class="bi bi-flag-fill"></i> <span class="ragu-label"><?= $isRagu ? 'Ditandai Ragu-ragu' : 'Tandai Ragu-ragu' ?></span>
                        </button>

                        <button type="button" class="btn btn-primary btn-sm rounded-2 px-3" onclick="goTo(<?= $idx + 1 ?>)" <?= $idx === count($soalList) - 1 ? 'disabled' : '' ?>>
                            Berikutnya <i class="bi bi-chevron-right ms-1"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </form>
        </div>
    </div>
</div>

<div class="violation-toast-modern" id="violationToast"></div>
<div class="net-banner" id="netBanner"></div>

<!-- Timer melayang (tampil saat timer di header ter-scroll keluar layar) -->
<div class="timer-float" id="timerFloat">
    <span class="timer-label">Sisa Waktu</span>
    <div class="timer-badge-modern" id="timerBadgeFloat">
        <i class="bi bi-clock-fill"></i>
        <span id="timerTextFloat">--:--</span>
    </div>
</div>

<!-- Lightbox untuk memperbesar gambar soal/opsi -->
<div class="image-lightbox-overlay" id="imageLightbox" onclick="closeImageZoomIfBackdrop(event)">
    <button type="button" class="image-lightbox-close" onclick="closeImageZoom(event)" aria-label="Tutup">
        <i class="bi bi-x-lg"></i>
    </button>
    <img id="imageLightboxImg" src="" alt="Gambar diperbesar">
</div>

<script>
const TOTAL_SOAL = <?= count($soalList) ?>;
const ATTEMPT_ID = <?= $attempt['id'] ?>;
const IS_FORCED_CHOICE = document.getElementById('testRoot').dataset.forcedChoice === '1';
const WAJIB_LENGKAP = document.getElementById('testRoot').dataset.wajibLengkap === '1';
const MAX_PILIHAN = parseInt(document.getElementById('testRoot').dataset.maxPilihan || '0', 10);
const SAVE_URL = document.getElementById('testRoot').dataset.saveUrl;
const LOG_URL = document.getElementById('testRoot').dataset.logUrl;
const SERVER_ERR = <?= json_encode($_GET['err'] ?? '') ?>;

let currentIdx = 0;
let isSubmitting = false;
const answeredState = {};

/* ===================== ANTREAN SIMPAN & STATUS KONEKSI ===================== */
const pendingSaves = new Map();   // soal_id -> body terbaru (selalu state terakhir per soal)
let flushing = false;
let failCount = 0;
let retryTimer = null;
let netState = 'online';
let restoredTimer = null;
let rejectedWarned = false;

function setNet(state) {
    if (state === netState) return;
    const prev = netState;
    netState = state;
    const b = document.getElementById('netBanner');
    clearTimeout(restoredTimer);
    b.className = 'net-banner';

    if (state === 'offline') {
        b.innerHTML = '<i class="bi bi-wifi-off"></i> Internet terputus. Jawaban yang sudah terisi sebelumnya aman di server. Jawaban baru akan otomatis dikirim saat internet kembali. Jangan tutup atau refresh halaman.';
        b.classList.add('offline', 'show');
    } else if (state === 'unstable') {
        b.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Koneksi internet Anda tidak stabil. Sistem terus mencoba menyimpan jawaban Anda...';
        b.classList.add('unstable', 'show');
    } else if (prev !== 'online') {
        b.innerHTML = '<i class="bi bi-wifi"></i> Koneksi pulih. Semua jawaban sudah tersimpan.';
        b.classList.add('restored', 'show');
        restoredTimer = setTimeout(() => b.classList.remove('show'), 3500);
    }
}

function updateSaveIndicator() {
    const el = document.getElementById('saveStatus');
    el.className = 'save-status mt-1';
    if (pendingSaves.size === 0) {
        el.innerHTML = '<i class="bi bi-cloud-check-fill"></i> Semua jawaban tersimpan';
    } else if (flushing && netState === 'online') {
        el.classList.add('saving');
        el.innerHTML = '<i class="bi bi-cloud-arrow-up-fill"></i> Menyimpan...';
    } else {
        el.classList.add('pending');
        el.innerHTML = '<i class="bi bi-cloud-slash-fill"></i> ' + pendingSaves.size + ' soal belum terkirim ke server';
    }
}

function scheduleRetry() {
    clearTimeout(retryTimer);
    const delay = Math.min(2000 * failCount, 10000);
    retryTimer = setTimeout(flushSaves, delay);
}

async function flushSaves() {
    if (flushing) return;
    flushing = true;
    updateSaveIndicator();

    while (pendingSaves.size) {
        const [soalId, body] = pendingSaves.entries().next().value;
        try {
            const ctrl = new AbortController();
            const to = setTimeout(() => ctrl.abort(), 10000); // 10 dtk = dianggap gagal
            const res = await fetch(SAVE_URL, { method: 'POST', body, signal: ctrl.signal });
            clearTimeout(to);

            // Error 4xx (attempt tidak valid/sudah selesai, data ditolak server):
            // mengulang tidak akan membantu, jadi buang dari antrean & beri tahu sekali.
            if (res.status >= 400 && res.status < 500) {
                if (pendingSaves.get(soalId) === body) pendingSaves.delete(soalId);
                if (!rejectedWarned) {
                    rejectedWarned = true;
                    showToast('Server menolak penyimpanan salah satu jawaban. Jika berlanjut, muat ulang halaman.');
                }
                setNet('online');
                continue;
            }
            if (!res.ok) throw new Error('HTTP ' + res.status);

            // Hapus dari antrean hanya kalau belum ada perubahan baru selama request berjalan
            if (pendingSaves.get(soalId) === body) pendingSaves.delete(soalId);
            failCount = 0;
            setNet('online');
        } catch (err) {
            failCount++;
            setNet(navigator.onLine ? 'unstable' : 'offline');
            flushing = false;
            updateSaveIndicator();
            scheduleRetry();
            return;
        }
    }
    flushing = false;
    updateSaveIndicator();
}

function queueSave(soalId, body) {
    pendingSaves.set(String(soalId), body);
    updateSaveIndicator();
    flushSaves();
}

async function flushAndWait(maxMs) {
    const start = Date.now();
    while (pendingSaves.size && Date.now() - start < maxMs) {
        await flushSaves();
        if (pendingSaves.size) await new Promise(r => setTimeout(r, 1000));
    }
    return pendingSaves.size === 0;
}

window.addEventListener('offline', () => setNet('offline'));
window.addEventListener('online', () => { failCount = 0; flushSaves(); });

<?php foreach ($soalList as $idx => $s):
    $isRagu = isset($raguTersimpan[$s['id']]);
    $isAnswered = $isForcedChoice
        ? fcSudahLengkap($s['id'], $mostTersimpan, $leastTersimpan)
        : ($maxPilihan > 0
            ? count($jawabanTersimpan[$s['id']] ?? []) === $maxPilihan
            : !empty($jawabanTersimpan[$s['id']]));
    $state = $isRagu ? 'ragu' : ($isAnswered ? 'answered' : 'null');
?>
answeredState[<?= $idx ?>] = <?= $state === 'null' ? 'null' : "'" . $state . "'" ?>;
<?php endforeach; ?>

function soalIdOf(idx) {
    return document.getElementById('qwrap-' + idx).dataset.soalId;
}

function goTo(idx) {
    if (idx < 0 || idx >= TOTAL_SOAL) return;
    document.getElementById('qwrap-' + currentIdx).classList.remove('active');
    document.getElementById('qwrap-' + idx).classList.add('active');
    currentIdx = idx;
    document.querySelectorAll('.soal-nav-btn').forEach(b => b.classList.remove('active'));

    const activeNavBtn = document.getElementById('navbtn-' + idx);
    activeNavBtn.classList.add('active');
    activeNavBtn.scrollIntoView({ block: 'nearest', inline: 'center' });
}

function refreshNavBtn(idx) {
    const btn = document.getElementById('navbtn-' + idx);
    btn.classList.remove('answered', 'ragu');
    const state = answeredState[idx];
    if (state === 'ragu') btn.classList.add('ragu');
    else if (state === 'answered') btn.classList.add('answered');
    updateProgress();
}

function updateProgress() {
    const answered = Object.values(answeredState).filter(s => s === 'answered' || s === 'ragu').length;
    document.getElementById('progressBar').style.width = (TOTAL_SOAL ? (answered / TOTAL_SOAL * 100) : 0) + '%';
}

/* ===================== SOAL BIASA (single / multiple) ===================== */

function saveJawaban(idx) {
    const soalId = soalIdOf(idx);
    const ragu = document.querySelector('#qwrap-' + idx + ' .ragu-input').value === '1';
    const checkedInputs = document.querySelectorAll('#qwrap-' + idx + ' input:checked');
    const opsiIds = Array.from(checkedInputs).map(i => i.value);

    // CFIT 2: baru dianggap terjawab (hijau) kalau tepat MAX_PILIHAN gambar terpilih
    const qTipe = document.getElementById('qwrap-' + idx).dataset.tipe;
    const lengkap = (MAX_PILIHAN > 0 && qTipe === 'multiple')
        ? opsiIds.length === MAX_PILIHAN
        : opsiIds.length > 0;
    answeredState[idx] = lengkap ? (ragu ? 'ragu' : 'answered') : (ragu ? 'ragu' : null);
    refreshNavBtn(idx);

    const body = new URLSearchParams();
    body.set('attempt_id', ATTEMPT_ID);
    body.set('soal_id', soalId);
    body.set('ragu_ragu', ragu ? '1' : '0');
    opsiIds.forEach(id => body.append('opsi_id[]', id));
    queueSave(soalId, body);
}

document.querySelectorAll('.option-choice-modern').forEach(function (el) {
    el.addEventListener('click', function (e) {
        // Cegah aksi bawaan <label> supaya handler ini hanya jalan sekali per klik
        e.preventDefault();

        const soalId = this.dataset.soal;
        const qwrap = this.closest('.question-card-modern');
        const idx = parseInt(qwrap.id.replace('qwrap-', ''), 10);
        const isMultiple = qwrap.dataset.tipe === 'multiple';
        const input = this.querySelector('input');

        if (isMultiple) {
            // Batas maksimal pilihan (CFIT 2 = 2 gambar)
            if (MAX_PILIHAN > 0 && !input.checked
                && qwrap.querySelectorAll('input:checked').length >= MAX_PILIHAN) {
                Swal.fire({
                    toast: true,
                    position: 'top',
                    icon: 'warning',
                    title: 'Maksimal ' + MAX_PILIHAN + ' gambar. Batalkan salah satu dulu untuk memilih yang lain.',
                    showConfirmButton: false,
                    timer: 2800
                });
                return;
            }
            input.checked = !input.checked;
            this.classList.toggle('selected', input.checked);
        } else {
            document.querySelectorAll('.option-choice-modern[data-soal="' + soalId + '"]').forEach(o => o.classList.remove('selected'));
            this.classList.add('selected');
            input.checked = true;
        }
        saveJawaban(idx);
    });
});

/* ===================== SOAL FORCED-CHOICE (Most / Least) ===================== */

// State di memori: fcState[soalId] = { most: opsiId|null, least: opsiId|null }
const fcState = {};
document.querySelectorAll('.fc-group').forEach(function (group) {
    const soalId = group.dataset.soal;
    let most = null, least = null;
    group.querySelectorAll('.fc-btn.most.active').forEach(b => most = b.dataset.opsi);
    group.querySelectorAll('.fc-btn.least.active').forEach(b => least = b.dataset.opsi);
    fcState[soalId] = { most, least };
});

function fcIdxOfSoal(soalId) {
    const qwrap = document.querySelector('.fc-group[data-soal="' + soalId + '"]').closest('.question-card-modern');
    return parseInt(qwrap.id.replace('qwrap-', ''), 10);
}

function fcRefreshStatus(soalId) {
    const idx = fcIdxOfSoal(soalId);
    const st = fcState[soalId];
    const lengkap = st.most !== null && st.least !== null && st.most !== st.least;
    const banner = document.getElementById('fcStatus-' + idx);
    if (banner) {
        banner.classList.toggle('complete', lengkap);
        banner.classList.toggle('incomplete', !lengkap);
        banner.innerHTML = lengkap
            ? '<i class="bi bi-check-circle-fill me-1"></i> Sudah lengkap: 1 Paling Sesuai + 1 Paling Tidak Sesuai dipilih.'
            : '<i class="bi bi-exclamation-circle me-1"></i> Belum lengkap. Pilih 1 pernyataan Paling Sesuai dan 1 pernyataan Paling Tidak Sesuai.';
    }

    const ragu = document.querySelector('#qwrap-' + idx + ' .ragu-input').value === '1';
    answeredState[idx] = lengkap ? (ragu ? 'ragu' : 'answered') : (ragu ? 'ragu' : null);
    refreshNavBtn(idx);
}

function fcSave(soalId) {
    const idx = fcIdxOfSoal(soalId);
    const ragu = document.querySelector('#qwrap-' + idx + ' .ragu-input').value === '1';
    const st = fcState[soalId];

    const body = new URLSearchParams();
    body.set('attempt_id', ATTEMPT_ID);
    body.set('soal_id', soalId);
    body.set('ragu_ragu', ragu ? '1' : '0');
    if (st.most !== null) body.set('opsi_id_most', st.most);
    if (st.least !== null) body.set('opsi_id_least', st.least);
    queueSave(soalId, body);
}

document.querySelectorAll('.fc-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const group = this.closest('.fc-group');
        const soalId = group.dataset.soal;
        const role = this.dataset.role; // 'most' atau 'least'
        const opsiId = this.dataset.opsi;
        const st = fcState[soalId];

        const sedangAktif = this.classList.contains('active');

        // Toggle: klik lagi tombol yang sama untuk membatalkan pilihan
        if (sedangAktif) {
            group.querySelectorAll('.fc-btn.' + role).forEach(b => b.classList.remove('active'));
            st[role] = null;
        } else {
            // Kalau pernyataan ini sedang jadi pilihan di role SEBALIKNYA, batalkan dulu
            // di sana supaya satu pernyataan tidak bisa jadi Most sekaligus Least.
            const roleLawan = role === 'most' ? 'least' : 'most';
            if (st[roleLawan] === opsiId) {
                group.querySelectorAll('.fc-btn.' + roleLawan + '[data-opsi="' + opsiId + '"]').forEach(b => b.classList.remove('active'));
                st[roleLawan] = null;
            }

            group.querySelectorAll('.fc-btn.' + role).forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            st[role] = opsiId;
        }

        fcRefreshStatus(soalId);
        fcSave(soalId);
    });
});

/* ===================== Navigasi umum ===================== */

function toggleRagu(idx) {
    const input = document.querySelector('#qwrap-' + idx + ' .ragu-input');
    const btn = document.querySelector('#qwrap-' + idx + ' .btn-ragu-toggle');
    const nowRagu = input.value !== '1';
    input.value = nowRagu ? '1' : '0';
    btn.classList.toggle('active', nowRagu);
    btn.querySelector('.ragu-label').textContent = nowRagu ? 'Ditandai Ragu-ragu' : 'Tandai Ragu-ragu';

    const soalId = soalIdOf(idx);
    if (IS_FORCED_CHOICE && document.querySelector('#qwrap-' + idx + ' .fc-group')) {
        fcRefreshStatus(soalId);
        fcSave(soalId);
    } else {
        saveJawaban(idx);
    }
}

document.querySelectorAll('.soal-nav-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { goTo(parseInt(this.dataset.idx, 10)); });
});

/* ===================== Cek kelengkapan jawaban ===================== */

// Soal dianggap terjawab berdasarkan jawaban NYATA (bukan status ragu-ragu)
function soalTerjawab(idx) {
    const qwrap = document.getElementById('qwrap-' + idx);
    const fcGroup = qwrap.querySelector('.fc-group');
    if (fcGroup) {
        const st = fcState[fcGroup.dataset.soal];
        return st.most !== null && st.least !== null && st.most !== st.least;
    }
    const jumlah = qwrap.querySelectorAll('input:checked').length;
    if (MAX_PILIHAN > 0 && qwrap.dataset.tipe === 'multiple') return jumlah === MAX_PILIHAN;
    return jumlah > 0;
}

function daftarBelumTerjawab() {
    const list = [];
    for (let i = 0; i < TOTAL_SOAL; i++) {
        if (!soalTerjawab(i)) list.push(i);
    }
    return list;
}

function ringkasNomor(list) {
    const nomor = list.map(i => i + 1);
    return nomor.length > 15 ? nomor.slice(0, 15).join(', ') + ', ...' : nomor.join(', ');
}

goTo(0);
updateProgress();

// Kalau server menolak submit karena jawaban belum lengkap
if (SERVER_ERR === 'belum_lengkap') {
    history.replaceState(null, '', 'tes.php?id=<?= (int)$tesId ?>');
    const belumServer = daftarBelumTerjawab();
    Swal.fire({
        icon: 'warning',
        title: 'Jawaban Belum Lengkap',
        text: 'Tes belum bisa dikumpulkan karena masih ada soal yang belum diisi. Silakan lengkapi terlebih dahulu.',
        confirmButtonColor: '#2563eb'
    }).then(() => { if (belumServer.length) goTo(belumServer[0]); });
}

/* Lightbox gambar soal/opsi */
function openImageZoom(src) {
    const overlay = document.getElementById('imageLightbox');
    const img = document.getElementById('imageLightboxImg');
    img.src = src;
    img.classList.remove('zoomed');
    overlay.classList.add('show');
}
function closeImageZoom(e) {
    if (e) e.stopPropagation();
    document.getElementById('imageLightbox').classList.remove('show');
    document.getElementById('imageLightboxImg').classList.remove('zoomed');
}
function closeImageZoomIfBackdrop(e) {
    if (e.target.id === 'imageLightbox') closeImageZoom(e);
}
document.getElementById('imageLightboxImg').addEventListener('click', function (e) {
    e.stopPropagation();
    this.classList.toggle('zoomed');
});

/* Navigation Guard & Submit */
function beforeUnloadHandler(e) {
    e.preventDefault();
    e.returnValue = '';
}
window.addEventListener('beforeunload', beforeUnloadHandler);

function disableUnloadGuard() {
    window.removeEventListener('beforeunload', beforeUnloadHandler);
    window.onbeforeunload = null;
}

async function submitTes(force = false) {
    if (isSubmitting) return;

    // Pastikan semua jawaban sudah masuk ke server sebelum dikumpulkan
    if (pendingSaves.size) {
        if (!force) {
            Swal.fire({
                title: 'Menyimpan jawaban...',
                text: 'Mohon tunggu, jangan tutup halaman.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });
        }
        const ok = await flushAndWait(force ? 5000 : 15000);
        if (!ok && !force) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Bermasalah',
                text: 'Jawaban belum berhasil terkirim ke server. Tes belum dikumpulkan. Periksa internet Anda lalu coba klik "Selesaikan Tes" lagi.',
                confirmButtonColor: '#2563eb'
            });
            return;
        }
    }

    isSubmitting = true;
    disableUnloadGuard();
    document.getElementById('formTes').submit();
}

function redirectReset(url) {
    if (isSubmitting) return;
    isSubmitting = true;
    disableUnloadGuard();
    window.location.href = url;
}

document.getElementById('btnSelesai').addEventListener('click', async function () {
    const belum = daftarBelumTerjawab();

    // DISC & Papikostik: tidak boleh submit sebelum semua terisi
    if (WAJIB_LENGKAP && belum.length > 0) {
        const r = await Swal.fire({
            icon: 'warning',
            title: 'Jawaban Belum Lengkap',
            html: 'Masih ada <b>' + belum.length + '</b> soal yang belum dijawab'
                + (IS_FORCED_CHOICE ? ' (pilih <b>Paling</b> dan <b>Paling Tidak</b>)' : '')
                + '.<br>Nomor: <b>' + ringkasNomor(belum) + '</b>'
                + '<br><br>Semua soal wajib diisi sebelum tes dapat dikumpulkan.',
            showCancelButton: true,
            confirmButtonText: 'Ke soal ' + (belum[0] + 1),
            cancelButtonText: 'Tutup',
            confirmButtonColor: '#2563eb'
        });
        if (r.isConfirmed) goTo(belum[0]);
        return;
    }

    // Tes lain (MBTI, logika, dll): boleh submit, tapi diberi peringatan
    const r = await Swal.fire({
        icon: 'question',
        title: 'Selesaikan Tes?',
        html: belum.length > 0
            ? 'Masih ada <b>' + belum.length + '</b> soal yang belum dijawab (no. ' + ringkasNomor(belum) + ').<br>Tetap kumpulkan sekarang?'
            : 'Semua jawaban Anda akan dikumpulkan dan tidak dapat diubah lagi.',
        showCancelButton: true,
        confirmButtonText: 'Ya, Kumpulkan',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#2563eb'
    });
    if (r.isConfirmed) submitTes();
});

/* Timer Engine */
let sisa = <?= (int)$sisaDetik ?>;
const timerText = document.getElementById('timerText');
const timerBadge = document.getElementById('timerBadge');
const timerTextFloat = document.getElementById('timerTextFloat');
const timerBadgeFloat = document.getElementById('timerBadgeFloat');
const timerFloat = document.getElementById('timerFloat');

function renderTimer() {
    const h = Math.floor(sisa / 3600);
    const m = Math.floor((sisa % 3600) / 60);
    const s = sisa % 60;
    const teks = h > 0
        ? h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
        : String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    timerText.textContent = teks;
    timerTextFloat.textContent = teks;
    if (sisa <= 60) {
        timerBadge.classList.add('low');
        timerBadgeFloat.classList.add('low');
    }
}
renderTimer();

// Tampilkan timer melayang hanya saat timer di header sudah tidak terlihat
if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
        timerFloat.classList.toggle('show', !entries[0].isIntersecting);
    }, { threshold: 0 }).observe(timerBadge);
}
async function handleTimeUp() {
    // Kunci pengisian jawaban, tapi antrean yang sudah ada tetap dikirim
    document.getElementById('formTes').style.pointerEvents = 'none';
    document.getElementById('btnSelesai').disabled = true;

    Swal.fire({
        icon: 'info',
        title: 'Waktu Habis',
        text: 'Jawaban Anda sedang dikumpulkan...',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false
    });

    // Coba kirim sisa antrean dulu
    await flushAndWait(5000);

    // Kalau masih ada yang belum terkirim, tunggu sampai koneksi kembali
    while (pendingSaves.size > 0 || !navigator.onLine) {
        Swal.update({
            text: 'Waktu habis. Menunggu koneksi internet untuk mengirim jawaban Anda. Jangan tutup atau refresh halaman.'
        });
        await new Promise(r => setTimeout(r, 2000));
        await flushSaves();
    }

    submitTes(true);
}
const interval = setInterval(function () {
    sisa--;
    if (sisa <= 0) {
        sisa = 0;
        renderTimer();
        clearInterval(interval);
        handleTimeUp();
        return;
    }
    renderTimer();
}, 1000);

/* Anti-Cheat Safeguards */
let toastTimer = null;
function showToast(msg) {
    const t = document.getElementById('violationToast');
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.display = 'none'; }, 4000);
}

function triggerShake() {
    const activeCard = document.getElementById('qwrap-' + currentIdx);
    activeCard.classList.remove('shake-warn');
    void activeCard.offsetWidth;
    activeCard.classList.add('shake-warn');
}

function lapor(alasan) {
    const body = new URLSearchParams();
    body.set('attempt_id', ATTEMPT_ID);
    body.set('alasan', alasan);
    fetch(LOG_URL, { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            if (data.reset) {
                triggerShake();
                showToast('Terdeteksi berpindah tab/aplikasi. Ujian direset.');
                setTimeout(() => { redirectReset(data.redirect); }, 1200);
            }
        }).catch(() => {});
}

document.addEventListener('visibilitychange', function () {
    if (document.hidden) lapor('tab_hidden');
});
window.addEventListener('blur', function () {
    setTimeout(() => { if (document.hidden || !document.hasFocus()) lapor('window_blur'); }, 150);
});

document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
document.addEventListener('copy', function (e) { e.preventDefault(); });
document.addEventListener('cut', function (e) { e.preventDefault(); });
document.addEventListener('keydown', function (e) {
    const blocked = (e.ctrlKey || e.metaKey) && ['c', 'u', 'p', 'f'].includes(e.key.toLowerCase());
    const devtools = e.key === 'F12' || ((e.ctrlKey || e.metaKey) && e.shiftKey && ['I', 'J', 'C'].includes(e.key));
    if (blocked || devtools) e.preventDefault();
});
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>