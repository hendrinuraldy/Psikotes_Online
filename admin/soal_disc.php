<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();
$tesId = (int)($_GET['tes_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tes WHERE id = ?");
$stmt->execute([$tesId]);
$tes = $stmt->fetch();
if (!$tes) { header('Location: tes.php'); exit; }
if ($tes['format_soal'] !== 'forced_choice') {
    // Halaman ini khusus format Most/Least. Soal single tetap dikelola di soal.php.
    header('Location: soal.php?tes_id=' . $tesId);
    exit;
}

$dimensiValid = ['D', 'I', 'S', 'C'];
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan_soal') {
        $soalId = (int)($_POST['soal_id'] ?? 0);
        $pertanyaanLabel = trim($_POST['pertanyaan'] ?? ('Soal DISC'));
        $teksArr = $_POST['pernyataan_teks'] ?? [];
        $dimMostArr = $_POST['pernyataan_dimensi_most'] ?? [];
        $dimLeastArr = $_POST['pernyataan_dimensi_least'] ?? [];

        $isiValid = 0;
        foreach ($teksArr as $t) { if (trim($t) !== '') $isiValid++; }

        if ($isiValid < 4) {
            $error = 'Keempat pernyataan (A-D) wajib diisi untuk soal forced-choice.';
        } else {
            $db->beginTransaction();

            if ($soalId > 0) {
                $stmt = $db->prepare("UPDATE soal SET pertanyaan = ? WHERE id = ? AND tes_id = ?");
                $stmt->execute([$pertanyaanLabel, $soalId, $tesId]);
                $db->prepare("DELETE FROM opsi WHERE soal_id = ?")->execute([$soalId]);
            } else {
                $urutanStmt = $db->prepare("SELECT COALESCE(MAX(urutan),0)+1 AS u FROM soal WHERE tes_id = ?");
                $urutanStmt->execute([$tesId]);
                $urutan = (int)$urutanStmt->fetch()['u'];
                $stmt = $db->prepare("INSERT INTO soal (tes_id, pertanyaan, gambar, urutan) VALUES (?, ?, NULL, ?)");
                $stmt->execute([$tesId, $pertanyaanLabel, $urutan]);
                $soalId = $db->lastInsertId();
            }

            $labels = ['A', 'B', 'C', 'D'];
            foreach ($labels as $i => $label) {
                $teks = trim($teksArr[$i] ?? '');
                if ($teks === '') continue;
                $dimMost = in_array($dimMostArr[$i] ?? '', $dimensiValid, true) ? $dimMostArr[$i] : null;
                $dimLeast = in_array($dimLeastArr[$i] ?? '', $dimensiValid, true) ? $dimLeastArr[$i] : null;

                $stmt = $db->prepare("INSERT INTO opsi (soal_id, label, teks, gambar, is_benar, nilai, dimensi, dimensi_least) VALUES (?, ?, ?, NULL, 0, 0, ?, ?)");
                $stmt->execute([$soalId, $label, $teks, $dimMost, $dimLeast]);
            }

            $db->commit();
            header('Location: soal_disc.php?tes_id=' . $tesId . '&status=saved');
            exit;
        }
    }

    if ($aksi === 'hapus_soal') {
        $soalId = (int)($_POST['soal_id'] ?? 0);
        if ($soalId > 0) {
            $db->prepare("DELETE FROM soal WHERE id = ? AND tes_id = ?")->execute([$soalId, $tesId]);
            header('Location: soal_disc.php?tes_id=' . $tesId . '&status=deleted');
            exit;
        }
    }
}

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'saved') $success = 'Soal berhasil disimpan.';
    elseif ($_GET['status'] === 'deleted') $success = 'Soal berhasil dihapus.';
}

