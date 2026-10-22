<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin/dashboard.php' : 'peserta/dashboard.php'));
    exit;
}

$error = null;
$terkirim = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Masukkan alamat email yang valid.';
    } else {
        $token = generateResetToken($email);
        if ($token !== null) {
            $stmt = getDB()->prepare("SELECT nama FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $nama = $stmt->fetch()['nama'] ?? '';
            sendResetPasswordEmail($email, $nama, $token);
        }
        $terkirim = true;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - Psikotes Online</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        :root {
            --brand-blue: #2563eb;
            --brand-blue-hover: #1d4ed8;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-color: #cbd5e1;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #ffffff;
            color: var(--text-dark);
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
        }

        .auth-wrapper {
            min-height: 100vh;
            display: flex;
        }

        /* Banner Sisi Kiri - Light Blue Gradient */
        .auth-banner {
            flex: 1.3;
            background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 50%, #eff6ff 100%);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 60px 80px;
            color: var(--text-dark);
        }

        /* Badge Siapkan Diri Anda */
        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(8px);
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.825rem;
            font-weight: 600;
            color: #3b82f6;
            border: 1px solid rgba(255, 255, 255, 0.8);
            width: fit-content;
            animation: pulseBadge 3s infinite ease-in-out;
        }

        .auth-banner-content {
            position: relative;
            z-index: 2;
            max-width: 500px;
            margin: auto 0;
            animation: fadeIn 0.8s ease-out forwards;
        }

        .auth-banner-title {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.02em;
            margin-bottom: 1.25rem;
            color: #0f172a;
        }

        .auth-banner-text {
            color: #475569;
            font-size: 1.05rem;
            line-height: 1.6;
            margin-bottom: 32px;
        }

        /* Card Tips Persiapan */
        .tips-card {
            background: rgba(255, 255, 255, 0.65);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 16px;
            padding: 16px 20px;
            display: flex;
            gap: 14px;
            align-items: flex-start;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .tips-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.08);
        }

        .tips-card i {
            font-size: 1.25rem;
            color: #2563eb;
            margin-top: 2px;
        }

        .tips-card p {
            margin: 0;
            font-size: 0.85rem;
            color: #334155;
            line-height: 1.5;
        }

        .tips-card p strong {
            color: #0f172a;
        }

        .auth-banner-footer {
            color: #94a3b8;
            font-size: 0.85rem;
        }

        /* Sisi Kanan (Form) */
        .auth-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 60px;
            background: #ffffff;
        }

        .auth-card {
            width: 100%;
            max-width: 400px;
        }

        /* Logo SSKTama Card */
        .auth-logo {
            margin-bottom: 32px;
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .auth-logo img {
            height: 40px;
            width: auto;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 4px 8px;
        }

        .auth-header {
            animation: fadeInUp 0.6s ease-out 0.1s forwards;
            opacity: 0;
        }

        .auth-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.02em;
            margin-bottom: 8px;
        }

        .auth-header p {
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 28px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
            display: block;
        }

        /* Input Custom Style */
        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            color: #64748b;
            font-size: 1.1rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .form-control-custom {
            width: 100%;
            padding: 12px 16px 12px 42px;
            font-size: 0.925rem;
            font-weight: 500;
            color: var(--text-dark);
            background-color: #ffffff;
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-control-custom:focus {
            outline: none;
            border-color: var(--brand-blue);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .form-control-custom:focus + .input-icon {
            color: var(--brand-blue);
        }

        /* Animated Form Items */
        .auth-form-body {
            animation: fadeInUp 0.6s ease-out 0.2s forwards;
            opacity: 0;
        }

        /* Tombol Biru Solid + Arrow */
        .btn-submit {
            width: 100%;
            padding: 13px 20px;
            background-color: var(--brand-blue);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit .btn-arrow {
            transition: transform 0.2s ease;
        }

        .btn-submit:hover {
            background-color: var(--brand-blue-hover);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.35);
            transform: translateY(-1px);
        }

        .btn-submit:hover .btn-arrow {
            transform: translateX(4px);
        }

        .btn-submit:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
        }

        /* Alert Styling */
        .alert-custom {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 24px;
            animation: fadeInUp 0.4s ease-out forwards;
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

        .auth-footer {
            margin-top: 28px;
            text-align: center;
            animation: fadeInUp 0.6s ease-out 0.3s forwards;
            opacity: 0;
        }

        .auth-footer a {
            color: var(--brand-blue);
            font-weight: 700;
            font-size: 0.875rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .auth-footer a .back-arrow {
            transition: transform 0.2s ease;
        }

        .auth-footer a:hover {
            color: var(--brand-blue-hover);
        }

        .auth-footer a:hover .back-arrow {
            transform: translateX(-4px);
        }

        /* Keyframe Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes pulseBadge {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.03);
            }
        }

        @media (max-width: 991.98px) {
            .auth-banner {
                display: none;
            }
            .auth-main {
                flex: 1;
                padding: 40px 20px;
            }
        }
    </style>
</head>
<body>

<div class="auth-wrapper">
    <!-- Banner Kiri (Sama Seperti Halaman Login) -->
    <div class="auth-banner">
        <div>
            <div class="badge-pill">
                <i class="bi bi-emoji-smile"></i> Siapkan Diri Anda
            </div>
        </div>
        
        <div class="auth-banner-content">
            <h1 class="auth-banner-title">Selamat Datang di Psikotes Online.</h1>
            <p class="auth-banner-text">Jalani sesi asesmen dengan tenang dan nyaman. Kerjakan setiap soal sesuai dengan kemampuan terbaik Anda.</p>

            <div class="tips-card">
                <i class="bi bi-lightbulb"></i>
                <p><strong>Tips Persiapan:</strong> Pastikan Anda berada di ruangan yang tenang dan koneksi internet Anda stabil sebelum memulai ujian.</p>
            </div>
        </div>

        <div class="auth-banner-footer">
            &copy; <?= date('Y') ?> Psikotes Online System.
        </div>
    </div>

    <!-- Form Kanan (Lupa Password) -->
    <div class="auth-main">
        <div class="auth-card">

            <div class="auth-logo">
                <img src="assets/Logo SSKtama Fiks Revisi (1).png" alt="SSKTama Logo">
            </div>

            <div class="auth-header">
                <h1>Lupa Password</h1>
                <p>Masukkan email akun Anda, kami akan kirimkan link untuk membuat password baru.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-custom alert-custom-danger" role="alert">
                    <i class="bi bi-exclamation-circle-fill mt-1"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($terkirim): ?>
                <div class="alert-custom alert-custom-success" role="alert">
                    <i class="bi bi-check-circle-fill mt-1"></i>
                    <div>Jika email terdaftar, kami telah mengirimkan link reset password. Silakan cek inbox atau folder spam Anda.</div>
                </div>
            <?php endif; ?>

            <?php if (!$terkirim): ?>
            <div class="auth-form-body">
                <form method="POST" autocomplete="off" novalidate>
                    <div class="mb-4">
                        <label class="form-label">Alamat Email</label>
                        <div class="input-group-custom">
                            <input type="email" name="email" class="form-control-custom" placeholder="nama@email.com" value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
                            <i class="bi bi-envelope input-icon"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <span>Kirim Link Reset</span>
                        <span class="btn-arrow">&rarr;</span>
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="login.php"><span class="back-arrow">&larr;</span> Kembali ke Halaman Masuk</a>
            </div>

        </div>
    </div>
</div>

</body>
</html>