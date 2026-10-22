<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

/* ---------- Filter ---------- */
$dari   = trim($_GET['dari'] ?? '');
$sampai = trim($_GET['sampai'] ?? '');
$q      = trim($_GET['q'] ?? '');
$role   = $_GET['role'] ?? 'peserta';   // peserta | admin | semua

$where  = [];
$params = [];

if ($dari !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
    $where[] = 'r.waktu_login >= ?';
    $params[] = $dari . ' 00:00:00';
}
if ($sampai !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
    $where[] = 'r.waktu_login <= ?';
    $params[] = $sampai . ' 23:59:59';
}
if ($q !== '') {
    $where[] = '(u.nama LIKE ? OR u.email LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if (in_array($role, ['peserta', 'admin'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $role;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Kondisi "masih online"
$idle = (int)IDLE_LIMIT;
$kondisiOnline = "(r.waktu_logout IS NULL AND r.waktu_terakhir_aktif >= NOW() - INTERVAL {$idle} SECOND)";

/* ---------- Aksi hapus ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db   = getDB();
    $aksi = $_POST['aksi'] ?? '';
    $jumlah = 0;

    try {
        if ($aksi === 'hapus_satu' || $aksi === 'hapus_terpilih') {
            $ids = $aksi === 'hapus_satu'
                ? [(int)($_POST['id'] ?? 0)]
                : array_map('intval', (array)($_POST['ids'] ?? []));
            $ids = array_values(array_filter($ids, fn($v) => $v > 0));

            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("DELETE r FROM riwayat_akses r WHERE r.id IN ($ph) AND NOT $kondisiOnline");
                $stmt->execute($ids);
                $jumlah = $stmt->rowCount();
            }
        } elseif ($aksi === 'hapus_filter') {
            $stmt = $db->prepare(
                "DELETE r FROM riwayat_akses r JOIN users u ON u.id = r.user_id"
                . ($whereSql ?: ' WHERE 1=1') . " AND NOT $kondisiOnline"
            );
            $stmt->execute($params);
            $jumlah = $stmt->rowCount();
        }

        flash('riwayat_ok', $jumlah > 0
            ? "$jumlah riwayat berhasil dihapus."
            : 'Tidak ada riwayat yang dihapus (sesi yang sedang online tidak bisa dihapus).');
    } catch (Throwable $e) {
        error_log('Gagal hapus riwayat akses: ' . $e->getMessage());
        flash('riwayat_err', 'Gagal menghapus riwayat akses.');
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: riwayat_akses.php' . ($qs ? '?' . $qs : ''));
    exit;
}

/* ---------- Data ---------- */
$sql = "SELECT r.*, u.nama, u.email, u.role,
               TIMESTAMPDIFF(MINUTE, r.waktu_login,
                   COALESCE(r.waktu_logout, r.waktu_terakhir_aktif)) AS durasi_menit,
               $kondisiOnline AS masih_online
        FROM riwayat_akses r
        JOIN users u ON u.id = r.user_id"
     . $whereSql
     . " ORDER BY r.waktu_login DESC LIMIT 500";

$stmt = getDB()->prepare($sql);
$stmt->execute($params);
$riwayat = $stmt->fetchAll();

$pesanOk  = flash('riwayat_ok');
$pesanErr = flash('riwayat_err');

$pageTitle  = 'Riwayat Akses';
$pageDesc   = 'Catatan waktu masuk dan keluar setiap akun';
$activeMenu = 'riwayat_akses';
include __DIR__ . '/../includes/layout_top.php';
?>

<!-- Custom CSS Modern & Animasi -->
<style>
    .animate-fade-in {
        animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .table-modern tbody tr {
        transition: all 0.2s ease;
    }
    .table-modern tbody tr:hover {
        background-color: rgba(243, 244, 246, 0.7) !important;
        transform: scale(1.002);
    }

    .avatar-circle {
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: #fff;
        font-weight: 600;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(79, 70, 229, 0.15);
    }

    .badge-pulse {
        position: relative;
        display: inline-flex;
        align-items: center;
    }
    .badge-pulse::before {
        content: '';
        position: absolute;
        width: 8px;
        height: 8px;
        left: 8px;
        background-color: #22c55e;
        border-radius: 50%;
        animation: pulseRing 1.8s infinite ease-in-out;
    }
    @keyframes pulseRing {
        0% { transform: scale(0.95); opacity: 0.8; }
        50% { transform: scale(1.6); opacity: 0; }
        100% { transform: scale(0.95); opacity: 0; }
    }

    .btn-action-delete {
        transition: all 0.2s ease;
    }
    .btn-action-delete:hover {
        background-color: #fee2e2 !important;
        color: #dc2626 !important;
        border-color: #fca5a5 !important;
        transform: translateY(-1px);
    }

    .glass-card {
        background: #ffffff;
        border-radius: 20px;
        border: 1px solid rgba(229, 231, 235, 0.8);
        box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.04);
    }

    .form-control:focus, .form-select:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
    }
