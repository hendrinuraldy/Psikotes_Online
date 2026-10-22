<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();
$tesId = (int)($_GET['tes_id'] ?? 0);
$error = null;
$success = null;

// Ambil data tes
$stmt = $db->prepare("SELECT * FROM tes WHERE id = ?");
$stmt->execute([$tesId]);
$tes = $stmt->fetch();
if (!$tes) {
    header('Location: tes.php');
    exit;
}

// Label opsi jawaban: tes logika (mis. CFIT) boleh sampai F, psikotes tetap A-E
$labelsOpsi = ($tes['jenis'] === 'logika')
    ? ['A', 'B', 'C', 'D', 'E', 'F']
    : ['A', 'B', 'C', 'D', 'E'];
$labelAkhir = end($labelsOpsi);

// Kerangka dimensi psikotes
$dimensiValid = [];
if ($tes['jenis'] === 'psikotes') {
    if ($tes['tipe_psikotes'] === 'mbti') {
        $dimensiValid = ['E', 'I', 'S', 'N', 'T', 'F', 'J', 'P'];
    } elseif ($tes['tipe_psikotes'] === 'papikostik') {
        // 20 dimensi PAPI Kostick sesuai panduan skoring
        $dimensiValid = [
            'A', 'N', 'G', 'C', 'D', 'R', 'T', 'V', 'W', 'F',
            'L', 'P', 'I', 'S', 'B', 'O', 'X', 'E', 'K', 'Z'
        ];
    } elseif ($tes['tipe_psikotes'] === 'disc') {
        $dimensiValid = ['D', 'I', 'S', 'C'];
    }
}

$uploadDir = __DIR__ . '/../uploads/soal/';

function simpanGambar(string $field): ?string {
    global $uploadDir;
    if (empty($_FILES[$field]['name'])) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return null;
    $newName = uniqid('img_', true) . '.' . $ext;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . $newName);
    return $newName;
}

