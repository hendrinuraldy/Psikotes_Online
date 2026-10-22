<?php
/**
 * Dipanggil dari admin/*.php atau peserta/*.php setelah requireRole().
 * Variabel yang diharapkan sudah di-set oleh halaman pemanggil:
 *   $pageTitle, $pageDesc (opsional), $activeMenu
 */

$user = currentUser();
$role = $_SESSION['role'];
$prefix = $role === 'admin' ? '../admin/' : '../peserta/';
$rootPrefix = '../'; // untuk assets & logout dari dalam folder admin/peserta
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<?php require_once __DIR__ . '/../includes/tab_guard.php'; ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Dashboard') ?> - Psikotes Online</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>/psikotes.ico?v=2">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= $rootPrefix ?>assets/css/style.css">
<style>
/* ==== Sidebar mobile toggle (tambahan) ==== */
.sidebar-toggle-btn {
    display: none;
    border: none;
    background: transparent;
    font-size: 22px;
    line-height: 1;
    color: var(--navy, #0f172a);
    padding: 4px 6px;
    border-radius: 8px;
    cursor: pointer;
}
.sidebar-toggle-btn:hover { background: #f1f5f9; }

.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    z-index: 1040;
}
.sidebar-overlay.show { display: block; }

@media (max-width: 991px) {
    .sidebar-toggle-btn { display: inline-flex; align-items: center; justify-content: center; }

    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        z-index: 1050;
        transform: translateX(-100%);
        transition: transform .25s ease;
    }
    .sidebar.show {
        transform: translateX(0);
    }
}
</style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
    <img src="../assets/Logo SSKtama Fiks Revisi (1).png" alt="Logo Psikotes" style="height: 52px; width: auto; vertical-align: middle; margin-right: 8px; background: #ffffff; padding: 4px 8px; border-radius: 6px;">
    Psikotes<span style="color:#a6a0f7;"></span>
</div>

        <?php if ($role === 'admin'): ?>
            <div class="nav-section-title">Menu Utama</div>
            <a href="<?= $rootPrefix ?>admin/dashboard.php" class="nav-link <?= ($activeMenu ?? '') === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="<?= $rootPrefix ?>admin/peserta.php" class="nav-link <?= ($activeMenu ?? '') === 'peserta' ? 'active' : '' ?>">
                <i class="bi bi-people-fill"></i> Peserta &amp; Aktivitas
            </a>
            <a href="<?= $rootPrefix ?>admin/riwayat_akses.php" class="nav-link <?= ($activeMenu ?? '') === 'riwayat_akses' ? 'active' : '' ?>">
                <i class="bi bi-door-open-fill"></i> Riwayat Akses
            </a>
            <a href="<?= $rootPrefix ?>admin/kelola_admin.php" class="nav-link <?= ($activeMenu ?? '') === 'kelola_admin' ? 'active' : '' ?>">
                <i class="bi bi-shield-lock-fill"></i> Kelola Admin
            </a>
            <a href="<?= $rootPrefix ?>admin/tes.php" class="nav-link <?= ($activeMenu ?? '') === 'tes' ? 'active' : '' ?>">
                <i class="bi bi-journal-text"></i> Kelola Tes
            </a>
            <a href="<?= $rootPrefix ?>admin/hasil.php" class="nav-link <?= ($activeMenu ?? '') === 'hasil' ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-fill"></i> Hasil &amp; Nilai
            </a>
            <a href="<?= $rootPrefix ?>admin/lamaran.php" class="nav-link <?= ($activeMenu ?? '') === 'lamaran' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-person-fill"></i> CV &amp; Lamaran
            </a>
            <div class="nav-section-title">Akun</div>
            <a href="<?= $rootPrefix ?>admin/profil.php" class="nav-link <?= ($activeMenu ?? '') === 'profil' ? 'active' : '' ?>">
                <i class="bi bi-person-circle"></i> Profil Saya
            </a>
            <a href="#" onclick="confirmLogout(event, '<?= $rootPrefix ?>logout.php')" class="nav-link">
                <i class="bi bi-box-arrow-left"></i> Keluar
            </a>
            
        <?php else: ?>
            <div class="nav-section-title">Menu Utama</div>
            <a href="<?= $rootPrefix ?>peserta/dashboard.php" class="nav-link <?= ($activeMenu ?? '') === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="<?= $rootPrefix ?>peserta/riwayat.php" class="nav-link <?= ($activeMenu ?? '') === 'riwayat' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i> Riwayat &amp; Nilai
            </a>
            <div class="nav-section-title">Akun</div>
            <a href="<?= $rootPrefix ?>peserta/profil.php" class="nav-link <?= ($activeMenu ?? '') === 'profil' ? 'active' : '' ?>">
    <i class="bi bi-person-circle"></i> Profil Saya
</a>
            <a href="#" onclick="confirmLogout(event, '<?= $rootPrefix ?>logout.php')" class="nav-link">
    <i class="bi bi-box-arrow-left"></i> Keluar
</a>
        <?php endif; ?>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
        <div class="topbar">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Buka menu">
                    <i class="bi bi-list"></i>
                </button>
                <div>
                    <h5><?= e($pageTitle ?? '') ?></h5>
                    <?php if (!empty($pageDesc)): ?><div class="page-desc"><?= e($pageDesc) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="user-chip">
                <div class="avatar"><?= e(strtoupper(substr($user['nama'], 0, 1))) ?></div>
                <div style="line-height:1.2;">
                    <div style="font-weight:600; font-size:13.5px;"><?= e($user['nama']) ?></div>
                    <div style="font-size:11.5px; color:var(--muted); text-transform:capitalize;"><?= e($role) ?></div>
                </div>
            </div>
        </div>
        <div class="content-wrap">

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <script>
        function confirmLogout(e, url) {
            e.preventDefault();
            Swal.fire({
                title: 'Keluar dari akun?',
                text: 'Anda perlu login kembali untuk mengakses sistem.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Keluar',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
        }

        // ==== Toggle sidebar mobile ====
        (function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggleBtn = document.getElementById('sidebarToggleBtn');

            function openSidebar() {
                sidebar.classList.add('show');
                overlay.classList.add('show');
            }
            function closeSidebar() {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            }

            toggleBtn.addEventListener('click', function () {
                sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
            });
            overlay.addEventListener('click', closeSidebar);

            // Tutup otomatis kalau salah satu link menu diklik (mobile),
            // KECUALI tombol logout (biar tidak nutup sidebar sebelum dialog muncul)
            sidebar.querySelectorAll('.nav-link:not([onclick])').forEach(function (link) {
                link.addEventListener('click', closeSidebar);
            });
        })();
        </script>