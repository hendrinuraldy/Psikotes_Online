<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();
$error = null;
$success = null;

// Handle Form Submit (Tambah / Edit / Toggle Status / Hapus / Grup)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    // Simpan / Update Tes
    if ($aksi === 'simpan_tes') {
        $id = (int)($_POST['id'] ?? 0);
        $judul = trim($_POST['judul'] ?? '');
        $jenis = $_POST['jenis'] ?? 'logika';
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $durasi = max(1, (int)($_POST['durasi_menit'] ?? 30));
        $urutan = (int)($_POST['urutan'] ?? 0);
        $grupId = (int)($_POST['grup_id'] ?? 0) ?: null;
        $urutanDalamGrup = (int)($_POST['urutan_dalam_grup'] ?? 0);

        $tipePsikotesInput = $_POST['tipe_psikotes'] ?? '';
        $tipePsikotes = ($jenis === 'psikotes' && in_array($tipePsikotesInput, ['disc', 'mbti', 'papikostik'], true))
            ? $tipePsikotesInput
            : null;

        $linkEksternal = ($jenis === 'eksternal') ? trim($_POST['link_eksternal'] ?? '') : null;

        if ($judul === '') {
            $error = 'Judul tes wajib diisi.';
        } elseif ($jenis === 'psikotes' && $tipePsikotes === null) {
            $error = 'Untuk tes psikotes, pilih kerangka penilaiannya (DISC, MBTI, atau Papikostik).';
        } elseif ($jenis === 'eksternal' && ($linkEksternal === '' || !filter_var($linkEksternal, FILTER_VALIDATE_URL))) {
            $error = 'Untuk tes eksternal, isi link tes dengan URL yang valid (harus diawali http:// atau https://).';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE tes SET judul=?, jenis=?, tipe_psikotes=?, link_eksternal=?, deskripsi=?, durasi_menit=?, urutan=?, grup_id=?, urutan_dalam_grup=? WHERE id=?");
                $stmt->execute([$judul, $jenis, $tipePsikotes, $linkEksternal, $deskripsi, $durasi, $urutan, $grupId, $urutanDalamGrup, $id]);
                header('Location: tes.php?status=updated');
            } else {
                if ($urutan <= 0) {
                    $maxUrutan = (int)($db->query("SELECT COALESCE(MAX(urutan),0) AS m FROM tes")->fetch()['m']);
                    $urutan = $maxUrutan + 1;
                }
                $stmt = $db->prepare("INSERT INTO tes (judul, jenis, tipe_psikotes, link_eksternal, deskripsi, durasi_menit, urutan, grup_id, urutan_dalam_grup, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif', NOW())");
                $stmt->execute([$judul, $jenis, $tipePsikotes, $linkEksternal, $deskripsi, $durasi, $urutan, $grupId, $urutanDalamGrup]);
                header('Location: tes.php?status=created');
            }
            exit;
        }
    }

    // Toggle Status Aktif / Nonaktif via POST (Aman CSRF)
    if ($aksi === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE tes SET status = IF(status='aktif','nonaktif','aktif') WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: tes.php?status=toggled');
            exit;
        }
    }

    // Hapus Tes via POST (Aman CSRF)
    if ($aksi === 'hapus_tes') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM tes WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: tes.php?status=deleted');
            exit;
        }
    }

    // Simpan Grup Tes Baru (mis. untuk menggabung CFIT 1-4 jadi satu unit di peserta)
    if ($aksi === 'simpan_grup') {
        $judulGrup = trim($_POST['grup_judul'] ?? '');
        if ($judulGrup === '') {
            $error = 'Judul grup wajib diisi.';
        } else {
            $stmt = $db->prepare("INSERT INTO tes_grup (judul, deskripsi, status) VALUES (?, ?, 'aktif')");
            $stmt->execute([$judulGrup, trim($_POST['grup_deskripsi'] ?? '')]);
            header('Location: tes.php?status=grup_saved');
            exit;
        }
    }

    // Hapus Grup Tes (tes anggotanya dilepas, TIDAK ikut terhapus)
    if ($aksi === 'hapus_grup') {
        $grupId = (int)($_POST['grup_id'] ?? 0);
        if ($grupId > 0) {
            $db->beginTransaction();
            $db->prepare("UPDATE tes SET grup_id = NULL, urutan_dalam_grup = 0 WHERE grup_id = ?")->execute([$grupId]);
            $db->prepare("DELETE FROM tes_grup WHERE id = ?")->execute([$grupId]);
            $db->commit();
            header('Location: tes.php?status=grup_deleted');
            exit;
        }
    }
}