// Handle Form Submit (Tambah / Edit / Hapus Soal)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan_soal') {
        $soalId = (int)($_POST['soal_id'] ?? 0);
        $pertanyaan = trim($_POST['pertanyaan'] ?? '');
        $tipeSoal = $_POST['tipe_soal'] ?? 'single'; // 'single' atau 'multiple'
        $labels = $labelsOpsi;
        
        // Tangkap Kunci Jawaban
        $jawabanBenarArr = [];
        if ($tipeSoal === 'single') {
            if (isset($_POST['jawaban_benar_single'])) {
                $jawabanBenarArr[] = (int)$_POST['jawaban_benar_single'];
            }
        } else {
            if (isset($_POST['jawaban_benar_multi']) && is_array($_POST['jawaban_benar_multi'])) {
                $jawabanBenarArr = array_map('intval', $_POST['jawaban_benar_multi']);
            }
        }

        if ($pertanyaan === '') {
            $error = 'Pertanyaan wajib diisi.';
        } elseif ($tes['jenis'] === 'logika' && empty($jawabanBenarArr)) {
            $error = 'Pilih minimal 1 kunci jawaban.';
        } elseif ($tes['jenis'] === 'psikotes' && empty($dimensiValid)) {
            $error = 'Tes ini belum memiliki kerangka penilaian (DISC/MBTI/Papikostik) yang dikenali. Silakan atur tipe psikotesnya di Kelola Tes.';
        } else {
            // Validasi Dimensi Psikotes
            $opsiValidasiGagal = null;
            if ($tes['jenis'] === 'psikotes') {
                foreach ($labels as $i => $label) {
                    $teksCek = trim($_POST['opsi_teks'][$i] ?? '');
                    $adaGambar = !empty($_FILES['opsi_gambar']['name'][$i]);
                    if ($teksCek === '' && !$adaGambar) continue;
                    
                    $dimensiCek = trim($_POST['opsi_dimensi'][$i] ?? '');
                    if (!in_array($dimensiCek, $dimensiValid, true)) {
                        $opsiValidasiGagal = $label;
                        break;
                    }
                }
            }

            if ($opsiValidasiGagal) {
                $error = "Dimensi untuk Opsi {$opsiValidasiGagal} wajib dipilih agar skor psikotes dapat dihitung.";
            } else {
                $db->beginTransaction();

                if ($soalId > 0) {
                    // Update Soal
                    $gambarBaru = simpanGambar('gambar_soal');
                    if ($gambarBaru) {
                        $stmt = $db->prepare("UPDATE soal SET pertanyaan = ?, tipe_soal = ?, gambar = ? WHERE id = ? AND tes_id = ?");
                        $stmt->execute([$pertanyaan, $tipeSoal, $gambarBaru, $soalId, $tesId]);
                    } else {
                        $stmt = $db->prepare("UPDATE soal SET pertanyaan = ?, tipe_soal = ? WHERE id = ? AND tes_id = ?");
                        $stmt->execute([$pertanyaan, $tipeSoal, $soalId, $tesId]);
                    }
                    
                    // Hapus opsi lama, nanti di-insert ulang
                    $stmt = $db->prepare("DELETE FROM opsi WHERE soal_id = ?");
                    $stmt->execute([$soalId]);
                } else {
                    // Insert Soal Baru
                    $gambarSoal = simpanGambar('gambar_soal');
                    $stmt = $db->prepare("INSERT INTO soal (tes_id, pertanyaan, tipe_soal, gambar, urutan) VALUES (?, ?, ?, ?, (SELECT x FROM (SELECT COALESCE(MAX(urutan),0)+1 x FROM soal WHERE tes_id = ?) t))");
                    $stmt->execute([$tesId, $pertanyaan, $tipeSoal, $gambarSoal, $tesId]);
                    $soalId = $db->lastInsertId();
                }

                // Simpan Opsi Jawaban
                foreach ($labels as $i => $label) {
                    $teks = trim($_POST['opsi_teks'][$i] ?? '');
                    $gambarOpsi = null;

                    if (!empty($_FILES['opsi_gambar']['name'][$i]) && $_FILES['opsi_gambar']['error'][$i] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['opsi_gambar']['name'][$i], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                            $newName = uniqid('opt_', true) . '.' . $ext;
                            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                            move_uploaded_file($_FILES['opsi_gambar']['tmp_name'][$i], $uploadDir . $newName);
                            $gambarOpsi = $newName;
                        }
                    } else {
                        $gambarOpsi = $_POST['opsi_gambar_lama'][$i] ?? null;
                    }

                    if ($teks === '' && empty($gambarOpsi)) continue;

                    $dimensi = null;
                    if ($tes['jenis'] === 'logika') {
                        $isBenar = in_array($i, $jawabanBenarArr, true) ? 1 : 0;
                        $nilai = $isBenar ? 1 : 0;
                    } else {
                        $isBenar = 0;
                        $nilai = 0;
                        $dimensiInput = trim($_POST['opsi_dimensi'][$i] ?? '');
                        $dimensi = in_array($dimensiInput, $dimensiValid, true) ? $dimensiInput : null;
                    }

                    $stmt = $db->prepare("INSERT INTO opsi (soal_id, label, teks, gambar, is_benar, nilai, dimensi) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$soalId, $label, $teks, $gambarOpsi, $isBenar, $nilai, $dimensi]);
                }

                $db->commit();
                header('Location: soal.php?tes_id=' . $tesId . '&status=saved');
                exit;
            }
        }
    }

    // Hapus Soal
    if ($aksi === 'hapus_soal') {
        $soalId = (int)($_POST['soal_id'] ?? 0);
        if ($soalId > 0) {
            $stmt = $db->prepare("DELETE FROM soal WHERE id = ? AND tes_id = ?");
            $stmt->execute([$soalId, $tesId]);
            header('Location: soal.php?tes_id=' . $tesId . '&status=deleted');
            exit;
        }
    }
}

// Feedback Status
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'saved') $success = 'Soal berhasil disimpan.';
    elseif ($_GET['status'] === 'deleted') $success = 'Soal berhasil dihapus.';
}

// Data Edit Soal
$editSoal = null;
$editOpsiMap = [];
if (isset($_GET['edit_soal'])) {
    $stmt = $db->prepare("SELECT * FROM soal WHERE id = ? AND tes_id = ?");
    $stmt->execute([(int)$_GET['edit_soal'], $tesId]);
    $editSoal = $stmt->fetch();

    if ($editSoal) {
        $stmt = $db->prepare("SELECT * FROM opsi WHERE soal_id = ? ORDER BY label ASC");
        $stmt->execute([$editSoal['id']]);
        foreach ($stmt->fetchAll() as $op) {
            $editOpsiMap[$op['label']] = $op;
        }
    }
}

