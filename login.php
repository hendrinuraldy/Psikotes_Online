<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    $tujuan = $_SESSION['role'] === 'admin' ? 'admin/dashboard.php' : 'peserta/dashboard.php';
    ?>
    <script>
    var ok = false;
    try { ok = !!sessionStorage.getItem('tab_ok'); } catch (e) {}
    location.replace(ok ? '<?= $tujuan ?>' : 'logout.php');
    </script>
    <?php
    exit;
}

$error = null;
$warning = null;

if (isset($_GET['gugur'])) {
    $error = 'Masa aktif akun Anda telah berakhir sehingga akun habis waktu pengerjaan. Hubungi HRD';
} elseif (isset($_GET['timeout'])) {
    $warning = 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan login kembali.';
} elseif (isset($_GET['belum_aktif']) && (int)($_GET['mulai'] ?? 0) > 0) {
    $warning = 'Akun Anda belum aktif untuk mengikuti psikotes. Akun akan aktif pada '
        . formatTanggal(date('Y-m-d H:i:s', (int)$_GET['mulai'])) . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email dan password wajib diisi.';
    } else {
        $stmt = getDB()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Email atau password salah.';
        } elseif ($user['status'] === 'nonaktif') {
            $error = 'Akun Anda dinonaktifkan. Hubungi admin.';
        } elseif (tandaiGugurJikaPerlu($user)) {
            $error = 'Masa aktif akun Anda telah berakhir sehingga akun habis waktu pengerjaan. Hubungi HRD';
        } elseif (isAkunBelumAktif($user)) {
            $warning = 'Akun Anda belum aktif untuk mengikuti psikotes. Akun akan aktif pada '
                . formatTanggal($user['aktif_mulai']) . '. Silakan login kembali pada waktu tersebut.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['nama'] = $user['nama'];
            $_SESSION['last_activity'] = time();
            $_SESSION['baru_login'] = true;

            // Tandai ONLINE begitu login (dipantau di admin/peserta.php)
            try {
                getDB()->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([$user['id']]);
            } catch (Throwable $e) {
                error_log('Gagal set last_seen: ' . $e->getMessage());
            }

            // Catat ke riwayat akses (jam masuk)
            catatLogin((int)$user['id']);

            $_SESSION['show_login_notice'] = true;

            header('Location: ' . ($user['role'] === 'admin' ? 'admin/dashboard.php' : 'peserta/dashboard.php'));
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
    <title>Masuk - Platform Psikotes Online</title>
      <link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>/psikotes.ico?v=2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <style>
        :root {
            --brand-primary: #2563eb;
            --brand-soft: #eff6ff;
            --text-title: #0f172a;
            --text-body: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-page: #f8fafc;
        }
    
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-page);
            color: var(--text-title);
            min-height: 100vh;
            margin: 0;
        }

        .auth-wrapper {
            min-height: 100vh;
            display: flex;
        }

        /* Banner Kiri (Tema Calming Soft Gradient) */
        .auth-banner {
            flex: 1.1;
            background: linear-gradient(135deg, #e0f2fe 0%, #e0e7ff 50%, #f0f9ff 100%);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 60px;
            color: var(--text-title);
            overflow: hidden;
        }

        /* Elemen Dekoratif Halus (Bukan Orb Gelap) */
        .auth-banner::before {
            content: '';
            position: absolute;
            top: -10%;
            right: -10%;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }

        .auth-banner::after {
            content: '';
            position: absolute;
            bottom: -10%;
            left: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.12) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }

        .auth-banner-content {
            position: relative;
            z-index: 2;
            max-width: 480px;
            margin: auto 0;
            animation: fadeIn 0.8s ease;
        }

        .badge-welcome {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--brand-primary);
            margin-bottom: 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }

        .auth-banner-title {
            font-size: 2.3rem;
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: -0.02em;
            color: #1e293b;
            margin-bottom: 1rem;
        }

        .auth-banner-text {
            color: var(--text-body);
            font-size: 1.05rem;
            line-height: 1.6;
        }

        /* Kartu Tips Menenangkan untuk Peserta */
        .tips-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            padding: 20px;
            margin-top: 32px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .tips-card i {
            font-size: 1.4rem;
            color: var(--brand-primary);
            margin-top: 2px;
        }

        .tips-card-text {
            font-size: 0.875rem;
            color: var(--text-body);
            margin: 0;
            line-height: 1.5;
        }

        /* Section Form Kanan */
        .auth-main {
            width: 100%;
            max-width: 520px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 32px;
            background: #ffffff;
            box-shadow: -4px 0 24px rgba(0, 0, 0, 0.02);
        }

        .auth-card {
            width: 100%;
            max-width: 380px;
            animation: fadeIn 0.8s ease;
        }

        .auth-logo {
            margin-bottom: 28px;
        }

        .auth-logo img {
            max-height: 44px;
            width: auto;
            object-fit: contain;
        }

        .auth-header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-title);
            margin-bottom: 6px;
            letter-spacing: -0.01em;
        }

        .auth-header p {
            color: var(--text-muted);
            font-size: 0.925rem;
            margin-bottom: 28px;
        }

        /* Form Control */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-title);
            margin-bottom: 8px;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            color: #94a3b8;
            font-size: 1.05rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .form-control-custom {
            width: 100%;
            padding: 13px 16px 13px 44px;
            font-size: 0.925rem;
            font-weight: 500;
            color: var(--text-title);
            background-color: #f8fafc;
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .form-control-custom:focus {
            outline: none;
            background-color: #ffffff;
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
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
            padding: 4px;
            font-size: 1.05rem;
        }

        .btn-toggle-password:hover {
            color: var(--text-title);
        }

        /* Alert */
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

        .alert-custom-success {
            background-color: #f0fdf4;
            border: 1px solid #dcfce7;
            color: #16a34a;
        }

        /* Alert kuning: akun belum aktif (jadwal aktif belum tiba) */
        .alert-custom-warning {
            background-color: #fffbeb;
            border: 1px solid #fde68a;
            color: #b45309;
        }

        /* Button Ramah (Friendly Blue) */
        .btn-submit {
            width: 100%;
            padding: 13px;
            background-color: var(--brand-primary);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background-color: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        /* Footer */
        .auth-footer {
            margin-top: 32px;
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
    <!-- Banner Kiri: Cerah, Ramah & Menenangkan -->
    <div class="auth-banner">
        <div>
            <div class="badge-welcome">
                <i class="bi bi-emoji-smile"></i> Siapkan Diri Anda
            </div>
        </div>

        <div class="auth-banner-content">
            <h2 class="auth-banner-title">Selamat Datang di Psikotes Online.</h2>
            <p class="auth-banner-text">Jalani sesi asesmen dengan tenang dan nyaman. Kerjakan setiap soal sesuai dengan kemampuan terbaik Anda.</p>

            <div class="tips-card">
                <i class="bi bi-lightbulb"></i>
                <p class="tips-card-text">
                    <strong>Tips Persiapan:</strong> Pastikan Anda berada di ruangan yang tenang dan koneksi internet Anda stabil sebelum memulai ujian.
                </p>
            </div>
        </div>

        <div class="text-muted small" style="position: relative; z-index: 2;">
            &copy; <?= date('Y') ?> Psikotes Online System.
        </div>
    </div>

    <!-- Section Form Kanan -->
    <div class="auth-main">
        <div class="auth-card">
            
            <div class="auth-logo">
                <img src="assets/Logo SSKtama Fiks Revisi (1).png" alt="Logo Perusahaan">
            </div>

            <div class="auth-header">
                <h1>Masuk ke Akun</h1>
                <p>Silakan gunakan email dan kata sandi yang telah diberikan.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-custom alert-custom-danger" role="alert">
                    <i class="bi bi-exclamation-circle-fill fs-5"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($warning): ?>
                <div class="alert-custom alert-custom-warning" role="alert">
                    <i class="bi bi-clock-history fs-5"></i>
                    <div><?= e($warning) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($sukses = flash('success')): ?>
                <div class="alert-custom alert-custom-success" role="alert">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div><?= e($sukses) ?></div>
                </div>
            <?php endif; ?>
            
            <form method="POST" autocomplete="off" novalidate>
                <div class="form-group">
                    <label class="form-label">Alamat Email</label>
                    <div class="input-group-custom">
                        <input type="email" name="email" class="form-control-custom" placeholder="nama@email.com" value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
                        <i class="bi bi-envelope input-icon"></i>
                    </div>
                </div>

                <div class="form-group mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label mb-0">Kata Sandi</label>
                        <a href="lupa_password.php" class="small text-decoration-none" style="color: var(--brand-primary); font-weight: 600;">Lupa kata sandi?</a>
                    </div>
                    <div class="input-group-custom">
                        <input type="password" name="password" id="passwordInput" class="form-control-custom" placeholder="••••••••" required>
                        <i class="bi bi-lock input-icon"></i>
                        <button type="button" class="btn-toggle-password" id="togglePassword" aria-label="Tampilkan Password">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Masuk</span>
                    <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <div class="auth-footer">
                Belum memiliki akses atau kendala login? <br>
                <p href="#" class="text-decoration-none" style="color: var(--brand-primary); font-weight: 600;">Hubungi HRD</p>
            </div>
        </div>
    </div>
</div>

<script>
    // Bersihkan penanda tab lama (belum login = tidak ada tab sah)
    try { sessionStorage.removeItem('tab_ok'); } catch (e) {}

    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('passwordInput');
    const eyeIcon = document.getElementById('eyeIcon');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            eyeIcon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    }
</script>
</body>
</html>