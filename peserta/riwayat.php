<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');

$db = getDB();
$userId = $_SESSION['user_id'];

// Fetch semua riwayat attempt peserta
$stmt = $db->prepare("
    SELECT a.*, t.judul AS judul_tes, t.jenis
    FROM attempt a
    JOIN tes t ON t.id = a.tes_id
    WHERE a.user_id = ?
    ORDER BY a.waktu_mulai DESC
");
$stmt->execute([$userId]);
$riwayat = $stmt->fetchAll();

// Hitung statistik ringkas untuk header
$totalTes = count($riwayat);
$totalSelesai = count(array_filter($riwayat, fn($item) => $item['status'] === 'selesai'));
$totalBerlangsung = count(array_filter($riwayat, fn($item) => $item['status'] === 'berlangsung'));

$pageTitle = 'Riwayat Ujian & Hasil';
$pageDesc = 'Pantau seluruh aktivitas pengerjaan tes dan statistik nilai Anda';
$activeMenu = 'riwayat';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    /* Styling Khusus Riwayat Ujian */
    .stat-card-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: all 0.2s ease;
    }
    .stat-card-modern:hover {
        border-color: #cbd5e1;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
    }
    .stat-icon-box {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .table-container-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02);
    }

    .table-modern {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .table-modern th {
        background-color: #f8fafc;
        color: #64748b;
        font-size: 0.775rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 14px 18px;
        border-bottom: 1px solid #e2e8f0;
    }
    .table-modern th:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
    .table-modern th:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }

    .table-modern td {
        padding: 16px 18px;
        font-size: 0.875rem;
        color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .table-modern tr:last-child td { border-bottom: none; }
    .table-modern tr:hover td { background-color: #fafafa; }

    .badge-modern {
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .badge-logika { background: #eff6ff; color: #2563eb; }
    .badge-psikotes { background: #fdf4ff; color: #c026d3; }
    .badge-eksternal { background: #fff7ed; color: #c2410c; }
    .badge-done { background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
    .badge-ongoing { background: #fffbeb; color: #d97706; border: 1px solid #fef3c7; }

    .search-input-group {
        position: relative;
        max-width: 300px;
    }
    .search-input-group input {
        padding-left: 38px;
        border-radius: 10px;
        font-size: 0.875rem;
        border: 1px solid #e2e8f0;
    }
    .search-input-group i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
    }

    .btn-action-sm {
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .btn-action-primary {
        background-color: #0f172a;
        color: #ffffff;
    }
    .btn-action-primary:hover {
        background-color: #2563eb;
        color: #ffffff;
    }
    .btn-action-outline {
        border: 1px solid #cbd5e1;
        color: #475569;
        background-color: #ffffff;
    }
    .btn-action-outline:hover {
        background-color: #f8fafc;
        color: #0f172a;
        border-color: #94a3b8;
    }

    /* ===================== ANIMASI ===================== */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes rowIn {
        from { opacity: 0; transform: translateX(-12px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    @keyframes hourglassFlip {
        0%, 70% { transform: rotate(0deg); }
        100%    { transform: rotate(180deg); }
    }

    /* Kartu statistik muncul bergantian
       (fill-mode "backwards" supaya efek hover tetap jalan setelah animasi selesai) */
    .stat-card-modern { animation: fadeUp 0.5s cubic-bezier(.22,.9,.36,1) backwards; }
    .row > div:nth-child(1) .stat-card-modern { animation-delay: 0.05s; }
    .row > div:nth-child(2) .stat-card-modern { animation-delay: 0.15s; }
    .row > div:nth-child(3) .stat-card-modern { animation-delay: 0.25s; }
    .stat-card-modern:hover { transform: translateY(-2px); }
    .stat-icon-box { transition: transform 0.25s ease; }
    .stat-card-modern:hover .stat-icon-box { transform: scale(1.12) rotate(-6deg); }

    /* Kartu tabel */
    .table-container-modern { animation: fadeUp 0.6s cubic-bezier(.22,.9,.36,1) 0.3s backwards; }

    /* Baris tabel masuk satu per satu */
    #riwayatTable tbody tr[data-row] {
        animation: rowIn 0.4s ease backwards;
        animation-delay: calc(0.5s + min(var(--i, 0), 12) * 45ms);
    }
    #riwayatTable tbody tr.row-pop { animation: rowIn 0.3s ease backwards; animation-delay: 0s; }
    .table-modern tbody tr td:first-child { transition: box-shadow 0.2s ease; }
    .table-modern tbody tr:hover td:first-child { box-shadow: inset 3px 0 0 #2563eb; }
    .table-modern td { transition: background-color 0.15s ease; }

    /* Badge & ikon status */
    .badge-modern { transition: transform 0.2s ease; }
    tr:hover .badge-modern { transform: scale(1.05); }
    .badge-ongoing i.bi-clock-history { display: inline-block; animation: hourglassFlip 2.4s ease-in-out infinite; }

    /* Tombol aksi */
    .btn-action-sm { transition: transform 0.15s ease, box-shadow 0.2s ease, background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease; }
    .btn-action-sm:hover { transform: translateY(-2px); box-shadow: 0 6px 14px -6px rgba(15, 23, 42, 0.3); }
    .btn-action-sm:active { transform: scale(0.96); }
    .btn-action-sm i { display: inline-block; transition: transform 0.2s ease; }
    .btn-action-sm:hover i.bi-arrow-right { transform: translateX(4px); }
    .btn-action-sm:hover i.bi-bar-chart-line { transform: scale(1.2); }

    /* Search melebar saat fokus */
    .search-input-group { transition: max-width 0.3s ease; }
    .search-input-group:focus-within { max-width: 360px; }
    .search-input-group input { transition: border-color 0.2s ease, box-shadow 0.25s ease; }
    .search-input-group input:focus { border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); }
    .search-input-group:focus-within i { color: #6366f1; }
    .search-input-group i { transition: color 0.2s ease; }

    /* Hormati pengguna yang mematikan animasi di sistemnya */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
    }
</style>

<!-- Top Statistics Section -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card-modern">
            <div class="stat-icon-box bg-primary bg-opacity-10 text-primary">
                <i class="bi bi-journal-bookmark-fill"></i>
            </div>
            <div>
                <span class="d-block text-muted small fw-semibold">Total Sesi Ujian</span>
                <h4 class="fw-bold mb-0 text-dark"><span class="count-up" data-count="<?= $totalTes ?>"><?= $totalTes ?></span></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card-modern">
            <div class="stat-icon-box bg-success bg-opacity-10 text-success">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div>
                <span class="d-block text-muted small fw-semibold">Ujian Selesai</span>
                <h4 class="fw-bold mb-0 text-dark"><span class="count-up" data-count="<?= $totalSelesai ?>"><?= $totalSelesai ?></span></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card-modern">
            <div class="stat-icon-box bg-warning bg-opacity-10 text-warning">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div>
                <span class="d-block text-muted small fw-semibold">Sedang Berlangsung</span>
                <h4 class="fw-bold mb-0 text-dark"><span class="count-up" data-count="<?= $totalBerlangsung ?>"><?= $totalBerlangsung ?></span></h4>
            </div>
        </div>
    </div>
</div>

<!-- Main Table Container -->
<div class="table-container-modern">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h6 class="fw-bold text-dark mb-1">Daftar Riwayat Tes</h6>
            <p class="text-muted small mb-0">Semua rekaman hasil pengerjaan modul psikotes dan logika</p>
        </div>

        <!-- Search Input Interaktif -->
        <div class="search-input-group">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" class="form-control" placeholder="Cari nama tes...">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-modern" id="riwayatTable">
            <thead>
                <tr>
                    <th>Modul Tes</th>
                    <th>Status</th>
                    <th>Waktu Mulai</th>
                    <th>Waktu Selesai</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($riwayat)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5">
                            <i class="bi bi-inbox fs-2 text-muted d-block mb-2"></i>
                            <span class="text-muted small">Belum ada riwayat pengerjaan tes.</span>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($riwayat as $ri => $r):
                    $jenisBadge = $r['jenis'] === 'logika' ? 'badge-logika' : ($r['jenis'] === 'eksternal' ? 'badge-eksternal' : 'badge-psikotes');
                    $urlLanjut = ($r['jenis'] === 'eksternal' ? 'tes_eksternal.php' : 'tes.php') . '?id=' . (int)$r['tes_id'];
                ?>
                <tr data-row style="--i: <?= (int)$ri ?>">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark me-1"><?= e($r['judul_tes']) ?></span>
                            <span class="badge-modern <?= $jenisBadge ?>">
                                <?= ucfirst($r['jenis']) ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <?= $r['status'] === 'selesai' 
                            ? '<span class="badge-modern badge-done"><i class="bi bi-check2"></i> Selesai</span>' 
                            : '<span class="badge-modern badge-ongoing"><i class="bi bi-clock-history"></i> Berlangsung</span>' 
                        ?>
                    </td>
                    <td class="text-muted small">
                        <i class="bi bi-calendar-event me-1"></i> <?= formatTanggal($r['waktu_mulai']) ?>
                    </td>
                    <td class="text-muted small">
                        <?= $r['waktu_selesai'] ? '<i class="bi bi-clock-check me-1"></i> ' . formatTanggal($r['waktu_selesai']) : '<span class="text-black-50">-</span>' ?>
                    </td>
                    <td class="text-end">
                        <?php if ($r['status'] === 'selesai'): ?>
                            <a href="hasil_detail.php?id=<?= $r['id'] ?>" class="btn-action-sm btn-action-outline d-inline-flex align-items-center gap-1">
                                <i class="bi bi-bar-chart-line"></i> Detail Hasil
                            </a>
                        <?php else: ?>
                            <a href="<?= e($urlLanjut) ?>" class="btn-action-sm btn-action-primary d-inline-flex align-items-center gap-1">
                                Lanjutkan <i class="bi bi-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Fitur pencarian instan pada tabel (baris yang muncul kembali ikut beranimasi)
    document.getElementById('searchInput').addEventListener('input', function() {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('#riwayatTable tbody tr[data-row]').forEach(function(row) {
            const cocok = row.textContent.toLowerCase().includes(filter);
            const sebelumnyaHilang = row.style.display === 'none';
            row.style.display = cocok ? '' : 'none';
            if (cocok && sebelumnyaHilang) {
                row.classList.remove('row-pop');
                void row.offsetWidth; // restart animasi
                row.classList.add('row-pop');
            }
        });
    });

    // Angka statistik menghitung naik dari 0
    document.querySelectorAll('.count-up').forEach(function (el) {
        const target = parseInt(el.dataset.count, 10) || 0;
        if (target === 0) return;
        const durasi = 800, mulai = performance.now();
        (function tick(now) {
            const p = Math.min((now - mulai) / durasi, 1);
            el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))); // ease-out
            if (p < 1) requestAnimationFrame(tick);
        })(mulai);
    });
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>