// Fetch All Soal
$soalList = $db->prepare("SELECT * FROM soal WHERE tes_id = ? ORDER BY urutan ASC, id ASC");
$soalList->execute([$tesId]);
$soalList = $soalList->fetchAll();

foreach ($soalList as &$s) {
    $stmt = $db->prepare("SELECT * FROM opsi WHERE soal_id = ? ORDER BY label ASC");
    $stmt->execute([$s['id']]);
    $s['opsi'] = $stmt->fetchAll();
}
unset($s);

$pageTitle = 'Kelola Soal: ' . $tes['judul'];
$pageDesc = ucfirst($tes['jenis']) . ' online ' . count($soalList) . ' Soal Tersedia';
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
    .opsi-box {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
        background: #f8fafc;
    }
    .badge-dimensi {
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 6px;
    }
    .opsi-preview-img {
        max-height: 140px;
        width: 100%;
        object-fit: contain;
    }
</style>

<div class="mb-3">
    <a href="tes.php" class="btn btn-link text-decoration-none p-0 fw-semibold text-primary">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Kelola Tes
    </a>
</div>

<div class="row g-4">
    <!-- Form Tambah/Edit Soal -->
    <div class="col-lg-5">
        <div class="card-modern-box">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0">
                    <i class="bi bi-<?= $editSoal ? 'pencil-square text-warning' : 'plus-circle-fill text-primary' ?> me-1"></i>
                    <?= $editSoal ? 'Edit Soal' : 'Tambah Soal Baru' ?>
                </h6>
            </div>

            <form method="POST" enctype="multipart/form-data" action="soal.php?tes_id=<?= $tesId ?>">
                <input type="hidden" name="aksi" value="simpan_soal">
                <input type="hidden" name="soal_id" value="<?= $editSoal['id'] ?? 0 ?>">

                <!-- Pilihan Tipe Soal (Single vs Multiple Choice) -->
                <?php if ($tes['jenis'] === 'logika'): ?>
                <div class="mb-3 p-3 bg-light rounded-3 border">
                    <label class="form-label small fw-bold text-dark mb-2">Tipe Pilihan Peserta</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="tipe_soal" id="tipeSingle" value="single" <?= ($editSoal['tipe_soal'] ?? 'single') === 'single' ? 'checked' : '' ?> onchange="toggleTipeSoal()">
                            <label class="form-check-label small fw-semibold" for="tipeSingle">
                                Single (Radio - Pilih 1)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="tipe_soal" id="tipeMultiple" value="multiple" <?= ($editSoal['tipe_soal'] ?? '') === 'multiple' ? 'checked' : '' ?> onchange="toggleTipeSoal()">
                            <label class="form-check-label small fw-semibold" for="tipeMultiple">
                                Multiple (Checkbox - Bisa >1)
                            </label>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Teks Pertanyaan</label>
                    <textarea name="pertanyaan" class="form-control" rows="3" placeholder="Tuliskan isi pertanyaan..." required><?= e($editSoal['pertanyaan'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Gambar Pertanyaan (Opsional)</label>
                    <?php if (!empty($editSoal['gambar'])): ?>
                        <div class="mb-2">
                            <img src="../uploads/soal/<?= e($editSoal['gambar']) ?>" class="rounded border" style="max-height: 100px;">
                        </div>
                    <?php endif; ?>
                    <input type="file" name="gambar_soal" class="form-control form-control-sm" accept="image/*">
                    <?php if (!empty($editSoal['gambar'])): ?>
                        <div class="form-text small text-muted mt-1">Kosongkan kalau tidak ingin mengganti gambar yang sudah ada.</div>
                    <?php endif; ?>
                </div>

                <label class="form-label small fw-semibold mb-2">Opsi Jawaban (A-<?= e($labelAkhir) ?>)</label>

                <?php if ($tes['jenis'] === 'psikotes' && empty($dimensiValid)): ?>
                    <div class="alert alert-warning small py-2 px-3 mb-2">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Tipe psikotes (<?= e($tes['tipe_psikotes'] ?? '-') ?>) untuk tes ini belum dikenali sistem, sehingga pilihan dimensi tidak dapat ditampilkan. Perbaiki kolom <code>tipe_psikotes</code> di Kelola Tes (nilai yang didukung: <code>disc</code>, <code>mbti</code>, <code>papikostik</code>).
                    </div>
                <?php endif; ?>

                <?php 
                $labelsArr = $labelsOpsi;
                foreach ($labelsArr as $i => $label): 
                    $opExisting = $editOpsiMap[$label] ?? null;
                    $teksOpsiVal = $opExisting['teks'] ?? '';
                    $dimensiVal = $opExisting['dimensi'] ?? '';
                    $isBenarVal = $opExisting['is_benar'] ?? 0;
                    $gambarLamaVal = $opExisting['gambar'] ?? '';
                ?>
                <div class="opsi-box mb-2">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-primary rounded-circle px-2 py-1"><?= $label ?></span>
                        <input type="text" name="opsi_teks[]" class="form-control form-control-sm" placeholder="Teks opsi <?= $label ?>" value="<?= e($teksOpsiVal) ?>">
                    </div>

                    <input type="hidden" name="opsi_gambar_lama[<?= $i ?>]" value="<?= e($gambarLamaVal) ?>">
                    
                    <div class="row g-2 align-items-center">
                        <div class="col-6">
                            <input type="file" name="opsi_gambar[]" class="form-control form-control-sm" accept="image/*">
                        </div>
                        <div class="col-6">
                            <?php if ($tes['jenis'] === 'logika'): ?>
                                <!-- Input Kunci Jawaban Single (Radio) -->
                                <div class="form-check input-kunci-single">
                                    <input class="form-check-input" type="radio" name="jawaban_benar_single" value="<?= $i ?>" id="radioBenar<?= $i ?>" <?= ($isBenarVal || ($i === 0 && !$editSoal)) ? 'checked' : '' ?>>
                                    <label class="form-check-label small text-success fw-semibold" for="radioBenar<?= $i ?>">Kunci Jawaban</label>
                                </div>
                                <!-- Input Kunci Jawaban Multiple (Checkbox) -->
                                <div class="form-check input-kunci-multi d-none">
                                    <input class="form-check-input" type="checkbox" name="jawaban_benar_multi[]" value="<?= $i ?>" id="checkBenar<?= $i ?>" <?= $isBenarVal ? 'checked' : '' ?>>
                                    <label class="form-check-label small text-success fw-semibold" for="checkBenar<?= $i ?>">Kunci Jawaban</label>
                                </div>
                            <?php else: ?>
                                <select name="opsi_dimensi[]" class="form-select form-select-sm dimensi-select">
                                    <option value="">— Pilih kecenderungan —</option>
                                    <?php foreach ($dimensiValid as $d): ?>
                                        <option value="<?= e($d) ?>" <?= $dimensiVal === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($gambarLamaVal)): ?>
                        <div class="mt-2 text-center">
                            <img src="../uploads/soal/<?= e($gambarLamaVal) ?>" class="rounded border p-1 bg-white" style="max-height: 60px;">
                        </div>
                        <div class="form-text small text-muted mt-1 text-center">Kosongkan file di atas kalau tidak ingin mengganti gambar opsi ini.</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary w-100 fw-semibold rounded-3 py-2 mt-3">
                    <i class="bi bi-check-lg me-1"></i> <?= $editSoal ? 'Update Soal' : 'Simpan Soal' ?>
                </button>

                <?php if ($editSoal): ?>
                    <a href="soal.php?tes_id=<?= $tesId ?>" class="btn btn-light border w-100 mt-2 rounded-3 text-center d-block">Batal Edit</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Daftar Soal -->
    <div class="col-lg-7">
        <div class="card-modern-box">
            <h6 class="fw-bold text-dark mb-3">Daftar Soal Tersimpan (<?= count($soalList) ?>)</h6>

            <?php if (empty($soalList)): ?>
                <div class="text-center py-5 text-muted small">
                    <i class="bi bi-file-earmark-x fs-1 opacity-50 d-block mb-2"></i>
                    Belum ada soal untuk tes ini. Silakan buat soal menggunakan form di samping.
                </div>
            <?php endif; ?>

            <div class="d-flex flex-column gap-3">
                <?php foreach ($soalList as $idx => $s): ?>
                <div class="border rounded-3 p-3 bg-white">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="fw-bold text-dark">#<?= $idx + 1 ?>. <?= e($s['pertanyaan']) ?></span>
                            <?php if ($tes['jenis'] === 'logika'): ?>
                                <span class="badge bg-<?= ($s['tipe_soal'] ?? 'single') === 'multiple' ? 'warning' : 'info' ?> text-dark ms-2 small">
                                    <?= ($s['tipe_soal'] ?? 'single') === 'multiple' ? 'Multiple Choice (Checkbox)' : 'Single Choice (Radio)' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1">
                            <a href="soal.php?tes_id=<?= $tesId ?>&edit_soal=<?= $s['id'] ?>" class="btn btn-sm btn-outline-warning rounded-2">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="soal.php?tes_id=<?= $tesId ?>" class="d-inline form-hapus-soal">
                                <input type="hidden" name="aksi" value="hapus_soal">
                                <input type="hidden" name="soal_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-2">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Preview Gambar Pertanyaan (kalau ada) -->
                    <?php if (!empty($s['gambar'])): ?>
                        <div class="mb-3">
                            <img src="../uploads/soal/<?= e($s['gambar']) ?>" class="img-thumbnail" style="max-height: 160px;" alt="Gambar soal">
                        </div>
                    <?php endif; ?>

                    <?php if (empty($s['opsi'])): ?>
                        <div class="text-muted small fst-italic">
                            <i class="bi bi-exclamation-circle me-1"></i> Belum ada opsi jawaban tersimpan untuk soal ini — klik Edit untuk melengkapinya.
                        </div>
                    <?php else: ?>
                    <!-- Grid Opsi Jawaban -->
                    <div class="row g-2">
                        <?php foreach ($s['opsi'] as $o): ?>
                        <div class="col-6 col-md-4">
                            <div class="p-2 border rounded-2 h-100 <?= $o['is_benar'] ? 'bg-success-subtle border-success' : 'bg-light' ?> small">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <span><strong class="text-dark"><?= e($o['label']) ?>.</strong> <?= e($o['teks']) ?></span>
                                    <?php if ($o['is_benar']): ?>
                                        <i class="bi bi-check-circle-fill text-success ms-1 fs-6"></i>
                                    <?php elseif (!empty($o['dimensi'])): ?>
                                        <span class="badge-dimensi"><?= e($o['dimensi']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($o['gambar'])): ?>
                                    <img src="../uploads/soal/<?= e($o['gambar']) ?>" class="opsi-preview-img rounded border mt-1" alt="Gambar opsi <?= e($o['label']) ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleTipeSoal() {
    const isMultiple = document.getElementById('tipeMultiple') && document.getElementById('tipeMultiple').checked;
    const singleInputs = document.querySelectorAll('.input-kunci-single');
    const multiInputs = document.querySelectorAll('.input-kunci-multi');

    if (isMultiple) {
        singleInputs.forEach(el => el.classList.add('d-none'));
        multiInputs.forEach(el => el.classList.remove('d-none'));
    } else {
        singleInputs.forEach(el => el.classList.remove('d-none'));
        multiInputs.forEach(el => el.classList.add('d-none'));
    }
}

// SweetAlert2 sudah dimuat di layout_top.php, jadi tidak perlu di-load lagi di sini
document.addEventListener('DOMContentLoaded', function () {
    toggleTipeSoal();

    <?php if ($success): ?>
    // Notifikasi sukses
    Swal.fire({
        icon: 'success',
        title: 'Berhasil',
        text: <?= json_encode($success, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        timer: 2000,
        timerProgressBar: true,
        showConfirmButton: false
    });
    // Bersihkan ?status=... dari URL supaya tidak muncul lagi saat refresh
    if (window.history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.delete('status');
        window.history.replaceState({}, '', url);
    }
    <?php endif; ?>

    <?php if ($error): ?>
    // Notifikasi error
    Swal.fire({
        icon: 'error',
        title: 'Oops...',
        text: <?= json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        confirmButtonText: 'Mengerti',
        confirmButtonColor: '#0d6efd'
    });
    <?php endif; ?>

    // Konfirmasi hapus soal
    document.querySelectorAll('.form-hapus-soal').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Hapus soal ini?',
                text: 'Soal beserta semua opsinya akan dihapus permanen.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="bi bi-trash me-1"></i> Ya, hapus',
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>