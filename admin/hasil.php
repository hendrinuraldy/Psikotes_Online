<?php
// Simpan file ini sebagai: admin/hasil.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attempt_auto.php';
requireRole('admin');

$db = getDB();

// Tutup otomatis attempt yang waktunya sudah habis
finalisasiAttemptKadaluarsa($db);

/* Status seleksi peserta: ditentukan manual dari menu Peserta (peserta.php).
   Halaman ini hanya MENAMPILKAN status tersebut. */
const STATUS_SELEKSI = [
    'diterima'    => 'Diterima',
    'disimpan'    => 'Disimpan',
    'tidak_lolos' => 'Tidak Lolos',
];

$pesertaId = (int)($_GET['peserta_id'] ?? 0);
$tesId = (int)($_GET['tes_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$statusSeleksi = (string)($_GET['status_seleksi'] ?? '');
if ($statusSeleksi !== 'belum' && !isset(STATUS_SELEKSI[$statusSeleksi])) {
    $statusSeleksi = '';
}

$jabatan = trim((string)($_GET['jabatan'] ?? ''));

// Mode: 'list' = daftar peserta, 'detail' = daftar tes milik satu peserta
$mode = $pesertaId > 0 ? 'detail' : 'list';

$where = [];
$params = [];

if ($pesertaId > 0) {
    $where[] = 'a.user_id = ?';
    $params[] = $pesertaId;
}

if ($mode === 'detail' && $tesId > 0) {
    $where[] = 'a.tes_id = ?';
    $params[] = $tesId;
}

if ($mode === 'list' && $jabatan !== '') {
    if ($jabatan === '__kosong') {
        $where[] = "(u.jabatan_dilamar IS NULL OR u.jabatan_dilamar = '')";
    } else {
        $where[] = 'u.jabatan_dilamar = ?';
        $params[] = $jabatan;
    }
}

if ($mode === 'list' && $statusSeleksi !== '') {
    if ($statusSeleksi === 'belum') {
        $where[] = 'u.status_seleksi IS NULL';
    } else {
        $where[] = 'u.status_seleksi = ?';
        $params[] = $statusSeleksi;
    }
}

if ($search !== '') {
    if ($mode === 'detail') {
        $where[] = 't.judul LIKE ?';
        $params[] = "%{$search}%";
    } else {
        $where[] = '(u.nama LIKE ? OR u.email LIKE ? OR u.jabatan_dilamar LIKE ? OR t.judul LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$daftarTes = $db->query("SELECT id, judul FROM tes ORDER BY judul ASC")->fetchAll();
$daftarJabatan = $db->query("SELECT DISTINCT jabatan_dilamar FROM users WHERE role = 'peserta' AND jabatan_dilamar IS NOT NULL AND jabatan_dilamar <> '' ORDER BY jabatan_dilamar ASC")->fetchAll(PDO::FETCH_COLUMN);

$pesertaInfo = null;
$pesertaList = [];
$hasilList = [];

if ($mode === 'detail') {
    $stmtP = $db->prepare("SELECT id, nama, email, jabatan_dilamar, status_seleksi FROM users WHERE id = ?");
    $stmtP->execute([$pesertaId]);
    $pesertaInfo = $stmtP->fetch();
    if (!$pesertaInfo) { header('Location: hasil.php'); exit; }

    $sql = "
        SELECT a.*, u.nama AS nama_peserta, u.email, t.judul AS judul_tes, t.jenis, t.tipe_psikotes, t.durasi_menit
        FROM attempt a
        JOIN users u ON u.id = a.user_id
        JOIN tes t ON t.id = a.tes_id
        $whereSql
        ORDER BY a.waktu_mulai DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $hasilList = $stmt->fetchAll();

    $totalUjian = count($hasilList);
    $totalSelesai = count(array_filter($hasilList, fn($i) => $i['status'] === 'selesai'));
    $totalBerlangsung = $totalUjian - $totalSelesai;
    $cardPertama = ['label' => 'Total Pengerjaan', 'nilai' => $totalUjian];
} else {
    $sql = "
        SELECT u.id AS user_id, u.nama AS nama_peserta, u.email,
               u.jabatan_dilamar, u.status_seleksi,
               COUNT(a.id) AS total_tes,
               SUM(CASE WHEN a.status = 'selesai' THEN 1 ELSE 0 END) AS total_selesai,
               SUM(CASE WHEN a.status <> 'selesai' THEN 1 ELSE 0 END) AS total_berlangsung,
               MAX(a.waktu_mulai) AS terakhir
        FROM attempt a
        JOIN users u ON u.id = a.user_id
        JOIN tes t ON t.id = a.tes_id
        $whereSql
        GROUP BY u.id, u.nama, u.email, u.jabatan_dilamar, u.status_seleksi
        ORDER BY terakhir DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $pesertaList = $stmt->fetchAll();

    $totalSelesai = array_sum(array_map(fn($r) => (int)$r['total_selesai'], $pesertaList));
    $totalBerlangsung = array_sum(array_map(fn($r) => (int)$r['total_berlangsung'], $pesertaList));
    $cardPertama = ['label' => 'Total Peserta', 'nilai' => count($pesertaList)];
}

// Query string filter untuk ekspor data
$queryExport = http_build_query(['peserta_id' => $pesertaId, 'tes_id' => $tesId, 'search' => $search, 'jabatan' => $jabatan, 'status_seleksi' => $statusSeleksi]);

/** Badge status seleksi (read-only; diubah dari menu Peserta). */
function badgeStatusSeleksi(?string $nilai): string {
    if ($nilai === null || $nilai === '' || !isset(STATUS_SELEKSI[$nilai])) {
        return '<span class="badge-seleksi s-belum">Belum ditentukan</span>';
    }
    return '<span class="badge-seleksi s-' . $nilai . '">' . STATUS_SELEKSI[$nilai] . '</span>';
}

$pageTitle = 'Hasil & Nilai';
$pageDesc = $mode === 'detail'
    ? 'Seluruh tes yang dikerjakan ' . $pesertaInfo['nama']
    : 'Pilih peserta untuk melihat hasil tes dan profil psikotesnya';
$activeMenu = 'hasil';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes pulseGlow {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.4; }
    }

    .card-anim { animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) both; }
    .delay-1 { animation-delay: 0.05s; }
    .delay-2 { animation-delay: 0.1s; }
    .delay-3 { animation-delay: 0.15s; }
    .delay-4 { animation-delay: 0.2s; }

    .card-modern-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
        margin-bottom: 20px;
        transition: box-shadow 0.3s ease;
    }

    .stat-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease, border-color 0.3s ease;
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.06);
        border-color: #cbd5e1;
    }
    .stat-icon {
        width: 44px; height: 44px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.25rem; transition: transform 0.3s ease;
    }
    .stat-card:hover .stat-icon { transform: scale(1.1) rotate(-5deg); }

    .form-control-modern, .form-select-modern {
        border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 12px;
        font-size: 0.85rem; transition: all 0.2s ease;
    }
    .form-control-modern:focus, .form-select-modern:focus {
        border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
    }

    .btn-action { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
    .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08); }

    .badge-soft-slate { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-weight: 600; }
    .badge-soft-purple { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; font-weight: 600; }

    .badge-status-dot {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: 20px;
    }
    .status-selesai { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .status-berlangsung { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .pulse-dot { animation: pulseGlow 1.5s infinite ease-in-out; }

    .table-modern { border-collapse: separate; border-spacing: 0; }
    .table-modern thead th {
        background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.78rem;
        text-transform: uppercase; letter-spacing: 0.03em; padding: 12px 16px;
        border-bottom: 1px solid #e2e8f0;
    }
    .table-modern tbody tr { transition: background-color 0.2s ease; }
    .table-modern tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .table-modern tbody tr:last-child td { border-bottom: none; }
    .table-modern tbody tr:hover { background-color: #f8fafc; }

    .link-peserta { color: #0f172a; text-decoration: none; transition: color 0.2s ease; }
    .link-peserta:hover { color: #4338ca; text-decoration: underline; }

    .avatar-peserta {
        width: 38px; height: 38px; border-radius: 50%;
        background: #e0e7ff; color: #4338ca; font-weight: 700;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .avatar-peserta.lg { width: 52px; height: 52px; font-size: 1.25rem; }

    .score-display { font-size: 1rem; font-weight: 700; color: #0f172a; }
    .profile-display {
        font-size: 0.82rem; font-weight: 700; color: #4338ca; background: #eef2ff;
        padding: 3px 10px; border-radius: 6px; border: 1px solid #c7d2fe;
        display: inline-block; transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    tr:hover .profile-display { transform: scale(1.03); box-shadow: 0 2px 6px rgba(99, 102, 241, 0.15); }

    /* Badge status seleksi (read-only) */
    .badge-seleksi {
        display: inline-flex; align-items: center; font-size: 0.75rem; font-weight: 700;
        padding: 4px 12px; border-radius: 20px; border: 1px solid #e2e8f0;
        background: #f8fafc; color: #64748b; white-space: nowrap;
    }
    .badge-seleksi.s-diterima    { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .badge-seleksi.s-disimpan    { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .badge-seleksi.s-tidak_lolos { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
</style>

<!-- Metric Cards Overview -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card card-anim delay-1">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                <i class="bi <?= $mode === 'detail' ? 'bi-file-earmark-text' : 'bi-people' ?>"></i>
            </div>
            <div>
                <div class="text-muted small fw-semibold"><?= e($cardPertama['label']) ?></div>
                <div class="fs-4 fw-bold text-dark"><?= (int)$cardPertama['nilai'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card card-anim delay-2">
            <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="text-muted small fw-semibold">Ujian Selesai</div>
                <div class="fs-4 fw-bold text-dark"><?= (int)$totalSelesai ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card card-anim delay-3">
            <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="text-muted small fw-semibold">Sedang Berlangsung</div>
                <div class="fs-4 fw-bold text-dark"><?= (int)$totalBerlangsung ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($mode === 'detail'): ?>
<!-- Header Peserta -->
<div class="card-modern-box card-anim delay-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <span class="avatar-peserta lg"><?= e(strtoupper(mb_substr($pesertaInfo['nama'], 0, 1))) ?></span>
            <div>
                <h5 class="fw-bold mb-0"><?= e($pesertaInfo['nama']) ?></h5>
                <div class="text-muted small"><?= e($pesertaInfo['email']) ?></div>
                <div class="small mt-1">
                    <i class="bi bi-briefcase text-muted me-1"></i>
                    <span class="fw-semibold"><?= !empty($pesertaInfo['jabatan_dilamar']) ? e($pesertaInfo['jabatan_dilamar']) : '-' ?></span>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?= badgeStatusSeleksi($pesertaInfo['status_seleksi'] ?? null) ?>
            <a href="hasil.php" class="btn btn-sm btn-light border fw-semibold text-secondary d-inline-flex align-items-center gap-1 btn-action">
                <i class="bi bi-arrow-left"></i> Semua Peserta
            </a>
            <a href="peserta_summary.php?user_id=<?= $pesertaId ?>" class="btn btn-sm btn-primary fw-semibold d-inline-flex align-items-center gap-1 shadow-sm btn-action" title="Ringkasan semua tes peserta ini">
                <i class="bi bi-person-lines-fill"></i> Summary Peserta
            </a>
            <a href="hasil_export_excel_all.php?<?= $queryExport ?>" class="btn btn-sm btn-success fw-semibold d-inline-flex align-items-center gap-1 shadow-sm btn-action">
                <i class="bi bi-file-earmark-excel"></i> Export Excel
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card-modern-box card-anim delay-3">
    <form method="GET" action="hasil.php" class="row g-3 align-items-end">
        <?php if ($mode === 'detail'): ?>
            <input type="hidden" name="peserta_id" value="<?= $pesertaId ?>">
        <?php endif; ?>

        <div class="col-md-<?= $mode === 'list' ? '3' : '4' ?>">
            <label class="form-label small fw-semibold text-secondary mb-1">
                <?= $mode === 'detail' ? 'Cari Modul Tes' : 'Cari Peserta / Jabatan / Ujian' ?>
            </label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control form-control-modern border-start-0" placeholder="Ketik kata kunci..." value="<?= e($search) ?>">
            </div>
        </div>

        <?php if ($mode === 'list'): ?>
        <div class="col-md-3">
            <label class="form-label small fw-semibold text-secondary mb-1">Filter Jabatan</label>
            <select name="jabatan" class="form-select form-select-sm form-select-modern" onchange="this.form.submit()">
                <option value="">Semua Jabatan</option>
                <?php foreach ($daftarJabatan as $jb): ?>
                    <option value="<?= e($jb) ?>" <?= $jabatan === $jb ? 'selected' : '' ?>><?= e($jb) ?></option>
                <?php endforeach; ?>
                <option value="__kosong" <?= $jabatan === '__kosong' ? 'selected' : '' ?>>Belum diisi</option>
            </select>
        </div>
        <?php else: ?>
        <div class="col-md-4">
            <label class="form-label small fw-semibold text-secondary mb-1">Filter Modul Tes</label>
            <select name="tes_id" class="form-select form-select-sm form-select-modern" onchange="this.form.submit()">
                <option value="0">Semua Modul Tes</option>
                <?php foreach ($daftarTes as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $tesId === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['judul']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($mode === 'list'): ?>
        <div class="col-md-3">
            <label class="form-label small fw-semibold text-secondary mb-1">Filter Status Seleksi</label>
            <select name="status_seleksi" class="form-select form-select-sm form-select-modern" onchange="this.form.submit()">
                <option value="">Semua Status</option>
                <option value="belum" <?= $statusSeleksi === 'belum' ? 'selected' : '' ?>>Belum ditentukan</option>
                <?php foreach (STATUS_SELEKSI as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $statusSeleksi === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="col-md-<?= $mode === 'list' ? '3' : '4' ?> d-flex gap-2 justify-content-md-end flex-wrap">
            <?php if ($tesId > 0 || $search !== '' || $statusSeleksi !== '' || $jabatan !== ''): ?>
                <a href="hasil.php<?= $mode === 'detail' ? '?peserta_id=' . $pesertaId : '' ?>" class="btn btn-sm btn-light border fw-semibold text-secondary d-inline-flex align-items-center gap-1 btn-action" title="Reset Filter">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            <?php endif; ?>

            <?php if ($mode === 'list'): ?>
                <a href="hasil_export_excel_all.php?<?= $queryExport ?>" class="btn btn-sm btn-success fw-semibold d-inline-flex align-items-center gap-1 shadow-sm btn-action">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($mode === 'list'): ?>
<!-- ===================== TAMPILAN AWAL: DAFTAR PESERTA ===================== -->
<div class="card-modern-box p-0 overflow-hidden card-anim delay-4">
    <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center bg-white">
        <h6 class="fw-bold text-dark mb-0">Daftar Peserta</h6>
        <span class="badge bg-light text-dark border font-monospace"><?= count($pesertaList) ?> Peserta</span>
    </div>

    <div class="table-responsive">
        <table class="table table-modern align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col" style="width: 32%;">Peserta</th>
                    <th scope="col" style="width: 18%;">Jabatan</th>
                    <th scope="col" class="text-center" style="width: 10%;">Total Tes</th>
                    <th scope="col" class="text-center" style="width: 12%;">Selesai</th>
                    <th scope="col" style="width: 16%;">Status Seleksi</th>
                    <th scope="col" class="text-end" style="width: 12%;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pesertaList)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <div class="py-3">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                <div class="fw-semibold text-secondary">Tidak ada peserta ditemukan</div>
                                <div class="small text-muted">Coba ubah kata kunci atau reset filter pencarian.</div>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pesertaList as $p): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <span class="avatar-peserta"><?= e(strtoupper(mb_substr($p['nama_peserta'], 0, 1))) ?></span>
                                <div>
                                    <div class="fw-bold">
                                        <a href="hasil.php?peserta_id=<?= (int)$p['user_id'] ?>" class="link-peserta">
                                            <?= e($p['nama_peserta']) ?>
                                        </a>
                                    </div>
                                    <div class="text-muted small" style="font-size: 0.8rem;"><?= e($p['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="small fw-semibold text-dark">
                            <?= !empty($p['jabatan_dilamar']) ? e($p['jabatan_dilamar']) : '<span class="text-muted">-</span>' ?>
                        </td>
                        <td class="text-center fw-bold"><?= (int)$p['total_tes'] ?></td>
                        <td class="text-center">
                            <span class="badge-status-dot status-selesai"><?= (int)$p['total_selesai'] ?></span>
                            <?php if ((int)$p['total_berlangsung'] > 0): ?>
                                <span class="badge-status-dot status-berlangsung" title="Sedang berlangsung"><?= (int)$p['total_berlangsung'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= badgeStatusSeleksi($p['status_seleksi'] ?? null) ?></td>
                        <td class="text-end">
                            <a href="hasil.php?peserta_id=<?= (int)$p['user_id'] ?>" class="btn btn-sm btn-light border text-primary fw-semibold btn-action" title="Lihat semua tes peserta ini">
                                <i class="bi bi-eye"></i> Lihat Tes
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ===================== DETAIL PESERTA: DAFTAR TES ===================== -->
<div class="card-modern-box p-0 overflow-hidden card-anim delay-4">
    <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center bg-white">
        <h6 class="fw-bold text-dark mb-0">Daftar Tes <?= e($pesertaInfo['nama']) ?></h6>
        <span class="badge bg-light text-dark border font-monospace"><?= count($hasilList) ?> Data</span>
    </div>

    <div class="table-responsive">
        <table class="table table-modern align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col" style="width: 30%;">Modul Tes</th>
                    <th scope="col" style="width: 17%;">Status</th>
                    <th scope="col" style="width: 23%;">Waktu Pengerjaan</th>
                    <th scope="col" style="width: 18%;">Hasil / Skor</th>
                    <th scope="col" class="text-end" style="width: 12%;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($hasilList)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <div class="py-3">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                <div class="fw-semibold text-secondary">Tidak ada data tes ditemukan</div>
                                <div class="small text-muted">Coba ubah kata kunci atau reset filter pencarian.</div>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($hasilList as $h): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold text-dark mb-1"><?= e($h['judul_tes']) ?></div>
                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                <span class="badge badge-soft-slate uppercase" style="font-size: 0.68rem; padding: 2px 6px;">
                                    <?= e($h['jenis']) ?>
                                </span>
                                <?php if ($h['jenis'] === 'psikotes' && !empty($h['tipe_psikotes'])): ?>
                                    <span class="badge badge-soft-purple uppercase" style="font-size: 0.68rem; padding: 2px 6px;">
                                        <?= strtoupper(e($h['tipe_psikotes'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($h['status'] === 'selesai'): ?>
                                <span class="badge-status-dot status-selesai">
                                    <i class="bi bi-circle-fill" style="font-size: 0.4rem;"></i> Selesai
                                </span>
                            <?php else: ?>
                                <span class="badge-status-dot status-berlangsung">
                                    <i class="bi bi-circle-fill pulse-dot" style="font-size: 0.4rem;"></i> Berlangsung
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="text-dark small"><i class="bi bi-play-circle text-muted me-1"></i><?= formatTanggal($h['waktu_mulai']) ?></div>
                            <?php if ($h['waktu_selesai']): ?>
                                <div class="text-muted small mt-1" style="font-size: 0.78rem;"><i class="bi bi-check2-circle text-success me-1"></i><?= formatTanggal($h['waktu_selesai']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($h['status'] === 'selesai'): ?>
                                <?php if ($h['jenis'] === 'logika'): ?>
                                    <?php if (($h['iq_score'] ?? null) !== null): ?>
                                        <span class="score-display">IQ <?= (int)$h['iq_score'] ?></span>
                                    <?php else: ?>
                                        <span class="score-display"><?= (int)$h['total_skor'] ?></span> <span class="text-muted small">pts</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php $profilTampil = $h['profil_hasil'] ?? $h['hasil_psikotes'] ?? null; ?>
                                    <span class="profile-display"><?= e(($profilTampil !== null && $profilTampil !== '') ? $profilTampil : 'Silahkan cek detail') ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted font-monospace">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($h['status'] === 'selesai'): ?>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="hasil_detail.php?id=<?= $h['id'] ?>" class="btn btn-light border text-primary fw-semibold btn-action" title="Lihat Detail">
                                        <i class="bi bi-eye"></i> Detail
                                    </a>
                                    <a href="hasil_export_pdf.php?id=<?= $h['id'] ?>" class="btn btn-light border text-secondary btn-action" title="Export PDF">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                    <a href="hasil_export_excel.php?id=<?= $h['id'] ?>" class="btn btn-light border text-success btn-action" title="Export Excel">
                                        <i class="bi bi-file-earmark-excel"></i>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border font-monospace" style="font-weight: 500;">In Progress</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>