<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');

$db = getDB();
$userId = $_SESSION['user_id'];

// Wajib upload CV & Form Lamaran sebelum bisa mengakses tes
$cekLamaran = $db->prepare("SELECT id FROM lamaran WHERE user_id = ?");
$cekLamaran->execute([$userId]);
if (!$cekLamaran->fetch()) {
    $_SESSION['info_upload_required'] = true;
    header('Location: upload_lamaran.php');
    exit;
}

// Ambil daftar tes aktif beserta statistik soal & info grup (kalau ada),
// diurutkan sesuai kolom `urutan` yang diatur admin di Kelola Tes, lalu
// urutan bagian dalam grup.
$tesAktif = $db->query("
    SELECT t.*, g.judul AS grup_judul, g.deskripsi AS grup_deskripsi,
           (SELECT COUNT(*) FROM soal s WHERE s.tes_id = t.id) AS jumlah_soal 
    FROM tes t 
    LEFT JOIN tes_grup g ON g.id = t.grup_id
    WHERE t.status = 'aktif' 
    ORDER BY t.urutan ASC, t.grup_id ASC, t.urutan_dalam_grup ASC, t.id ASC
")->fetchAll();

// Prepared statement untuk status pengerjaan per tes
$statusStmt = $db->prepare("
    SELECT tes_id, status, id 
    FROM attempt 
    WHERE user_id = ? AND tes_id = ? 
    ORDER BY id DESC LIMIT 1
");

// Ambil riwayat terbaru
$riwayatTerbaru = $db->prepare("
    SELECT a.*, t.judul AS judul_tes, t.jenis 
    FROM attempt a
    JOIN tes t ON t.id = a.tes_id
    WHERE a.user_id = ? 
    ORDER BY a.waktu_mulai DESC LIMIT 5
");
$riwayatTerbaru->execute([$userId]);
$riwayat = $riwayatTerbaru->fetchAll();

// -----------------------------------------------------------------------
// Kelompokkan tes menjadi "unit" tampilan: tes yang punya grup_id sama
// (mis. CFIT 1-4) digabung jadi 1 kartu; tes lain tetap 1 kartu = 1 tes.
// Tiap unit tetap membawa daftar anggotanya (dengan status attempt
// masing-masing) supaya bisa dicek/ditautkan per bagian.
// -----------------------------------------------------------------------
$unitMap = [];
$unitKeyOrder = [];
foreach ($tesAktif as $t) {
    $statusStmt->execute([$userId, $t['id']]);
    $t['_status'] = $statusStmt->fetch();

    $key = $t['grup_id'] ? 'grup-' . $t['grup_id'] : 'tes-' . $t['id'];
    if (!isset($unitMap[$key])) {
        $unitMap[$key] = ['anggota' => []];
        $unitKeyOrder[] = $key;
    }
    $unitMap[$key]['anggota'][] = $t;
}

$units = [];
foreach ($unitKeyOrder as $key) {
    $anggota = $unitMap[$key]['anggota'];
    $isGrup = $anggota[0]['grup_id'] !== null;
    $utama = $anggota[0];

    $totalSelesai = 0;
    $adaBerlangsung = false;
    foreach ($anggota as $a) {
        if ($a['_status'] && $a['_status']['status'] === 'selesai') $totalSelesai++;
        elseif ($a['_status'] && $a['_status']['status'] === 'berlangsung') $adaBerlangsung = true;
    }
    $sudahMulaiUnit = $totalSelesai > 0 || $adaBerlangsung;
    $statusUnit = $totalSelesai === count($anggota) ? 'selesai' : ($sudahMulaiUnit ? 'berlangsung' : null);

    // Bagian aktif: bagian pertama yang belum selesai. Dipakai sebagai
    // target tombol Mulai/Lanjutkan Ujian.
    $anggotaAktif = null;
    foreach ($anggota as $a) {
        if (!$a['_status'] || $a['_status']['status'] !== 'selesai') { $anggotaAktif = $a; break; }
    }

    $units[] = [
        'key'           => $key,
        'is_grup'       => $isGrup,
        'judul'         => $isGrup ? $utama['grup_judul'] : $utama['judul'],
        'deskripsi'     => $isGrup ? $utama['grup_deskripsi'] : $utama['deskripsi'],
        'jenis'         => $utama['jenis'],
        'anggota'       => $anggota,
        'jumlah_soal'   => array_sum(array_map(fn($a) => (int)$a['jumlah_soal'], $anggota)),
        'durasi_menit'  => array_sum(array_map(fn($a) => (int)$a['durasi_menit'], $anggota)),
        'status'        => $statusUnit,
        'anggota_aktif' => $anggotaAktif,
        'urutan_min'    => min(array_map(fn($a) => (int)$a['urutan'], $anggota)),
    ];
}

// Urutkan unit berdasarkan urutan terkecil di antara anggotanya
usort($units, fn($a, $b) => $a['urutan_min'] <=> $b['urutan_min']);

// Tentukan kunci per unit secara berurutan (sama seperti logic lama,
// sekarang di level unit: kartu grup dianggap satu kesatuan)
$totalUnitSelesai = 0;
$penghalang = null;
foreach ($units as &$u) {
    $u['_terkunci'] = !$u['status'] && $penghalang !== null;
    $u['_alasan_kunci'] = $penghalang;

    if ($u['status'] === 'selesai') {
        $totalUnitSelesai++;
    } elseif ($penghalang === null) {
        $penghalang = $u['judul'];
    }
}
unset($u);

$pageTitle = 'Dashboard Peserta';
$pageDesc = 'Pantau modul ujian yang tersedia dan riwayat aktivitas Anda';
$activeMenu = 'dashboard';

// Flag sekali-pakai dari login.php: kalau ada, tampilkan popup peringatan
// hanya SEKALI (tepat setelah login), lalu langsung dihapus dari session
// supaya tidak muncul lagi saat refresh atau kembali ke dashboard nanti.
$showLoginNotice = !empty($_SESSION['show_login_notice']);
unset($_SESSION['show_login_notice']);

include __DIR__ . '/../includes/layout_top.php';
?>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* Custom Styling khusus Dashboard Peserta */
    .welcome-card {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        border-radius: 16px;
        color: #ffffff;
        padding: 28px 32px;
        position: relative;
        overflow: hidden;
    }
    .welcome-card::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 300px;
        height: 300px;
        background: radial-gradient(circle, rgba(59, 130, 246, 0.25) 0%, transparent 70%);
        border-radius: 50%;
    }

    .card-test-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 24px;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: all 0.25s ease;
    }
    .card-test-modern:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 24px -10px rgba(15, 23, 42, 0.08);
        border-color: #cbd5e1;
    }
    .card-test-modern.locked {
        background: #f8fafc;
    }
    .card-test-modern.locked h5,
    .card-test-modern.locked p {
        opacity: 0.65;
    }

    .badge-modern {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }
    .badge-logika { background: #eff6ff; color: #2563eb; }
    .badge-psikotes { background: #fdf4ff; color: #c026d3; }
    .badge-eksternal { background: #fff7ed; color: #c2410c; }
    .badge-done { background: #f0fdf4; color: #16a34a; }
    .badge-ongoing { background: #fffbeb; color: #d97706; }
    .badge-locked { background: #f1f5f9; color: #64748b; }

    .test-meta {
        background: #f8fafc;
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.825rem;
        color: #64748b;
        font-weight: 500;
    }

    .table-modern-wrapper {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 24px;
    }
    .table-modern {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .table-modern th {
        color: #64748b;
        font-size: 0.775rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 12px 16px;
        border-bottom: 1px solid #e2e8f0;
    }
    .table-modern td {
        padding: 16px;
        font-size: 0.875rem;
        color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .table-modern tr:last-child td {
        border-bottom: none;
    }

    .btn-action {
        border-radius: 10px;
        padding: 10px 16px;
        font-size: 0.875rem;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    .btn-brand-primary {
        background: #0f172a;
        color: #ffffff;
        border: none;
    }
    .btn-brand-primary:hover {
        background: #2563eb;
        color: #ffffff;
    }
    .btn-locked {
        background: #e2e8f0;
        color: #64748b;
        border: none;
        cursor: pointer;
    }
    .btn-locked:hover {
        background: #cbd5e1;
        color: #475569;
    }

    /* Kotak peringatan pindah tab (popup login & konfirmasi mulai) */
    .tab-warning {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 12px;
        padding: 12px 14px;
        color: #991b1b;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    .tab-warning-ok {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 12px;
        padding: 12px 14px;
        color: #166534;
        font-size: 0.875rem;
        line-height: 1.5;
    }

    /* Info kondisi internet (popup login & konfirmasi mulai) */
    .net-info {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 14px;
        font-size: 0.85rem;
        line-height: 1.5;
        color: #334155;
    }
    .net-item {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        padding: 8px 10px;
        border-radius: 8px;
        text-align: left;
    }
    .net-item i { margin-top: 2px; flex-shrink: 0; }
    .net-offline  { background: #fef2f2; color: #991b1b; }
    .net-unstable { background: #fffbeb; color: #92400e; }
    .net-restored { background: #f0fdf4; color: #166534; }

    /* ===================== ANIMASI ===================== */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes rowIn {
        from { opacity: 0; transform: translateX(-12px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    @keyframes alertDrop {
        from { opacity: 0; transform: translateY(-14px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes modalPop {
        from { opacity: 0; transform: scale(0.92) translateY(20px); }
        to   { opacity: 1; transform: scale(1) translateY(0); }
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
    }
    @keyframes floatGlow {
        from { transform: translate(0, 0) scale(1); }
        to   { transform: translate(-30px, 24px) scale(1.15); }
    }
    @keyframes wave {
        0%, 60%, 100% { transform: rotate(0deg); }
        10%, 30% { transform: rotate(16deg); }
        20%, 40% { transform: rotate(-8deg); }
        50% { transform: rotate(10deg); }
    }
    @keyframes ongoingGlow {
        0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); border-color: #fde68a; }
        50%      { box-shadow: 0 0 0 5px rgba(245, 158, 11, 0.15); border-color: #f59e0b; }
    }
    @keyframes hourglassFlip {
        0%, 70% { transform: rotate(0deg); }
        100%    { transform: rotate(180deg); }
    }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20% { transform: translateX(-5px); }
        40% { transform: translateX(5px); }
        60% { transform: translateX(-3px); }
        80% { transform: translateX(3px); }
    }

    /* Banner selamat datang */
    .welcome-card { animation: fadeUp 0.6s cubic-bezier(.22,.9,.36,1) backwards; }
    .welcome-card::after { animation: floatGlow 8s ease-in-out infinite alternate; }
    .welcome-card .wave {
        display: inline-block;
        transform-origin: 70% 70%;
        animation: wave 1.8s ease-in-out 0.8s 2;
    }
    .welcome-card .background-blur { animation: fadeUp 0.6s cubic-bezier(.22,.9,.36,1) 0.25s backwards; }

    /* Alert & judul bagian */
    .alert { animation: alertDrop 0.45s cubic-bezier(.22,.9,.36,1) 0.15s backwards; }
    .section-head { animation: fadeIn 0.5s ease 0.2s backwards; }

    /* Kartu tes muncul bergantian
       (fill-mode "backwards" supaya efek hover translateY tetap jalan setelah animasi selesai) */
    .card-test-modern {
        animation: fadeUp 0.5s cubic-bezier(.22,.9,.36,1) backwards;
        animation-delay: calc(0.25s + min(var(--i, 0), 8) * 70ms);
    }

    /* Kartu tes yang sedang berlangsung: border berdenyut halus */
    .card-test-modern.is-ongoing {
        border-color: #fde68a;
        animation: fadeUp 0.5s cubic-bezier(.22,.9,.36,1) backwards,
                   ongoingGlow 2.4s ease-in-out 1.2s infinite;
        animation-delay: calc(0.25s + min(var(--i, 0), 8) * 70ms), 1.2s;
    }
    .badge-ongoing i.bi-hourglass-split { display: inline-block; animation: hourglassFlip 2.4s ease-in-out infinite; }

    /* Badge & meta */
    .badge-modern { transition: transform 0.2s ease; }
    .card-test-modern:hover .badge-modern { transform: scale(1.05); }
    .card-test-modern .test-meta i { transition: transform 0.25s ease; display: inline-block; }
    .card-test-modern:hover .test-meta i { transform: scale(1.2) rotate(-8deg); }

    /* Tombol aksi */
    .btn-action { transition: transform 0.15s ease, box-shadow 0.2s ease, background-color 0.2s ease, color 0.2s ease; }
    .btn-action:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 16px -8px rgba(15, 23, 42, 0.35); }
    .btn-action:active:not(:disabled) { transform: scale(0.97); }
    .btn-action i.bi-arrow-right,
    .btn-action i.bi-play-fill { display: inline-block; transition: transform 0.2s ease; }
    .btn-action:hover i.bi-arrow-right,
    .btn-action:hover i.bi-play-fill { transform: translateX(4px); }
    .btn-locked:active { animation: shake 0.35s ease; }
    .btn-locked i { display: inline-block; }
    .btn-locked:hover i { animation: shake 0.45s ease; }

    /* Tabel riwayat */
    .table-modern-wrapper { animation: fadeUp 0.55s cubic-bezier(.22,.9,.36,1) 0.35s backwards; }
    .table-modern tbody tr {
        animation: rowIn 0.4s ease backwards;
        animation-delay: calc(0.6s + min(var(--i, 0), 8) * 50ms);
        transition: background-color 0.15s ease;
    }
    .table-modern tbody tr:hover { background-color: #f8fafc; }
    .table-modern tbody tr:hover td:first-child { box-shadow: inset 3px 0 0 #2563eb; }
    .table-modern tbody tr td:first-child { transition: box-shadow 0.2s ease; }

    /* Link "Lihat Semua": panah bergeser */
    .table-modern-wrapper a i { display: inline-block; transition: transform 0.2s ease; }
    .table-modern-wrapper a:hover i { transform: translateX(4px); }

    /* Popup peringatan login */
    .modal.show .modal-dialog { animation: modalPop 0.35s cubic-bezier(.22,.9,.36,1) backwards; }
    .modal.fade .modal-dialog { transform: none; }

    /* Hormati pengguna yang mematikan animasi di sistemnya */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
    }
</style>

<!-- Banner Selamat Datang & Metric Quick Stats -->
<div class="welcome-card mb-4">
    <div class="row align-items-center position-relative" style="z-index: 2;">
        <div class="col-lg-8 mb-3 mb-lg-0">
            <h4 class="fw-bold mb-1">Halo, <?= e($_SESSION['nama'] ?? 'Peserta') ?>! <span class="wave">👋</span></h4>
            <p class="text-white-50 mb-0" style="font-size: 0.95rem;">
                Siapkan diri Anda dengan baik sebelum memulai ujian. Pastikan koneksi internet stabil.
            </p>
        </div>
        <div class="col-lg-4 text-lg-end">
            <div class="d-inline-flex gap-3 background-blur p-2 px-3 rounded-3" style="background: rgba(255,255,255,0.08);">
                <div class="text-center">
                    <span class="d-block text-white-50 small">Tersedia</span>
                    <span class="fs-5 fw-bold count-up" data-count="<?= count($units) ?>"><?= count($units) ?></span>
                </div>
                <div class="border-end border-white-10 opacity-25"></div>
                <div class="text-center">
                    <span class="d-block text-white-50 small">Selesai</span>
                    <span class="fs-5 fw-bold text-success count-up" data-count="<?= $totalUnitSelesai ?>"><?= $totalUnitSelesai ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($penghalang !== null && $totalUnitSelesai < count($units)): ?>
<div class="alert alert-warning border-0 shadow-sm mb-4" style="font-size:13.5px;">
    <i class="bi bi-info-circle-fill me-2"></i>
    Kerjakan modul tes secara berurutan. Selesaikan <strong><?= e($penghalang) ?></strong> terlebih dahulu untuk membuka modul tes berikutnya.
</div>
<?php endif; ?>

<!-- Grid Daftar Tes Aktif -->
<div class="section-head d-flex align-items-center justify-content-between mb-3">
    <h6 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.01em;">Modul Tes Tersedia</h6>
    <span class="text-muted small"><?= count($units) ?> tes aktif</span>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($units as $ui => $u): 
        $terkunci = $u['_terkunci'];
        $alasanKunci = $u['_alasan_kunci'];
        // Tes eksternal tidak punya bank soal sama sekali (jumlah_soal selalu 0
        // secara wajar) — jadi jangan pakai syarat jumlah_soal untuk tipe ini.
        $butuhSoal = $u['jenis'] !== 'eksternal';
        $anggotaAktif = $u['anggota_aktif'];
        // Tes eksternal dikerjakan di halaman terpisah (buka link + upload
        // screenshot bukti), bukan di halaman bank-soal seperti tes biasa.
        $urlKerjakan = $anggotaAktif
            ? (($anggotaAktif['jenis'] === 'eksternal' ? 'tes_eksternal.php' : 'tes.php') . '?id=' . $anggotaAktif['id'])
            : '#';

        // Tes MBTI dikecualikan dari aturan pindah tab (deteksi lewat judul
        // tes / judul grup). Kalau ada kolom khusus di DB, lebih baik pakai itu.
        $isMbti = stripos((string)$u['judul'], 'mbti') !== false;
        if (!$isMbti) {
            foreach ($u['anggota'] as $a) {
                if (stripos((string)$a['judul'], 'mbti') !== false) { $isMbti = true; break; }
            }
        }
        // Aturan "pindah tab = ulang dari awal" hanya untuk tes yang
        // dikerjakan di sistem ini (bukan eksternal) dan bukan MBTI.
        $aturanTab = ($u['jenis'] !== 'eksternal') && !$isMbti;
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card-test-modern <?= $terkunci ? 'locked' : '' ?><?= (!$terkunci && $u['status'] === 'berlangsung') ? ' is-ongoing' : '' ?>" style="--i: <?= (int)$ui ?>">
            <div>
                <!-- Header Card Badge -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge-modern <?= $u['jenis'] === 'logika' ? 'badge-logika' : ($u['jenis'] === 'eksternal' ? 'badge-eksternal' : 'badge-psikotes') ?>">
                        <i class="bi <?= $u['jenis'] === 'logika' ? 'bi-cpu' : ($u['jenis'] === 'eksternal' ? 'bi-box-arrow-up-right' : 'bi-person-badge') ?> me-1"></i>
                        <?= $u['jenis'] === 'eksternal' ? 'Eksternal' : ucfirst($u['jenis']) ?>
                        <?php if ($u['is_grup']): ?> &middot; <?= count($u['anggota']) ?> Bagian<?php endif; ?>
                    </span>

                    <?php if ($terkunci): ?>
                        <span class="badge-modern badge-locked"><i class="bi bi-lock-fill me-1"></i>Terkunci</span>
                    <?php elseif ($u['status'] === 'selesai'): ?>
                        <span class="badge-modern badge-done"><i class="bi bi-check-circle-fill me-1"></i>Selesai</span>
                    <?php elseif ($u['status'] === 'berlangsung'): ?>
                        <span class="badge-modern badge-ongoing"><i class="bi bi-hourglass-split me-1"></i>Berlangsung</span>
                    <?php endif; ?>
                </div>

                <!-- Judul & Deskripsi -->
                <h5 class="fw-bold text-dark mb-2" style="font-size: 1.05rem;"><?= e($u['judul']) ?></h5>
                <p class="text-muted mb-4" style="font-size: 0.85rem; line-height: 1.5; min-height: 40px;">
                    <?= e($u['deskripsi'] ?: 'Tidak ada deskripsi tambahan untuk tes ini.') ?>
                </p>
            </div>

            <div>
                <!-- Metadata Soal & Waktu -->
                <div class="test-meta d-flex justify-content-between align-items-center mb-3">
                    <?php if ($u['jenis'] === 'eksternal'): ?>
                        <span><i class="bi bi-box-arrow-up-right me-1 text-primary"></i> Tes di situs lain</span>
                    <?php else: ?>
                        <span><i class="bi bi-card-checklist me-1 text-primary"></i> <?= (int)$u['jumlah_soal'] ?> Soal</span>
                    <?php endif; ?>
                    <span><i class="bi bi-clock me-1 text-primary"></i> <?= (int)$u['durasi_menit'] ?> Menit<?= $u['is_grup'] ? ' total' : '' ?></span>
                </div>

                <!-- Action Button -->
                <?php if ($terkunci): ?>
                    <button type="button" class="btn btn-locked btn-action w-100"
                            onclick="konfirmasiTerkunci('<?= e($alasanKunci) ?>', '<?= e($u['judul']) ?>')">
                        <i class="bi bi-lock-fill me-1"></i> Terkunci
                    </button>
                <?php elseif ($butuhSoal && $u['jumlah_soal'] == 0): ?>
                    <button class="btn btn-light text-muted btn-action w-100" disabled>
                        <i class="bi bi-dash-circle me-1"></i> Belum Ada Soal
                    </button>
                <?php elseif ($u['status'] === 'selesai'): ?>
                    <?php if (!$u['is_grup']): ?>
                        <a href="../peserta/hasil_detail.php?id=<?= $u['anggota'][0]['_status']['id'] ?>" class="btn btn-outline-secondary btn-action w-100 text-center d-block">
                            <i class="bi bi-file-earmark-text me-1"></i> Lihat Hasil
                        </a>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-1">
                            <?php foreach ($u['anggota'] as $i => $a): ?>
                                <a href="../peserta/hasil_detail.php?id=<?= $a['_status']['id'] ?>" class="btn btn-outline-secondary btn-action w-100 text-center d-block btn-sm">
                                    <i class="bi bi-file-earmark-text me-1"></i> Hasil Bagian <?= $i + 1 ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($u['status'] === 'berlangsung'): ?>
                    <a href="<?= e($urlKerjakan) ?>" class="btn btn-warning text-white btn-action w-100 text-center d-block">
                        Lanjutkan Tes <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                <?php else: ?>
                    <button type="button" 
                            class="btn btn-brand-primary btn-action w-100 text-center d-block" 
                            onclick="konfirmasiMulai('<?= e($urlKerjakan) ?>', '<?= e($u['judul']) ?>', <?= $aturanTab ? 'true' : 'false' ?>, <?= $isMbti ? 'true' : 'false' ?>)">
                        Mulai Ujian <i class="bi bi-play-fill ms-1"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($units)): ?>
        <div class="col-12">
            <div class="card-test-modern text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                <h6 class="fw-bold text-dark mb-1">Belum Ada Tes Tersedia</h6>
                <p class="text-muted small mb-0">Silakan hubungi administrator untuk informasi jadwal tes terbaru.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Riwayat Terbaru Table -->
<div class="table-modern-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h6 class="fw-bold text-dark mb-0">Riwayat Ujian Terbaru</h6>
            <span class="text-muted small">5 pengerjaan tes terakhir Anda</span>
        </div>
        <a href="riwayat.php" class="fw-semibold text-decoration-none small text-primary">
            Lihat Semua <i class="bi bi-chevron-right ms-1"></i>
        </a>
    </div>

    <?php if (empty($riwayat)): ?>
        <div class="text-center py-4 text-muted small">
            <i class="bi bi-journal-x d-block fs-3 mb-1 opacity-50"></i>
            Anda belum pernah mengerjakan tes apapun.
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table-modern">
            <thead>
                <tr>
                    <th>Nama Modul Tes</th>
                    <th>Status</th>
                    <th>Waktu Mulai</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($riwayat as $ri => $r): ?>
                <tr style="--i: <?= (int)$ri ?>">
                    <td class="fw-semibold text-dark">
                        <i class="bi <?= $r['jenis'] === 'logika' ? 'bi-cpu' : ($r['jenis'] === 'eksternal' ? 'bi-box-arrow-up-right' : 'bi-person-badge') ?> text-muted me-2"></i>
                        <?= e($r['judul_tes']) ?>
                    </td>
                    <td>
                        <?= $r['status'] === 'selesai' 
                            ? '<span class="badge-modern badge-done"><i class="bi bi-check-circle-fill me-1"></i>Selesai</span>' 
                            : '<span class="badge-modern badge-ongoing"><i class="bi bi-hourglass-split me-1"></i>Berlangsung</span>' 
                        ?>
                    </td>
                    <td class="text-muted small">
                        <i class="bi bi-calendar3 me-1"></i> <?= formatTanggal($r['waktu_mulai']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Popup peringatan sekali-pakai, hanya muncul persis setelah login -->
<?php if ($showLoginNotice): ?>
<div class="modal fade" id="loginNoticeModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 16px; border: none;">
      <div class="modal-header" style="border-bottom: none; padding-bottom: 0;">
        <h5 class="modal-title fw-bold" style="color:#0f172a;">
          <i class="bi bi-shield-exclamation" style="color:#f59e0b;"></i>
          Perhatian Sebelum Memulai
        </h5>
      </div>
      <div class="modal-body" style="font-size: 0.925rem; color:#334155; padding-top: 8px;">
        <ul class="mb-3 ps-3">
          <li class="mb-2">Pastikan koneksi internet Anda stabil selama psikotes berlangsung.</li>
          <li class="mb-2">Waktu pengerjaan berjalan otomatis dan akan <strong>tersubmit otomatis</strong> saat waktu habis.</li>
        </ul>

        <!-- Info kondisi internet -->
        <div class="net-info mb-3">
          <div class="fw-bold mb-2" style="color:#0f172a;">
            <i class="bi bi-wifi me-1"></i> Jika terjadi masalah internet saat tes:
          </div>
          <div class="net-item net-offline mb-2">
            <i class="bi bi-wifi-off"></i>
            <span><strong>Internet terputus.</strong> Jawaban yang sudah terisi sebelumnya aman di server. Jawaban baru akan otomatis dikirim saat internet kembali. <strong>Jangan tutup atau refresh halaman.</strong></span>
          </div>
          <div class="net-item net-unstable mb-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><strong>Koneksi tidak stabil.</strong> Sistem terus mencoba menyimpan jawaban Anda.</span>
          </div>
          <div class="net-item net-restored">
            <i class="bi bi-wifi"></i>
            <span><strong>Koneksi pulih.</strong> Semua jawaban sudah tersimpan.</span>
          </div>
        </div>

        <div class="tab-warning mb-2">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>
          <strong>Jangan berpindah tab / aplikasi saat tes berlangsung!</strong><br>
          Jika Anda berpindah tab, <strong>seluruh jawaban akan terhapus dan tes dimulai dari awal lagi</strong>,
          walaupun Anda sudah menjawab setengahnya. Sistem akan mendeteksi hal ini.
        </div>
        <div class="tab-warning-ok">
          <i class="bi bi-check-circle-fill me-1"></i>
          <strong>Pengecualian:</strong> tes <strong>MBTI</strong> boleh berpindah tab tanpa masalah.
        </div>
      </div>
      <div class="modal-footer" style="border-top: none;">
        <button type="button" class="btn btn-dark w-100" data-bs-dismiss="modal" style="border-radius: 10px;">
          Saya Mengerti
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var noticeModalEl = document.getElementById('loginNoticeModal');
    if (noticeModalEl && typeof bootstrap !== 'undefined') {
        var noticeModal = new bootstrap.Modal(noticeModalEl);
        noticeModal.show();
    }
});
</script>
<?php endif; ?>

<!-- Angka statistik menghitung naik dari 0 -->
<script>
document.querySelectorAll('.count-up').forEach(function (el) {
    var target = parseInt(el.dataset.count, 10) || 0;
    if (target === 0) return;
    var durasi = 800, mulai = performance.now();
    (function tick(now) {
        var p = Math.min((now - mulai) / durasi, 1);
        el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))); // ease-out
        if (p < 1) requestAnimationFrame(tick);
    })(mulai);
});
</script>

<!-- Script Konfirmasi SweetAlert2 -->
<script>
/**
 * @param {string}  url        Tujuan halaman tes
 * @param {string}  judulTes   Judul tes (belum dipakai di popup)
 * @param {boolean} aturanTab  true = pindah tab akan mereset tes dari awal
 * @param {boolean} isMbti     true = tes MBTI (boleh pindah tab)
 */
function konfirmasiMulai(url, judulTes, aturanTab, isMbti) {
    var html = '<div class="text-start">';

    if (aturanTab) {
        html += '<div class="tab-warning mb-3">' +
                  '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' +
                  '<strong>Jangan berpindah tab / aplikasi!</strong><br>' +
                  'Jika Anda berpindah tab, <strong>semua jawaban akan terhapus dan tes dimulai dari awal lagi</strong>, ' +
                  'walaupun Anda sudah menjawab setengahnya.' +
                '</div>';
    } else if (isMbti) {
        html += '<div class="tab-warning-ok mb-3">' +
                  '<i class="bi bi-check-circle-fill me-1"></i> ' +
                  'Khusus tes <strong>MBTI</strong>, Anda <strong>boleh berpindah tab</strong> tanpa masalah.' +
                '</div>';
    }

    // Info internet: hanya untuk tes yang dikerjakan di sistem ini (bukan eksternal)
    if (aturanTab || isMbti) {
        html += '<div class="net-info mb-3">' +
                  '<div class="fw-bold mb-2"><i class="bi bi-wifi me-1"></i> Jika internet bermasalah:</div>' +
                  '<div class="net-item net-offline mb-2">' +
                    '<i class="bi bi-wifi-off"></i>' +
                    '<span><strong>Internet terputus.</strong> Jawaban sebelumnya aman di server dan jawaban baru otomatis terkirim saat internet kembali. ' +
                    '<strong>Jangan tutup atau refresh halaman.</strong></span>' +
                  '</div>' +
                  '<div class="net-item net-unstable mb-2">' +
                    '<i class="bi bi-exclamation-triangle-fill"></i>' +
                    '<span><strong>Koneksi tidak stabil.</strong> Sistem terus mencoba menyimpan jawaban Anda.</span>' +
                  '</div>' +
                  '<div class="net-item net-restored">' +
                    '<i class="bi bi-wifi"></i>' +
                    '<span><strong>Koneksi pulih.</strong> Semua jawaban sudah tersimpan.</span>' +
                  '</div>' +
                '</div>';
    }

    html += '<small class="text-muted">Waktu pengerjaan akan langsung berjalan setelah Anda menekan tombol Ya, Lanjutkan.</small></div>';

    Swal.fire({
        title: 'Konfirmasi Mulai Ujian',
        html: html,
        icon: aturanTab ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: '#0f172a',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Lanjutkan',
        cancelButtonText: 'Batal',
        reverseButtons: true,
        customClass: {
            popup: 'rounded-4',
            confirmButton: 'btn btn-brand-primary px-4 py-2 ms-2',
            cancelButton: 'btn btn-light px-4 py-2'
        },
        buttonsStyling: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
}
</script>
<script>
function konfirmasiTerkunci(alasanKunci, judulTes) {
    Swal.fire({
        title: 'Modul Masih Terkunci',
        html: 'Selesaikan tes <strong>' + alasanKunci + '</strong> terlebih dahulu sebelum bisa mengerjakan <strong>' + judulTes + '</strong>.',
        icon: 'warning',
        confirmButtonColor: '#0f172a',
        confirmButtonText: 'Mengerti',
        customClass: {
            popup: 'rounded-4',
            confirmButton: 'btn btn-brand-primary px-4 py-2'
        },
        buttonsStyling: false
    });
}
</script>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>