<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$stats = getDashboardStats();

$aktivitas = getDB()->query("
    SELECT a.id, a.status, a.waktu_mulai, a.waktu_selesai, a.total_skor, a.total_benar, a.flag_curang, a.pelanggaran_count,
           u.nama AS nama_peserta, t.judul AS judul_tes, t.jenis
    FROM attempt a
    JOIN users u ON u.id = a.user_id
    JOIN tes t ON t.id = a.tes_id
    ORDER BY a.waktu_mulai DESC
    LIMIT 8
")->fetchAll();

$pageTitle = 'Dashboard Admin';
$pageDesc = 'Ringkasan aktivitas dan statistik pengerjaan psikotes';
$activeMenu = 'dashboard';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    /* Keyframe Animations */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes spinAround {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    @keyframes pulseSoft {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }

    /* Staggered Entrance Animation for Stat Cards */
    .stat-card-anim {
        animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    .stat-card-anim:nth-child(1) { animation-delay: 0.1s; }
    .stat-card-anim:nth-child(2) { animation-delay: 0.2s; }
    .stat-card-anim:nth-child(3) { animation-delay: 0.3s; }
    .stat-card-anim:nth-child(4) { animation-delay: 0.4s; }

    /* Modern Dashboard Card Styling */
    .stat-card-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    
    .stat-card-modern:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 20px -5px rgba(15, 23, 42, 0.08);
        border-color: #cbd5e1;
    }

    .stat-icon-wrapper {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
        transition: transform 0.3s ease;
    }

    .stat-card-modern:hover .stat-icon-wrapper {
        transform: scale(1.1) rotate(4deg);
    }

    .bg-icon-indigo { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .bg-icon-cyan { background: #ecfeff; color: #0891b2; border: 1px solid #a5f3fc; }
    .bg-icon-amber { background: #fffbeb; color: #d97706; border: 1px solid #fef3c7; }
    .bg-icon-green { background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }

    /* Table Container Animation */
    .table-container-modern {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.5s both;
    }

    /* Table Row Animations */
    .table-hover tbody tr {
        transition: background-color 0.2s ease, transform 0.2s ease;
    }
    .table-hover tbody tr:hover {
        background-color: #f8fafc;
    }

    /* Status Badges */
    .status-badge-modern {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .status-badge-modern.ongoing {
        background-color: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }
    .status-badge-modern.done {
        background-color: #f0fdf4;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }

    /* Continuous Spinning Icon for 'Berlangsung' */
    .spin {
        animation: spinAround 2s linear infinite;
        display: inline-block;
    }

    /* Badge Jenis Tes */
    .badge-jenis {
        font-size: 0.7rem;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .badge-jenis.logika { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
    .badge-jenis.psikotes { background: #faf5ff; color: #9333ea; border: 1px solid #f3e8ff; }

    /* Smooth transition button */
    .btn-hover-animate {
        transition: all 0.2s ease;
    }
    .btn-hover-animate:hover {
        transform: translateX(3px);
    }
</style>

<!-- Stat Cards dengan Animasi Slide-Up & Hover -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-lg-3 stat-card-anim">
        <div class="stat-card-modern">
            <div class="stat-icon-wrapper bg-icon-indigo">
                <i class="bi bi-people-fill"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 font-monospace counter-up" data-target="<?= $stats['total_peserta'] ?>">0</h3>
                <span class="text-muted small">Total Peserta</span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3 stat-card-anim">
        <div class="stat-card-modern">
            <div class="stat-icon-wrapper bg-icon-cyan">
                <i class="bi bi-journal-text"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 font-monospace counter-up" data-target="<?= $stats['total_tes'] ?>">0</h3>
                <span class="text-muted small">Jenis Tes Aktif</span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3 stat-card-anim">
        <div class="stat-card-modern">
            <div class="stat-icon-wrapper bg-icon-amber">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 font-monospace counter-up" data-target="<?= $stats['sedang_berlangsung'] ?>">0</h3>
                <span class="text-muted small">Sedang Mengerjakan</span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3 stat-card-anim">
        <div class="stat-card-modern">
            <div class="stat-icon-wrapper bg-icon-green">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 font-monospace counter-up" data-target="<?= $stats['selesai'] ?>">0</h3>
                <span class="text-muted small">Tes Selesai</span>
            </div>
        </div>
    </div>
</div>

<!-- Tabel Aktivitas Terbaru -->
<div class="table-container-modern">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h6 class="fw-bold text-dark mb-1">Aktivitas Terbaru</h6>
            <p class="text-muted small mb-0">Daftar pengerjaan tes terkini oleh peserta</p>
        </div>
        <a href="peserta.php" class="btn btn-sm btn-outline-primary rounded-2 font-semibold btn-hover-animate">
            Kelola Semua Peserta <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>

    <?php if (empty($aktivitas)): ?>
        <div class="text-center py-5 text-muted small">
            <i class="bi bi-inbox d-block fs-2 mb-2 opacity-50"></i>
            Belum ada aktivitas pengerjaan tes yang tercatat.
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 0.875rem;">
            <thead class="table-light">
                <tr>
                    <th class="fw-semibold text-secondary py-3">Peserta</th>
                    <th class="fw-semibold text-secondary py-3">Modul Tes</th>
                    <th class="fw-semibold text-secondary py-3">Status</th>
                    <th class="fw-semibold text-secondary py-3">Waktu Mulai</th>
                    <th class="fw-semibold text-secondary py-3 text-end">Skor Akhir</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aktivitas as $a): ?>
                <tr>
                    <td class="py-3">
                        <span class="fw-bold text-dark d-block"><?= e($a['nama_peserta']) ?></span>
                        <?php if (!empty($a['flag_curang'])): ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle mt-1" style="font-size: 0.7rem;">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i><?= (int)$a['pelanggaran_count'] ?> Pelanggaran
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3">
                        <span class="text-dark fw-medium d-block mb-1"><?= e($a['judul_tes']) ?></span>
                        <span class="badge-jenis <?= $a['jenis'] === 'logika' ? 'logika' : 'psikotes' ?>">
                            <?= e($a['jenis']) ?>
                        </span>
                    </td>
                    <td class="py-3">
                        <?php if ($a['status'] === 'berlangsung'): ?>
                            <span class="status-badge-modern ongoing">
                                <i class="bi bi-arrow-repeat spin"></i> Berlangsung
                            </span>
                        <?php else: ?>
                            <span class="status-badge-modern done">
                                <i class="bi bi-check-lg"></i> Selesai
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 text-muted">
                        <?= formatTanggal($a['waktu_mulai']) ?>
                    </td>
                    <td class="py-3 text-end font-monospace fw-bold fs-6 text-dark">
                        <?= $a['status'] === 'selesai' ? e($a['total_skor']) : '<span class="text-muted fw-normal fs-7">-</span>' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Script Animasi Counter Angka -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const counters = document.querySelectorAll('.counter-up');
    const speed = 200;

    counters.forEach(counter => {
        const animate = () => {
            const target = +counter.getAttribute('data-target');
            const count = +counter.innerText;
            const inc = target / speed || 1;

            if (count < target) {
                counter.innerText = Math.ceil(count + inc);
                setTimeout(animate, 15);
            } else {
                counter.innerText = target.toLocaleString();
            }
        };
        animate();
    });
});
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>