// Menangkap Notifikasi
if (isset($_GET['status'])) {
    $st = $_GET['status'];
    if ($st === 'created') $success = 'Tes baru berhasil ditambahkan.';
    elseif ($st === 'updated') $success = 'Data tes berhasil diperbarui.';
    elseif ($st === 'toggled') $success = 'Status tes berhasil diubah.';
    elseif ($st === 'deleted') $success = 'Tes beserta data terkait berhasil dihapus.';
    elseif ($st === 'grup_saved') $success = 'Grup tes berhasil dibuat.';
    elseif ($st === 'grup_deleted') $success = 'Grup tes berhasil dihapus. Tes yang tadinya jadi anggota tetap ada, hanya dilepas dari grup.';
}

$daftarGrup = $db->query("SELECT * FROM tes_grup WHERE status = 'aktif' ORDER BY judul ASC")->fetchAll();

$daftarTes = $db->query("
    SELECT t.*, g.judul AS grup_judul, COUNT(s.id) AS jumlah_soal
    FROM tes t
    LEFT JOIN tes_grup g ON g.id = t.grup_id
    LEFT JOIN soal s ON s.tes_id = t.id
    GROUP BY t.id
    ORDER BY t.urutan ASC, t.grup_id ASC, t.urutan_dalam_grup ASC, t.id ASC
")->fetchAll();

$editTes = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM tes WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editTes = $stmt->fetch();
}

