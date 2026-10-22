<?php
// Simpan file ini sebagai: peserta/upload_lamaran.php

require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');

$db = getDB();
$userId =$_SESSION['user_id'];

$allowedExt  = ['pdf', 'doc', 'docx'];
$allowedMime = [     'application/pdf',     'application/msword',     'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ];$maxSize = 5 * 1024 * 1024; // 5 MB

$errors  = [];$success = false;

// Ambil data lamaran yang sudah ada (kalau peserta mau upload ulang/ganti)
$stmt =$db->prepare("SELECT * FROM lamaran WHERE user_id = ?");
$stmt->execute([$userId]);
$existing =$stmt->fetch();

function validasiFileLamaran($file,$label, $allowedExt,$allowedMime, $maxSize, &$errors) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = "$label wajib diunggah.";
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {$errors[] = "Terjadi kesalahan saat mengunggah $label.";
        return null;
    }
    if ($file['size'] >$maxSize) {
        $errors[] = "$label melebihi ukuran maksimal 5 MB.";
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);$mime = finfo_file($finfo,$file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($ext,$allowedExt, true) || !in_array($mime,$allowedMime, true)) {
        $errors[] = "$label harus berformat PDF atau Word (.doc/.docx).";
        return null;
    }
    return $ext;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {$storageDir = __DIR__ . '/../storage/lamaran/';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    $extCv      = validasiFileLamaran($_FILES['cv'] ?? null, 'CV', $allowedExt,$allowedMime, $maxSize,$errors);
    $extLamaran = validasiFileLamaran($_FILES['form_lamaran'] ?? null, 'Form Lamaran', $allowedExt,$allowedMime, $maxSize,$errors);

    if (empty($errors)) {$cvFilename      = 'cv_' . $userId . '_' . time() . '.' . $extCv;
        $lamaranFilename = 'lamaran_' . $userId . '_' . time() . '.' . $extLamaran;

        move_uploaded_file($_FILES['cv']['tmp_name'], $storageDir .$cvFilename);
        move_uploaded_file($_FILES['form_lamaran']['tmp_name'], $storageDir .$lamaranFilename);

        if ($existing) {
            // Hapus file lama supaya storage tidak menumpuk
            @unlink($storageDir . basename($existing['cv_path']));
            @unlink($storageDir . basename($existing['lamaran_path']));

            $upd =$db->prepare("
                UPDATE lamaran
                SET cv_filename = ?, cv_path = ?, lamaran_filename = ?, lamaran_path = ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            $upd->execute([$_FILES['cv']['name'], $cvFilename,$_FILES['form_lamaran']['name'], $lamaranFilename,$userId,
            ]);
        } else {
            $ins =$db->prepare("
                INSERT INTO lamaran (user_id, cv_filename, cv_path, lamaran_filename, lamaran_path, uploaded_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([$userId,
                $_FILES['cv']['name'],$cvFilename,
                $_FILES['form_lamaran']['name'],$lamaranFilename,
            ]);
        }

        header('Location: dashboard.php');
        exit;
    }
}

$pageTitle  = 'Upload CV & Form Lamaran';
$pageDesc   = 'Lengkapi dokumen berikut sebelum dapat memulai tes';$activeMenu = 'dashboard';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    .upload-card {
        background: #ffffff;
        border: 1px solid #f0f2f5;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
        padding: 32px;
    }

    .dropzone-box {
        border: 2px dashed #cbd5e1;
        border-radius: 14px;
        padding: 24px 16px;
        text-align: center;
        background-color: #f8fafc;
        transition: all 0.25s ease-in-out;
        cursor: pointer;
        position: relative;
    }

    .dropzone-box:hover, .dropzone-box.dragover {
        border-color: #4f46e5;
        background-color: #f0fdf4;
    }

    .dropzone-icon {
        width: 48px;
        height: 48px;
        background: #eef2ff;
        color: #4f46e5;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        margin-bottom: 12px;
        transition: transform 0.2s;
    }

    .dropzone-box:hover .dropzone-icon {
        transform: translateY(-3px);
    }

    .file-input-hidden {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }

    .selected-file-info {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f1f5f9;
        border-radius: 10px;
        padding: 8px 12px;
        margin-top: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
    }

    .badge-soft-indigo {
        background-color: #e0e7ff;
        color: #3730a3;
        font-weight: 600;
        padding: 6px 12px;
        border-radius: 30px;
    }

    .btn-submit-modern {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
        border: none;
        border-radius: 12px;
        padding: 12px 24px;
        font-weight: 600;
        font-size: 0.95rem;
        letter-spacing: 0.2px;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        transition: all 0.2s ease;
    }

    .btn-submit-modern:hover {
        background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.2);
    }
</style>

<div class="row justify-content-center my-4">
    <div class="col-lg-8 col-xl-7">
        <div class="upload-card">
            
            <!-- Header -->
            <div class="d-flex align-items-center mb-3">
                <div class="flex-shrink-0 me-3">
                    <span class="badge-soft-indigo d-inline-flex align-items-center justify-content-center fs-4 p-3 rounded-circle">
                        <i class="bi bi-file-earmark-arrow-up"></i>
                    </span>
                </div>
                <div>
                    <h4 class="fw-bold text-dark mb-1">Upload CV &amp; Form Lamaran</h4>
                    <p class="text-muted mb-0 small">Lengkapi berkas pendaftaran Anda sebelum dapat mengakses tes online.</p>
                </div>
            </div>

            <hr class="my-4" style="border-color: #f1f5f9;">

            <!-- Format & Limit Rules -->
            <div class="d-flex align-items-center justify-content-between p-3 mb-4 rounded-3" style="background:#f8fafc; border: 1px solid #e2e8f0;">
                <div class="d-flex align-items-center text-muted fs-7">
                    <i class="bi bi-info-circle-fill text-primary me-2 fs-6"></i>
                    <span>Format yang diterima: <strong>PDF, DOC, DOCX</strong></span>
                </div>
                <span class="badge bg-secondary-subtle text-secondary border fs-8">Maks. 5 MB / file</span>
            </div>

            <!-- Error Alerts -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-exclamation-triangle-fill fs-5 me-2 mt-1"></i>
                        <div>
                            <strong class="d-block mb-1">Gagal Mengunggah Berkas:</strong>
                            <ul class="mb-0 ps-3 small">
                                <?php foreach ($errors as$err): ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Existing Files Banner -->
            <?php if ($existing): ?>
                <div class="alert alert-primary bg-primary-subtle text-primary-emphasis border-0 rounded-3 mb-4 p-3">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-check-circle-fill fs-5 me-2 mt-1"></i>
                        <div class="small">
                            <strong>Dokumen Sudah Diunggah:</strong>
                            <div class="mt-1">
                                <span class="badge bg-white text-primary border me-1"><i class="bi bi-file-earmark-pdf me-1"></i><?= e($existing['cv_filename']) ?></span>
                                <span class="badge bg-white text-primary border"><i class="bi bi-file-earmark-text me-1"></i><?= e($existing['lamaran_filename']) ?></span>
                            </div>
                            <span class="text-muted d-block mt-2 fs-8">*Mengunggah ulang di bawah ini akan menggantikan berkas yang ada.</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="post" enctype="multipart/form-data">
                
                <!-- Input CV -->
                <div class="mb-4">
                    <label class="form-label fw-bold text-dark fs-7 mb-2">
                        1. CV (Curriculum Vitae) <span class="text-danger">*</span>
                    </label>
                    <div class="dropzone-box" id="dropzone-cv">
                        <div class="dropzone-icon">
                            <i class="bi bi-person-vcard"></i>
                        </div>
                        <h6 class="fw-semibold text-dark mb-1 fs-7">Klik atau seret file CV ke sini</h6>
                        <p class="text-muted small mb-0 fs-8">PDF, DOC, atau DOCX (Maks. 5 MB)</p>
                        <input type="file" name="cv" id="input-cv" class="file-input-hidden" accept=".pdf,.doc,.docx" required>
                    </div>
                    <div id="file-info-cv" class="selected-file-info d-none">
                        <span><i class="bi bi-file-earmark-check text-success me-2"></i><span id="filename-cv"></span></span>
                        <i class="bi bi-x-circle text-danger cursor-pointer" onclick="resetInput('cv')"></i>
                    </div>
                </div>

                <!-- Input Form Lamaran -->
                <div class="mb-4">
                    <label class="form-label fw-bold text-dark fs-7 mb-2">
                        2. Form Lamaran <span class="text-danger">*</span>
                    </label>
                    <div class="dropzone-box" id="dropzone-lamaran">
                        <div class="dropzone-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                        <h6 class="fw-semibold text-dark mb-1 fs-7">Klik atau seret Form Lamaran ke sini</h6>
                        <p class="text-muted small mb-0 fs-8">PDF, DOC, atau DOCX (Maks. 5 MB)</p>
                        <input type="file" name="form_lamaran" id="input-lamaran" class="file-input-hidden" accept=".pdf,.doc,.docx" required>
                    </div>
                    <div id="file-info-lamaran" class="selected-file-info d-none">
                        <span><i class="bi bi-file-earmark-check text-success me-2"></i><span id="filename-lamaran"></span></span>
                        <i class="bi bi-x-circle text-danger cursor-pointer" onclick="resetInput('lamaran')"></i>
                    </div>
                </div>

                <!-- Action Button -->
                <button type="submit" class="btn btn-submit-modern w-100 mt-2">
                    <i class="bi bi-cloud-arrow-up-fill me-2"></i> Unggah &amp; Lanjutkan
                </button>
            </form>

        </div>
    </div>
</div>

<script>
    // Fungsi untuk memperbarui tampilan ketika file dipilih
    function setupFileInput(inputId, infoId, nameId) {
        const input = document.getElementById(inputId);
        const infoBox = document.getElementById(infoId);
        const nameSpan = document.getElementById(nameId);

        input.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                nameSpan.textContent = this.files[0].name;
                infoBox.classList.remove('d-none');
            } else {
                infoBox.classList.add('d-none');
            }
        });
    }

    function resetInput(type) {
        const input = document.getElementById(`input-${type}`);
        const infoBox = document.getElementById(`file-info-${type}`);
        input.value = '';
        infoBox.classList.add('d-none');
    }

    setupFileInput('input-cv', 'file-info-cv', 'filename-cv');
    setupFileInput('input-lamaran', 'file-info-lamaran', 'filename-lamaran');
</script>

<!-- SweetAlert2 CDN (jika belum dimasukkan di layout_top.php) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if (!empty($_SESSION['info_upload_required'])): ?>
<?php unset($_SESSION['info_upload_required']); // Hapus session agar tidak muncul berulang saat reload ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        title: 'Perhatian!',
        text: 'Anda wajib mengunggah CV dan Form Lamaran terlebih dahulu sebelum dapat mengerjakan modul psikotes.',
        icon: 'info',
        confirmButtonText: 'Saya Mengerti',
        confirmButtonColor: '#0f172a',
        customClass: {
            popup: 'rounded-4'
        }
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>