<?php
// Simpan file ini sebagai: includes/profil_page.php
// File ini di-require dari admin/profil.php dan peserta/profil.php
// setelah requireRole() dipanggil, jadi $activeMenu = 'profil' sudah di-set duluan.

$user = currentUser();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama            = trim($_POST['nama'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $passwordBaru    = trim($_POST['password_baru'] ?? '');
    $passwordUlang   = trim($_POST['password_ulang'] ?? '');
    $passwordSaatIni = trim($_POST['password_saat_ini'] ?? '');

    if ($passwordBaru !== '' && $passwordBaru !== $passwordUlang) {
        $errors[] = 'Konfirmasi password baru tidak cocok.';
    } else {
        $hasil = updateProfilSendiri(
            (int)$_SESSION['user_id'],
            $nama,
            $email,
            $passwordBaru,
            $passwordSaatIni
        );

        if ($hasil['success']) {
            $success = true;
            $user = currentUser(); // refresh data setelah update
        } else {
            $errors = $hasil['errors'];
        }
    }
}

$pageTitle = 'Profil Saya';
$pageDesc  = 'Kelola informasi akun dan keamanan login Anda';
include __DIR__ . '/layout_top.php';
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

    /* Card Entrance Animation */
    .card-anim {
        animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    /* Interactive Avatar */
    .avatar-interactive {
        width: 76px;
        height: 76px;
        font-size: 1.85rem;
        background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
        color: #fff;
        border-radius: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
        box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
    }

    .avatar-interactive:hover {
        transform: scale(1.08) rotate(-3deg);
        box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4);
    }

    /* Form Controls & Inputs */
    .form-control-modern {
        border-color: #cbd5e1;
        transition: all 0.2s ease;
    }

    .form-control-modern:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3.5px rgba(99, 102, 241, 0.15);
    }

    .input-group-text-modern {
        transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .input-group:focus-within .input-group-text-modern {
        background-color: #e0e7ff !important;
        color: #4f46e5 !important;
        border-color: #6366f1;
    }

    /* Action Buttons Micro-interactions */
    .btn-action {
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(79, 70, 229, 0.25) !important;
    }

    /* Section Cards Focus Styling */
    .security-box {
        transition: background-color 0.2s ease, border-color 0.2s ease;
    }

    .security-box:focus-within {
        background-color: #ffffff !important;
        border-color: #a5b4fc !important;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.08);
    }
</style>

<div class="row justify-content-center my-3 my-md-4">
    <div class="col-12 col-md-10 col-lg-8 col-xl-7">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white card-anim">
            
            <!-- Header Card / Banner Profil -->
            <div class="p-4 p-sm-5 bg-light border-bottom position-relative">
                <div class="d-flex flex-column flex-sm-row align-items-center align-items-sm-start text-center text-sm-start gap-3 gap-sm-4">
                    <!-- Avatar dengan Ring Shadow & Hover Animation -->
                    <div class="avatar-interactive flex-shrink-0">
                        <?= e(strtoupper(substr($user['nama'], 0, 1))) ?>
                    </div>
                    <!-- Meta User -->
                    <div class="pt-1">
                        <h4 class="fw-bold text-dark mb-1 fs-5 fs-sm-4"><?= e($user['nama']) ?></h4>
                        <p class="text-muted small mb-2"><?= e($user['email']) ?></p>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-2 fw-medium text-capitalize" style="font-size: 0.75rem; letter-spacing: 0.3px;">
                            <i class="bi bi-person-badge me-1"></i><?= e($_SESSION['role']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Body Card / Form -->
            <div class="card-body p-4 p-sm-5">

                <!-- Alert Sukses -->
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4 card-anim" role="alert" style="font-size: 0.875rem;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-check-circle-fill me-2 fs-5 text-success"></i>
                            <div><strong>Berhasil!</strong> Profil Anda telah diperbarui.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Alert Error -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4 card-anim" role="alert" style="font-size: 0.875rem;">
                        <div class="d-flex align-items-start">
                            <i class="bi bi-exclamation-triangle-fill me-2 fs-5 text-danger mt-1"></i>
                            <div>
                                <strong>Gagal memperbarui profil:</strong>
                                <ul class="mb-0 ps-3 mt-1">
                                    <?php foreach ($errors as $err): ?>
                                        <li><?= e($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    
                    <!-- Section 1: Informasi Pribadi -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark mb-3 text-uppercase fs-7 text-secondary" style="letter-spacing: 0.8px; font-size: 0.75rem;">Informasi Dasar</h6>
                        
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label small fw-semibold text-dark mb-1">Nama Lengkap</label>
                                <div class="input-group">
                                    <span class="input-group-text input-group-text-modern bg-light border-end-0 rounded-start-3 text-muted"><i class="bi bi-person"></i></span>
                                    <input type="text" name="nama" class="form-control form-control-modern border-start-0 rounded-end-3 py-2" required
                                           value="<?= e($_POST['nama'] ?? $user['nama']) ?>" placeholder="Masukkan nama lengkap">
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-semibold text-dark mb-1">Alamat Email</label>
                                <div class="input-group">
                                    <span class="input-group-text input-group-text-modern bg-light border-end-0 rounded-start-3 text-muted"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" class="form-control form-control-modern border-start-0 rounded-end-3 py-2" required
                                           value="<?= e($_POST['email'] ?? $user['email']) ?>" placeholder="nama@email.com">
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4 text-secondary opacity-25">

                    <!-- Section 2: Keamanan Akun -->
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="fw-bold text-dark mb-0 text-uppercase fs-7 text-secondary" style="letter-spacing: 0.8px; font-size: 0.75rem;">Ganti Password</h6>
                            <span class="badge bg-light text-muted border font-monospace fw-normal" style="font-size: 0.7rem;">Opsional</span>
                        </div>
                        <p class="text-muted small mb-3">
                            Biarkan bidang password baru kosong jika tidak ingin mengubahnya.
                        </p>

                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold text-dark mb-1">Password Baru</label>
                                <div class="input-group">
                                    <span class="input-group-text input-group-text-modern bg-light border-end-0 rounded-start-3 text-muted"><i class="bi bi-key"></i></span>
                                    <input type="password" name="password_baru" class="form-control form-control-modern border-start-0 rounded-end-3 py-2" placeholder="Min. 6 karakter">
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold text-dark mb-1">Ulangi Password Baru</label>
                                <div class="input-group">
                                    <span class="input-group-text input-group-text-modern bg-light border-end-0 rounded-start-3 text-muted"><i class="bi bi-key-fill"></i></span>
                                    <input type="password" name="password_ulang" class="form-control form-control-modern border-start-0 rounded-end-3 py-2" placeholder="Ulangi password baru">
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4 text-secondary opacity-25">

                    <!-- Section 3: Konfirmasi Keamanan -->
                    <div class="mb-4 p-3 bg-light rounded-3 border security-box">
                        <label class="form-label small fw-bold text-dark mb-1">
                            Password Saat Ini <span class="text-danger">*</span>
                        </label>
                        <p class="text-muted extra-small mb-2" style="font-size: 0.8rem;">Diperlukan untuk memverifikasi bahwa ini memang Anda.</p>
                        <div class="input-group">
                            <span class="input-group-text input-group-text-modern bg-white border-end-0 rounded-start-3 text-muted"><i class="bi bi-shield-lock"></i></span>
                            <input type="password" name="password_saat_ini" class="form-control form-control-modern border-start-0 rounded-end-3 py-2" required
                                   placeholder="Masukkan password Anda saat ini">
                        </div>
                    </div>

                    <!-- Tombol Action -->
                    <div class="d-grid d-sm-flex justify-content-sm-end pt-2">
                        <button type="submit" class="btn btn-primary rounded-3 px-4 py-2 fw-semibold shadow-sm d-inline-flex align-items-center justify-content-center gap-2 btn-action">
                            <i class="bi bi-check2-circle fs-5"></i> Simpan Perubahan
                        </button>
                    </div>

                </form>

            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_bottom.php'; ?>