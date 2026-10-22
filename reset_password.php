<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin/dashboard.php' : 'peserta/dashboard.php'));
    exit;
}

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$error = null;
$user  = $token !== '' ? validateResetToken($token) : false;

if ($token === '' || !$user) {
    $tokenInvalid = true;
} else {
    $tokenInvalid = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password        = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password minimal 8 karakter.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Konfirmasi password tidak sama.';
        } else {
            resetPassword((int)$user['id'], $password);
            flash('success', 'Password berhasil diubah. Silakan masuk dengan password baru Anda.');
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Psikotes Online</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        :root {
            --brand-primary: #3b82f6;
            --brand-primary-hover: #2563eb;
            --dark-surface: #0f172a;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-light: #f8fafc;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-main);
            min-height: 100vh;
            margin: 0;
        }

        .auth-wrapper {
            min-height: 100vh;
            display: flex;
        }

        .auth-banner {
            flex: 1;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #1e1b4b 100%);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 60px;
            color: #ffffff;
            overflow: hidden;
        }

        .auth-banner::before {
            content: '';
            position: absolute;
            top: -20%;
            left: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.25) 0%, rgba(0,0,0,0) 70%);
            border-radius: 50%;
        }

        .auth-banner-content {
            position: relative;
            z-index: 2;
            max-width: 480px;
            margin: auto 0;
        }

        .auth-banner-title {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
        }

        .auth-banner-text {
            color: #94a3b8;
            font-size: 1.05rem;
            line-height: 1.6;
        }

        .auth-main {
            width: 100%;
            max-width: 540px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            background: #ffffff;
        }

        .auth-card {
            width: 100%;
            max-width: 400px;
        }

        .auth-logo {
            margin-bottom: 32px;
        }

        .auth-logo img {
            max-height: 48px;
            width: auto;
            object-fit: contain;
        }

        .auth-header h1 {
            font-size: 1.65rem;
            font-weight: 700;
            color: var(--dark-surface);
            letter-spacing: -0.01em;
            margin-bottom: 6px;
        }

        .auth-header p {
            color: var(--text-muted);
            font-size: 0.925rem;
            margin-bottom: 32px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--dark-surface);
            margin-bottom: 8px;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            font-size: 1.1rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .form-control-custom {
            width: 100%;
            padding: 12px 16px 12px 42px;
            font-size: 0.925rem;
            font-weight: 500;
            color: var(--dark-surface);
            background-color: #f8fafc;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            transition: all 0.2s ease-in-out;
        }

        .form-control-custom:focus {
            outline: none;
            background-color: #ffffff;
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
        }

        .form-control-custom:focus + .input-icon {
            color: var(--brand-primary);
        }

        .btn-toggle-password {
            position: absolute;
            right: 14px;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            font-size: 1.1rem;
        }

        .btn-toggle-password:hover {
            color: var(--dark-surface);
        }

        .alert-custom {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
        }

        .alert-custom-danger {
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            color: #dc2626;
        }

        .btn-submit {
            width: 100%;
            padding: 13px;
            background-color: var(--dark-surface);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .btn-submit:hover {
            background-color: var(--brand-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.25);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .auth-footer {
            margin-top: 32px;
            text-align: center;
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .auth-footer a {
            color: var(--brand-primary);
            font-weight: 600;
            text-decoration: none;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 991.98px) {
            .auth-banner {
                display: none;
            }
            .auth-main {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-banner">
        <div></div>
        <div class="auth-banner-content">
            <h2 class="auth-banner-title">Evaluasi Potensi dengan Presisi.</h2>
            <p class="auth-banner-text">Selamat datang di Platform Psikotes Online. Jalani asesmen secara terstruktur, cepat, dan terintegrasi.</p>
        </div>
        <div class="text-white-50 small">
            &copy; <?= date('Y') ?> Psikotes Online System. All rights reserved.
        </div>
    </div>

    <div class="auth-main">
        <div class="auth-card">

            <div class="auth-logo">
                <img src="assets/Logo SSKtama Fiks Revisi (1).png" alt="Logo Perusahaan">
            </div>

            <?php if ($tokenInvalid): ?>

                <div class="auth-header">
                    <h1>Link Tidak Valid</h1>
                    <p>Link reset password sudah kedaluwarsa atau tidak valid.</p>
                </div>

                <div class="alert-custom alert-custom-danger" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <div>Silakan minta link reset password baru.</div>
                </div>

                <a href="lupa_password.php" class="btn-submit d-block text-center text-decoration-none" style="line-height: 1.2; padding-top: 13px;">
                    Minta Link Baru <i class="bi bi-arrow-right ms-1"></i>
                </a>

            <?php else: ?>

                <div class="auth-header">
                    <h1>Buat Password Baru</h1>
                    <p>Halo <?= e($user['nama']) ?>, silakan masukkan password baru Anda.</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert-custom alert-custom-danger" role="alert">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off" novalidate>
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="form-group">
                        <label class="form-label">Password Baru</label>
                        <div class="input-group-custom">
                            <input type="password" name="password" id="passwordInput" class="form-control-custom" placeholder="Minimal 8 karakter" required minlength="8">
                            <i class="bi bi-lock input-icon"></i>
                            <button type="button" class="btn-toggle-password" id="togglePassword" aria-label="Tampilkan Password">
                                <i class="bi bi-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">Konfirmasi Password Baru</label>
                        <div class="input-group-custom">
                            <input type="password" name="password_confirm" id="passwordConfirmInput" class="form-control-custom" placeholder="Ulangi password baru" required minlength="8">
                            <i class="bi bi-lock input-icon"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        Simpan Password Baru <i class="bi bi-check-lg ms-1"></i>
                    </button>
                </form>

            <?php endif; ?>

            <div class="auth-footer">
                <a href="login.php"><i class="bi bi-arrow-left me-1"></i>Kembali ke Halaman Masuk</a>
            </div>

        </div>
    </div>
</div>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('passwordInput');
    const eyeIcon = document.getElementById('eyeIcon');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            eyeIcon.classList.toggle('bi-eye');
            eyeIcon.classList.toggle('bi-eye-slash');
        });
    }
</script>
</body>
</html>