</style>

<div class="animate-fade-in">
    <!-- Alert Feedback -->
    <?php if ($pesanOk): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center" role="alert">
            <i class="bi bi-check-circle-fill fs-5 me-3 text-success"></i>
            <div><?= e($pesanOk) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($pesanErr): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5 me-3 text-danger"></i>
            <div><?= e($pesanErr) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="glass-card p-4 mb-4">
        <!-- Panel Filter (GET) -->
        <form method="GET" class="row g-3 align-items-end mb-4 pb-3 border-bottom">
            <div class="col-12 col-md-3">
                <label class="form-label fs-7 fw-bold text-uppercase text-muted tracking-wider mb-1">Cari Pengguna</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0 rounded-start-3 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= e($q) ?>" class="form-control form-control-sm border-start-0 rounded-end-3" placeholder="Nama atau email...">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-7 fw-bold text-uppercase text-muted tracking-wider mb-1">Dari Tanggal</label>
                <input type="date" name="dari" value="<?= e($dari) ?>" class="form-control form-control-sm rounded-3">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-7 fw-bold text-uppercase text-muted tracking-wider mb-1">Sampai Tanggal</label>
                <input type="date" name="sampai" value="<?= e($sampai) ?>" class="form-control form-control-sm rounded-3">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-7 fw-bold text-uppercase text-muted tracking-wider mb-1">Jenis Akun</label>
                <select name="role" class="form-select form-select-sm rounded-3">
                    <option value="peserta" <?= $role === 'peserta' ? 'selected' : '' ?>>Peserta</option>
                    <option value="admin"   <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="semua"   <?= $role === 'semua' ? 'selected' : '' ?>>Semua Role</option>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary rounded-3 px-3 w-100 fw-semibold d-inline-flex align-items-center justify-content-center shadow-sm">
                    <i class="bi bi-funnel-fill me-1"></i> Filter
                </button>
                <a href="riwayat_akses.php" class="btn btn-sm btn-light border rounded-3 px-3 text-secondary d-inline-flex align-items-center">
                    Reset
                </a>
            </div>
        </form>

        <!-- Form Aksi Hapus & Tabel (POST) -->
        <form method="POST" id="formRiwayat">
            <input type="hidden" name="aksi" id="aksiInput" value="">
            <input type="hidden" name="id" id="idInput" value="">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-3 fw-semibold px-3" id="btnHapusTerpilih" disabled>
                        <i class="bi bi-trash me-1"></i> Hapus Terpilih (<span id="jumlahTerpilih">0</span>)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-3 fw-semibold px-3" id="btnHapusFilter" <?= $riwayat ? '' : 'disabled' ?>>
                        <i class="bi bi-trash3 me-1"></i> Hapus Semua Hasil Filter
                    </button>
                </div>
                <span class="text-muted small">Total: <strong><?= count($riwayat) ?></strong> entri ditampilkan</span>
            </div>

            <div class="table-responsive rounded-3 border">
                <table class="table table-hover table-modern align-middle mb-0" style="font-size: 0.875rem;">
                    <thead class="bg-light text-secondary text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.6px;">
                        <tr>
                            <th class="ps-3 py-3" style="width: 40px;">
                                <input type="checkbox" class="form-check-input" id="cekSemua" aria-label="Pilih semua">
                            </th>
                            <th class="py-3">Pengguna</th>
                            <th class="py-3">Waktu Masuk</th>
                            <th class="py-3">Waktu Keluar</th>
                            <th class="py-3">Durasi</th>
                            <th class="py-3">Alasan Keluar</th>
                            <th class="text-end pe-4 py-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$riwayat): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-1 d-block opacity-25 mb-2"></i>
                                Belum ada data riwayat akses yang ditemukan.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($riwayat as $r): $online = (int)$r['masih_online'] === 1; ?>
                        <tr>
                            <td class="ps-3">
                                <?php if (!$online): ?>
                                    <input type="checkbox" class="form-check-input cek-baris" name="ids[]" value="<?= (int)$r['id'] ?>">
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar-circle">
                                        <?= strtoupper(substr($r['nama'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark mb-0"><?= e($r['nama']) ?></div>
                                        <div class="text-muted small" style="font-size: 0.775rem;"><?= e($r['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="fw-medium text-dark">
                                <i class="bi bi-box-arrow-in-right me-1 text-success"></i>
                                <?= e(formatTanggal($r['waktu_login'])) ?>
                            </td>
                            <td>
                                <?php if ($online): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 badge-pulse">
                                        <i class="bi bi-circle-fill me-2" style="font-size: 0.4rem; opacity:0;"></i> Sedang Online
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary">
                                        <i class="bi bi-box-arrow-right me-1 text-muted"></i>
                                        <?= e(formatTanggal($r['waktu_logout'] ?? $r['waktu_terakhir_aktif'])) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace">
                                    <?= e(formatDurasi($r['durasi_menit'] !== null ? (int)$r['durasi_menit'] : null)) ?>
                                </span>
                            </td>
                            <td class="small text-muted">
                                <?= $online ? '<span class="text-black-50">-</span>' : e(labelAlasanLogout($r['alasan_logout'])) ?>
                            </td>
                            <td class="text-end pe-4">
                                <?php if (!$online): ?>
                                    <button type="button" class="btn btn-sm btn-light border rounded-3 btn-action-delete text-secondary btn-hapus-satu"
                                            data-id="<?= (int)$r['id'] ?>" title="Hapus riwayat ini">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <div class="d-flex align-items-center gap-2 text-muted small mt-3">
            <i class="bi bi-info-circle"></i>
            <span>Menampilkan maksimal 500 riwayat terbaru. Sesi aktif (online) dilindungi dari penghapusan.</span>
        </div>
    </div>
</div>

<script>
(function () {
    const form        = document.getElementById('formRiwayat');
    const aksiInput   = document.getElementById('aksiInput');
    const idInput     = document.getElementById('idInput');
    const cekSemua    = document.getElementById('cekSemua');
    const btnTerpilih = document.getElementById('btnHapusTerpilih');
    const btnFilter   = document.getElementById('btnHapusFilter');
    const labelJumlah = document.getElementById('jumlahTerpilih');
    const cekBaris    = () => Array.from(document.querySelectorAll('.cek-baris'));

    function kirim(aksi, id) {
        aksiInput.value = aksi;
        idInput.value = id || '';
        form.submit();
    }

    function konfirmasi(judul, teks, aksi, id) {
        Swal.fire({
            title: judul,
            text: teks,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            reverseButtons: true,
            customClass: {
                popup: 'rounded-4',
                confirmButton: 'rounded-3 shadow-sm px-4',
                cancelButton: 'rounded-3 px-4'
            }
        }).then(function (res) { if (res.isConfirmed) kirim(aksi, id); });
    }

    function updateTerpilih() {
        const n = cekBaris().filter(c => c.checked).length;
        labelJumlah.textContent = n;
        btnTerpilih.disabled = n === 0;
        const semua = cekBaris();
        cekSemua.checked = semua.length > 0 && n === semua.length;
    }

    cekSemua.addEventListener('change', function () {
        cekBaris().forEach(c => { c.checked = cekSemua.checked; });
        updateTerpilih();
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('cek-baris')) {
            updateTerpilih();
        }
    });

    document.querySelectorAll('.btn-hapus-satu').forEach(function (b) {
        b.addEventListener('click', function () {
            konfirmasi('Hapus riwayat ini?', 'Data yang dihapus tidak bisa dikembalikan.', 'hapus_satu', this.dataset.id);
        });
    });

    btnTerpilih.addEventListener('click', function () {
        const n = cekBaris().filter(c => c.checked).length;
        konfirmasi('Hapus ' + n + ' riwayat terpilih?', 'Data yang dihapus tidak bisa dikembalikan.', 'hapus_terpilih');
    });

    btnFilter.addEventListener('click', function () {
        konfirmasi('Hapus semua hasil filter?', 'Semua riwayat yang cocok dengan filter saat ini akan dihapus permanen.', 'hapus_filter');
    });

    updateTerpilih();
})();
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>