$editSoal = null;
$editOpsi = [];
if (isset($_GET['edit_soal'])) {
    $stmt = $db->prepare("SELECT * FROM soal WHERE id = ? AND tes_id = ?");
    $stmt->execute([(int)$_GET['edit_soal'], $tesId]);
    $editSoal = $stmt->fetch();
    if ($editSoal) {
        $stmt = $db->prepare("SELECT * FROM opsi WHERE soal_id = ? ORDER BY label ASC");
        $stmt->execute([$editSoal['id']]);
        $editOpsi = $stmt->fetchAll();
    }
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

$pageTitle = 'Kelola Soal Forced-Choice: ' . $tes['judul'];
$pageDesc = 'Format Most/Least (Paling / Paling Tidak) &middot; ' . count($soalList) . ' soal';
$activeMenu = 'tes';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    .card-modern-box { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(15,23,42,.03); }
    .pernyataan-box { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#f8fafc; margin-bottom:10px; }
    .badge-dim { font-size:.72rem; font-weight:600; padding:2px 8px; border-radius:6px; }
    .badge-dim.most { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
    .badge-dim.least { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
</style>

<div class="mb-3">
    <a href="tes.php" class="btn btn-link text-decoration-none p-0 fw-semibold text-primary">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Kelola Tes
    </a>
</div>

<?php if ($success): ?><div class="alert alert-success border-0 shadow-sm mb-4"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger border-0 shadow-sm mb-4"><?= e($error) ?></div><?php endif; ?>

<div class="alert alert-light border small mb-4" style="background:#f8fafc;">
    <i class="bi bi-info-circle me-1 text-primary"></i>
    Format soal ini punya 4 pernyataan (A-D). Untuk tiap pernyataan, tentukan dimensinya
    <strong>jika dipilih sebagai "Paling Sesuai" (Most)</strong> dan <strong>jika dipilih sebagai "Paling Tidak Sesuai" (Least)</strong> -
    keduanya bisa berbeda huruf, sesuai kunci penilaian asli. Kosongkan salah satu dropdown kalau
    pernyataan itu memang netral (tidak mewakili dimensi apapun) untuk konteks tersebut.
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card-modern-box">
            <h6 class="fw-bold text-dark mb-3">
                <i class="bi bi-<?= $editSoal ? 'pencil-square text-warning' : 'plus-circle-fill text-primary' ?> me-1"></i>
                <?= $editSoal ? 'Edit Soal #' . (int)$editSoal['urutan'] : 'Tambah Soal Baru' ?>
            </h6>
            <form method="POST" action="soal_disc.php?tes_id=<?= $tesId ?>">
                <input type="hidden" name="aksi" value="simpan_soal">
                <input type="hidden" name="soal_id" value="<?= $editSoal['id'] ?? 0 ?>">

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Label / catatan soal (opsional, untuk admin saja)</label>
                    <input type="text" name="pertanyaan" class="form-control" value="<?= e($editSoal['pertanyaan'] ?? '') ?>" placeholder="Mis: Soal DISC nomor 25">
                </div>

                <?php
                $labelsArr = ['A', 'B', 'C', 'D'];
                $opsiByLabel = [];
                foreach ($editOpsi as $o) { $opsiByLabel[$o['label']] = $o; }
                foreach ($labelsArr as $i => $label):
                    $existing = $opsiByLabel[$label] ?? null;
                ?>
                <div class="pernyataan-box">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-primary rounded-circle px-2 py-1"><?= $label ?></span>
                        <input type="text" name="pernyataan_teks[]" class="form-control form-control-sm"
                               placeholder="Teks pernyataan <?= $label ?>" value="<?= e($existing['teks'] ?? '') ?>">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-text small mb-1">Dimensi jika dipilih <span class="badge-dim most">PALING SESUAI</span></label>
                            <select name="pernyataan_dimensi_most[]" class="form-select form-select-sm">
                                <option value="">— Netral —</option>
                                <?php foreach ($dimensiValid as $d): ?>
                                    <option value="<?= $d ?>" <?= (($existing['dimensi'] ?? '') === $d) ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-text small mb-1">Dimensi jika dipilih <span class="badge-dim least">PALING TIDAK SESUAI</span></label>
                            <select name="pernyataan_dimensi_least[]" class="form-select form-select-sm">
                                <option value="">— Netral —</option>
                                <?php foreach ($dimensiValid as $d): ?>
                                    <option value="<?= $d ?>" <?= (($existing['dimensi_least'] ?? '') === $d) ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary w-100 fw-semibold rounded-3 py-2 mt-2">
                    <i class="bi bi-check-lg me-1"></i> <?= $editSoal ? 'Update Soal' : 'Simpan Soal' ?>
                </button>
                <?php if ($editSoal): ?>
                    <a href="soal_disc.php?tes_id=<?= $tesId ?>" class="btn btn-light border w-100 mt-2 rounded-3 text-center d-block">Batal Edit</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card-modern-box">
            <h6 class="fw-bold text-dark mb-3">Daftar Soal (<?= count($soalList) ?>)</h6>
            <?php if (empty($soalList)): ?>
                <div class="text-center py-5 text-muted small">
                    <i class="bi bi-file-earmark-x fs-1 opacity-50 d-block mb-2"></i>
                    Belum ada soal. Jalankan seed SQL, atau tambah manual lewat form di samping.
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($soalList as $idx => $s): ?>
                    <div class="border rounded-3 p-3 bg-white">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="fw-bold text-dark">#<?= (int)$s['urutan'] ?>. <?= e($s['pertanyaan']) ?></span>
                            <div class="d-flex gap-1">
                                <a href="soal_disc.php?tes_id=<?= $tesId ?>&edit_soal=<?= $s['id'] ?>" class="btn btn-sm btn-outline-warning rounded-2"><i class="bi bi-pencil"></i></a>
                                <form method="POST" action="soal_disc.php?tes_id=<?= $tesId ?>" class="d-inline" onsubmit="return confirm('Hapus soal ini?');">
                                    <input type="hidden" name="aksi" value="hapus_soal">
                                    <input type="hidden" name="soal_id" value="<?= (int)$s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-2"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        <div class="row g-2">
                            <?php foreach ($s['opsi'] as $o): ?>
                            <div class="col-6">
                                <div class="p-2 border rounded-2 bg-light small">
                                    <strong><?= e($o['label']) ?>.</strong> <?= e($o['teks']) ?>
                                    <div class="mt-1 d-flex gap-1">
                                        <span class="badge-dim most">Most: <?= e($o['dimensi'] ?? '-') ?></span>
                                        <span class="badge-dim least">Least: <?= e($o['dimensi_least'] ?? '-') ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>