$pageTitle = 'Kelola Tes';
$pageDesc = 'Buat dan atur modul ujian: Tes Logika, Psikotes Kepribadian (DISC / MBTI / Papikostik), & Tes Eksternal';
$activeMenu = 'tes';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    .card-modern-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    }
    .badge-jenis {
        font-size: 0.7rem;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 6px;
        text-transform: uppercase;
    }
    .badge-jenis.logika { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .badge-jenis.psikotes { background: #faf5ff; color: #9333ea; border: 1px solid #e9d5ff; }
    .badge-jenis.eksternal { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }

    .badge-framework {
        font-size: 0.7rem;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 6px;
    }
    .badge-framework.disc { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .badge-framework.mbti { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
    .badge-framework.papikostik { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .badge-framework.warning { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .badge-status-inactive { background: #f8fafc; color: #94a3b8; border: 1px solid #e2e8f0; }
    .badge-grup {
        background: #ecfeff;
        color: #0e7490;
        border: 1px solid #a5f3fc;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 6px;
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
    @keyframes alertDrop {
        from { opacity: 0; transform: translateY(-14px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes revealField {
        from { opacity: 0; transform: translateY(-8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes editPulse {
        0%   { background-color: rgba(245, 158, 11, 0.18); }
        100% { background-color: rgba(245, 158, 11, 0.06); }
    }

    /* Kartu muncul bergantian
       (fill-mode "backwards" supaya efek hover tetap jalan setelah animasi selesai) */
    .card-modern-box {
        animation: fadeUp 0.55s cubic-bezier(.22,.9,.36,1) backwards;
        transition: box-shadow 0.25s ease, transform 0.25s ease;
    }
    .card-modern-box:hover {
        box-shadow: 0 10px 25px -8px rgba(15, 23, 42, 0.08);
    }
    .col-lg-4 > .card-modern-box:nth-child(1) { animation-delay: 0.05s; }
    .col-lg-4 > .card-modern-box:nth-child(2) { animation-delay: 0.18s; }
    .col-lg-8 > .card-modern-box { animation-delay: 0.3s; }

    /* Alert turun dari atas */
    .alert { animation: alertDrop 0.45s cubic-bezier(.22,.9,.36,1) backwards; }

    /* Item grup masuk bergantian */
    .grup-item {
        animation: rowIn 0.35s ease backwards;
        animation-delay: calc(0.3s + min(var(--i, 0), 8) * 50ms);
        transition: border-color 0.2s ease, background-color 0.2s ease, transform 0.2s ease;
    }
    .grup-item:hover {
        border-color: #a5f3fc !important;
        background-color: #f0fdff;
        transform: translateX(3px);
    }

    /* Daftar tes masuk satu per satu */
    .tes-item {
        animation: rowIn 0.4s ease backwards;
        animation-delay: calc(0.4s + min(var(--i, 0), 12) * 45ms);
        transition: background-color 0.15s ease, padding-left 0.2s ease, box-shadow 0.2s ease;
    }
    .tes-item:hover {
        background-color: #f8fafc;
        padding-left: 12px !important;
        box-shadow: inset 3px 0 0 #6366f1;
    }
    .tes-item.is-editing {
        background-color: rgba(245, 158, 11, 0.06);
        box-shadow: inset 3px 0 0 #f59e0b;
        padding-left: 12px !important;
        animation: rowIn 0.4s ease backwards, editPulse 1.2s ease 0.5s 2 alternate;
        animation-delay: calc(0.4s + min(var(--i, 0), 12) * 45ms), 0.5s;
    }

    /* Badge sedikit "hidup" saat baris di-hover */
    .tes-item .badge-jenis,
    .tes-item .badge-framework,
    .tes-item .badge-grup { transition: transform 0.2s ease; }
    .tes-item:hover .badge-jenis,
    .tes-item:hover .badge-framework,
    .tes-item:hover .badge-grup { transform: translateY(-1px) scale(1.04); }

    /* Tombol aksi: efek angkat & tekan */
    .btn { transition: transform 0.15s ease, box-shadow 0.2s ease, background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease; }
    .btn:hover { transform: translateY(-1px); }
    .btn:active { transform: scale(0.94); }
    .tes-item .btn-sm:hover { box-shadow: 0 4px 10px -3px rgba(15, 23, 42, 0.2); }
    .btn i.bi-toggle-on,
    .btn i.bi-toggle-off { display: inline-block; transition: transform 0.25s ease; }
    .btn:hover i.bi-toggle-on,
    .btn:hover i.bi-toggle-off { transform: scale(1.2); }
    .btn:hover i.bi-trash { animation: trashWiggle 0.4s ease; }
    @keyframes trashWiggle {
        0%, 100% { transform: rotate(0); }
        25% { transform: rotate(-12deg); }
        75% { transform: rotate(12deg); }
    }

    /* Field form: fokus lebih halus */
    .form-control, .form-select { transition: border-color 0.2s ease, box-shadow 0.25s ease, background-color 0.2s ease; }

    /* Field yang muncul/hilang sesuai jenis tes */
    .reveal { animation: revealField 0.3s cubic-bezier(.22,.9,.36,1) backwards; }

    /* Hormati pengguna yang mematikan animasi di sistemnya */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
    }
</style>

<!-- Alert Feedback -->
<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= e($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Kolom Kiri: Kelola Grup Tes + Form Tambah/Edit Tes -->
    <div class="col-lg-4">

        <!-- Kelola Grup Tes -->
        <div class="card-modern-box mb-4">
            <h6 class="fw-bold text-dark mb-1">
                <i class="bi bi-collection text-info me-2"></i>Grup Tes
            </h6>
            <p class="text-muted small mb-3">
                Gabungkan beberapa tes (mis. CFIT 1-4) jadi satu unit tampilan di dashboard peserta.
                Tiap anggota tetap punya bank soal &amp; durasinya sendiri — peserta otomatis lanjut
                ke bagian berikutnya begitu satu bagian selesai.
            </p>

            <?php if (!empty($daftarGrup)): ?>
            <div class="d-flex flex-column gap-2 mb-3">
                <?php foreach ($daftarGrup as $gi => $g): ?>
                <div class="grup-item d-flex justify-content-between align-items-center border rounded-3 px-3 py-2" style="--i: <?= (int)$gi ?>">
                    <span class="small fw-semibold text-dark"><?= e($g['judul']) ?></span>
                    <form method="POST" action="tes.php" onsubmit="return confirm('Hapus grup ini? Tes anggotanya TIDAK akan ikut terhapus, hanya dilepas dari grup.');">
                        <input type="hidden" name="aksi" value="hapus_grup">
                        <input type="hidden" name="grup_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-2"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="tes.php">
                <input type="hidden" name="aksi" value="simpan_grup">
                <div class="mb-2">
                    <input type="text" name="grup_judul" class="form-control form-control-sm" placeholder="Judul grup, mis: Tes CFIT" required>
                </div>
                <div class="mb-2">
                    <textarea name="grup_deskripsi" class="form-control form-control-sm" rows="2" placeholder="Deskripsi yang tampil di kartu peserta (opsional)"></textarea>
                </div>
                <button type="submit" class="btn btn-outline-info btn-sm w-100 fw-semibold rounded-3">
                    <i class="bi bi-plus-lg me-1"></i> Tambah Grup
                </button>
            </form>
        </div>

        <!-- Form Tambah / Edit Tes -->
        <div class="card-modern-box">
            <h6 class="fw-bold text-dark mb-3">
                <i class="bi bi-<?= $editTes ? 'pencil-square text-warning' : 'plus-circle-fill text-primary' ?> me-2"></i>
                <?= $editTes ? 'Edit Tes' : 'Tambah Tes Baru' ?>
            </h6>

            <form method="POST" action="tes.php">
                <input type="hidden" name="aksi" value="simpan_tes">
                <input type="hidden" name="id" value="<?= $editTes['id'] ?? 0 ?>">

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Judul Tes</label>
                    <input type="text" name="judul" class="form-control" value="<?= e($editTes['judul'] ?? '') ?>" placeholder="Mis: Tes Logika Penalaran" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Jenis Tes</label>
                    <select name="jenis" id="selectJenis" class="form-select">
                        <option value="logika" <?= (($editTes['jenis'] ?? '') === 'logika') ? 'selected' : '' ?>>Tes Logika (Kuantitatif/Verbal)</option>
                        <option value="psikotes" <?= (($editTes['jenis'] ?? '') === 'psikotes') ? 'selected' : '' ?>>Tes Psikotes Kepribadian (Bank Soal)</option>
                        <option value="eksternal" <?= (($editTes['jenis'] ?? '') === 'eksternal') ? 'selected' : '' ?>>Tes Eksternal (Link + Upload Hasil)</option>
                    </select>
                </div>

                <div class="mb-3" id="wrapTipePsikotes" style="<?= (($editTes['jenis'] ?? '') === 'psikotes') ? '' : 'display:none;' ?>">
                    <label class="form-label small fw-semibold">Kerangka Penilaian Psikotes</label>
                    <select name="tipe_psikotes" class="form-select">
                        <option value="">— Pilih Kerangka —</option>
                        <option value="disc" <?= (($editTes['tipe_psikotes'] ?? '') === 'disc') ? 'selected' : '' ?>>DISC (Dominance, Influence, Steadiness, Conscientiousness)</option>
                        <option value="mbti" <?= (($editTes['tipe_psikotes'] ?? '') === 'mbti') ? 'selected' : '' ?>>MBTI (16 Tipe Kepribadian)</option>
                        <option value="papikostik" <?= (($editTes['tipe_psikotes'] ?? '') === 'papikostik') ? 'selected' : '' ?>>Papikostik (PAPI Kostick, 20 Dimensi)</option>
                    </select>
                    <div class="form-text small text-muted mt-1">
                        Sistem akan mengalkulasi skor berdasarkan bobot kriteria pada tiap opsi jawaban.
                    </div>
                </div>

                <div class="mb-3" id="wrapLinkEksternal" style="<?= (($editTes['jenis'] ?? '') === 'eksternal') ? '' : 'display:none;' ?>">
                    <label class="form-label small fw-semibold">Link Tes Eksternal</label>
                    <input type="url" name="link_eksternal" class="form-control" placeholder="https://openpsychometrics.org/tests/OEJTS/" value="<?= e($editTes['link_eksternal'] ?? '') ?>">
                    <div class="form-text small text-muted mt-1">
                        Peserta akan diarahkan ke link ini di tab baru, lalu diminta upload
                        screenshot hasilnya sebelum waktu habis. Tidak ada soal/bank soal untuk jenis ini —
                        penilaian dilakukan manual oleh admin dari screenshot yang diupload.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Deskripsi / Petunjuk</label>
                    <textarea name="deskripsi" class="form-control" rows="3" placeholder="Petunjuk pengerjaan untuk peserta..."><?= e($editTes['deskripsi'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Durasi Pengerjaan (Menit)</label>
                    <input type="number" name="durasi_menit" min="1" class="form-control" value="<?= e((string)($editTes['durasi_menit'] ?? 30)) ?>" required>
                    <div class="form-text small text-muted mt-1">
                        Waktu mundur yang dihitung saat peserta memulai ujian.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Urutan Pengerjaan</label>
                    <input type="number" name="urutan" min="0" class="form-control" value="<?= e((string)($editTes['urutan'] ?? 0)) ?>" placeholder="Kosongkan / 0 untuk otomatis di urutan terakhir">
                    <div class="form-text small text-muted mt-1">
                        Menentukan tes/grup mana yang harus diselesaikan lebih dulu di dashboard peserta
                        (angka kecil dikerjakan lebih dulu). Kalau tes ini anggota grup, gunakan urutan yang
                        SAMA untuk semua anggotanya supaya kartu gabungan muncul di satu posisi yang konsisten.
                    </div>
                </div>

                <hr class="my-3">

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Gabung ke Grup Tes (opsional)</label>
                    <select name="grup_id" class="form-select">
                        <option value="0">— Tidak digabung —</option>
                        <?php foreach ($daftarGrup as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= (int)($editTes['grup_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['judul']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text small text-muted mt-1">
                        Kalau dipilih, tes ini akan tampil sebagai bagian dari kartu grup (bukan kartu
                        sendiri) di dashboard peserta, dan otomatis lanjut ke bagian berikutnya begitu selesai.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Urutan Bagian dalam Grup</label>
                    <input type="number" name="urutan_dalam_grup" min="0" class="form-control" value="<?= e((string)($editTes['urutan_dalam_grup'] ?? 0)) ?>" placeholder="Mis: 1 untuk bagian pertama, 2 untuk bagian kedua, dst">
                    <div class="form-text small text-muted mt-1">
                        Hanya berlaku kalau tes ini digabung ke grup di atas. Menentukan urutan bagian
                        (mis. CFIT 1 = 1, CFIT 2 = 2, dst) — peserta mengerjakan sesuai urutan ini.
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold rounded-3 py-2">
                    <i class="bi bi-check-lg me-1"></i> <?= $editTes ? 'Update Tes' : 'Simpan Tes' ?>
                </button>

                <?php if ($editTes): ?>
                    <a href="tes.php" class="btn btn-light border w-100 mt-2 rounded-3 text-center d-block">Batal Edit</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Daftar Tes -->
    <div class="col-lg-8">
        <div class="card-modern-box">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0">Daftar Modul Tes (<?= count($daftarTes) ?>)</h6>
            </div>

            <?php if (empty($daftarTes)): ?>
                <div class="text-center py-5 text-muted small">
                    <i class="bi bi-journal-x fs-1 opacity-50 d-block mb-2"></i>
                    Belum ada modul tes yang dibuat. Gunakan formulir di samping untuk membuat baru.
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush border-top">
                    <?php foreach ($daftarTes as $ti => $t): ?>
                    <div class="tes-item list-group-item py-3 px-0 d-flex justify-content-between align-items-center flex-wrap gap-2<?= ($editTes && (int)$editTes['id'] === (int)$t['id']) ? ' is-editing' : '' ?>" style="--i: <?= (int)$ti ?>">
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <span class="badge bg-dark bg-opacity-75 text-white" style="font-size:0.7rem; padding:3px 8px; border-radius:6px;" title="Urutan pengerjaan">#<?= (int)$t['urutan'] ?></span>
                                <span class="fw-bold text-dark fs-6"><?= e($t['judul']) ?></span>
                                <span class="badge-jenis <?= e($t['jenis']) ?>">
                                    <?= e($t['jenis']) ?>
                                </span>

                                <?php if ($t['jenis'] === 'psikotes' && $t['tipe_psikotes']): ?>
                                    <span class="badge-framework <?= e($t['tipe_psikotes']) ?>">
                                        <?= strtoupper(e($t['tipe_psikotes'])) ?>
                                    </span>
                                <?php elseif ($t['jenis'] === 'psikotes'): ?>
                                    <span class="badge-framework warning">Kerangka Belum Set</span>
                                <?php endif; ?>

                                <?php if ($t['grup_id']): ?>
                                    <span class="badge-grup">
                                        <i class="bi bi-collection me-1"></i><?= e($t['grup_judul']) ?> &middot; Bagian <?= (int)$t['urutan_dalam_grup'] ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($t['status'] === 'nonaktif'): ?>
                                    <span class="badge badge-status-inactive">Nonaktif</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small">
                                <?php if ($t['jenis'] === 'eksternal'): ?>
                                    <i class="bi bi-box-arrow-up-right me-1"></i><a href="<?= e($t['link_eksternal']) ?>" target="_blank" class="text-muted"><?= e($t['link_eksternal']) ?></a>
                                <?php else: ?>
                                    <i class="bi bi-card-checklist me-1"></i><?= (int)$t['jumlah_soal'] ?> Soal
                                <?php endif; ?>
                                <span class="mx-1">&middot;</span>
                                <i class="bi bi-clock me-1"></i><?= (int)$t['durasi_menit'] ?> Menit
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex align-items-center gap-1">
                            <?php if ($t['jenis'] !== 'eksternal'): ?>
                            <a href="soal.php?tes_id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary rounded-2" title="Kelola Soal">
                                <i class="bi bi-list-check me-1"></i> Soal
                            </a>

                            <a href="import_soal.php?tes_id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-2" title="Import Soal dari PDF">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </a>
                            <a href="import_excel.php?tes_id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-success rounded-2" title="Import Soal dari Excel">
                                <i class="bi bi-file-earmark-excel"></i>
                            </a>
                            <?php endif; ?>

                            <a href="tes.php?edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-warning rounded-2" title="Edit Tes">
                                <i class="bi bi-pencil"></i>
                            </a>

                            <!-- Toggle Status Form -->
                            <form method="POST" action="tes.php" class="d-inline">
                                <input type="hidden" name="aksi" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-<?= $t['status'] === 'aktif' ? 'success' : 'secondary' ?> rounded-2" title="Toggle Aktif/Nonaktif">
                                    <i class="bi bi-toggle-<?= $t['status'] === 'aktif' ? 'on' : 'off' ?>"></i>
                                </button>
                            </form>

                            <!-- Hapus Tes Form -->
                            <form method="POST" action="tes.php" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus tes ini beserta semua soal dan hasil ujian terkait?');">
                                <input type="hidden" name="aksi" value="hapus_tes">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-2" title="Hapus Tes">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Toggle field tambahan berdasarkan jenis tes yang dipilih (dengan animasi muncul)
function tampilkanField(el, tampil) {
    if (!tampil) {
        el.style.display = 'none';
        el.classList.remove('reveal');
        return;
    }
    el.style.display = 'block';
    el.classList.remove('reveal');
    void el.offsetWidth; // restart animasi
    el.classList.add('reveal');
}

document.getElementById('selectJenis').addEventListener('change', function () {
    tampilkanField(document.getElementById('wrapTipePsikotes'), this.value === 'psikotes');
    tampilkanField(document.getElementById('wrapLinkEksternal'), this.value === 'eksternal');
});

// Kalau sedang mode edit, gulir mulus ke form supaya perubahan langsung terlihat
<?php if ($editTes): ?>
window.addEventListener('load', function () {
    if (window.innerWidth < 992) {
        const form = document.querySelector('input[name="aksi"][value="simpan_tes"]');
        if (form) form.closest('.card-modern-box').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>