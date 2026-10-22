<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

/* ---------------------------------------------------------
 * LOAD PHPMAILER (MANUAL DARI FOLDER INCLUDES/PHPMAILER)
 * ------------------------------------------------------- */
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (file_exists(__DIR__ . '/../config/mail.php')) {
    require_once __DIR__ . '/../config/mail.php';
}

if (!defined('SMTP_HOST'))       define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_USERNAME'))   define('SMTP_USERNAME', 'emailkamu@gmail.com');
if (!defined('SMTP_PASSWORD'))   define('SMTP_PASSWORD', 'xxxx xxxx xxxx xxxx');
if (!defined('SMTP_SECURE'))     define('SMTP_SECURE', PHPMailer::ENCRYPTION_STARTTLS);
if (!defined('SMTP_PORT'))       define('SMTP_PORT', 587);
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', 'emailkamu@gmail.com');
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME', 'Psikotes Online');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Batas idle (detik). Ping tiap 30 dtk; 180 dtk memberi ruang untuk tab background.
if (!defined('IDLE_LIMIT')) define('IDLE_LIMIT', 180);

/* ---------------------------------------------------------
 * RIWAYAT AKSES (login / logout)
 * ------------------------------------------------------- */

/** Catat login baru. Riwayat lama user yang masih menggantung ditutup dulu. */
function catatLogin(int $userId): void {
    try {
        $db = getDB();

        // Tutup riwayat lama yang tidak pernah logout (tab/browser langsung ditutup)
        $db->prepare("
            UPDATE riwayat_akses
            SET waktu_logout = waktu_terakhir_aktif, alasan_logout = 'tab_ditutup'
            WHERE user_id = ? AND waktu_logout IS NULL
        ")->execute([$userId]);

        $db->prepare("
            INSERT INTO riwayat_akses (user_id, waktu_login, waktu_terakhir_aktif, ip_address, user_agent)
            VALUES (?, NOW(), NOW(), ?, ?)
        ")->execute([
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

        $_SESSION['riwayat_id'] = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('Gagal catat login: ' . $e->getMessage());
    }
}

/**
 * Catat waktu keluar. Panggil SEBELUM session_unset()/session_destroy().
 * $waktuTs (unix time) opsional: untuk timeout, jam keluar = aktivitas terakhir.
 */
function catatLogout(string $alasan = 'logout', ?int $waktuTs = null): void {
    if (empty($_SESSION['riwayat_id'])) return;
    try {
        $waktu = $waktuTs ? date('Y-m-d H:i:s', $waktuTs) : date('Y-m-d H:i:s');
        getDB()->prepare("
            UPDATE riwayat_akses
            SET waktu_logout = ?, alasan_logout = ?
            WHERE id = ? AND waktu_logout IS NULL
        ")->execute([$waktu, $alasan, $_SESSION['riwayat_id']]);
    } catch (Throwable $e) {
        error_log('Gagal catat logout: ' . $e->getMessage());
    }
}

/** Label alasan logout untuk tampilan. */
function labelAlasanLogout(?string $alasan): string {
    return [
        'logout'      => 'Logout manual',
        'timeout'     => 'Timeout (idle)',
        'gugur'       => 'Akun gugur',
        'belum_aktif' => 'Akun belum aktif',
        'tab_ditutup' => 'Tab/browser ditutup',
    ][$alasan ?? 'tab_ditutup'] ?? (string)$alasan;
}

/** Format durasi menit -> "1 j 25 m". */
function formatDurasi(?int $menit): string {
    if ($menit === null || $menit < 0) return '-';
    if ($menit < 1) return '< 1 m';
    $j = intdiv($menit, 60);
    $m = $menit % 60;
    return ($j > 0 ? $j . ' j ' : '') . $m . ' m';
}

/* ---------------------------------------------------------
 * AUTH HELPERS
 * ------------------------------------------------------- */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function touchLastSeen(): void {
    if (empty($_SESSION['user_id'])) return;
    $db = getDB();
    $db->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")
       ->execute([$_SESSION['user_id']]);

    if (!empty($_SESSION['riwayat_id'])) {
        try {
            $db->prepare("UPDATE riwayat_akses SET waktu_terakhir_aktif = NOW() WHERE id = ? AND waktu_logout IS NULL")
               ->execute([$_SESSION['riwayat_id']]);
        } catch (Throwable $e) {
            error_log('Gagal update riwayat aktif: ' . $e->getMessage());
        }
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $stmt = getDB()->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    }
    return $user;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . baseUrl('login.php'));
        exit;
    }

    // Putus sesi kalau tidak ada aktivitas/ping melebihi batas
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > IDLE_LIMIT) {
        catatLogout('timeout', (int)$_SESSION['last_activity']);
        session_unset();
        session_destroy();
        header('Location: ' . baseUrl('login.php?timeout=1'));
        exit;
    }
    $_SESSION['last_activity'] = $now;
}

function requireRole(string $role): void {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        header('Location: ' . baseUrl($_SESSION['role'] === 'admin' ? 'admin/dashboard.php' : 'peserta/dashboard.php'));
        exit;
    }

    if ($role === 'peserta') {
        $user = currentUser();

        if ($user && isAkunBelumAktif($user)) {
            $mulai = strtotime($user['aktif_mulai']);
            catatLogout('belum_aktif');
            session_unset();
            session_destroy();
            header('Location: ' . baseUrl('login.php?belum_aktif=1&mulai=' . $mulai));
            exit;
        }

        if ($user && tandaiGugurJikaPerlu($user)) {
            catatLogout('gugur');
            session_unset();
            session_destroy();
            header('Location: ' . baseUrl('login.php?gugur=1'));
            exit;
        }
    }
}

/* ---------------------------------------------------------
 * UTIL
 * ------------------------------------------------------- */
function baseUrl(string $path = ''): string {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(string $key, ?string $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

function formatTanggal(?string $datetime): string {
    if (!$datetime) return '-';
    $bulan = ['', 'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $ts = strtotime($datetime);
    return date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y H:i', $ts);
}

/** Format tanggal tanpa jam, dipakai untuk field seperti Tanggal Lahir. */
function formatTanggalSaja(?string $tanggal): string {
    if (!$tanggal) return '-';
    $bulan = ['', 'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $ts = strtotime($tanggal);
    return date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/* ---------------------------------------------------------
 * MASA AKTIF AKUN PESERTA & FITUR "GUGUR"
 * ------------------------------------------------------- */
function isAkunBelumAktif(array $user): bool {
    if (($user['role'] ?? '') !== 'peserta') return false;
    if (empty($user['aktif_mulai'])) return false;
    $mulai = strtotime($user['aktif_mulai']);
    return $mulai !== false && time() < $mulai;
}

function isAkunGugur(array $user): bool {
    if (($user['role'] ?? '') !== 'peserta') return false;
    if (($user['status'] ?? '') === 'gugur') return true;
    if (empty($user['aktif_sampai'])) return false;

    $sampai = strtotime($user['aktif_sampai']);
    return $sampai !== false && time() > $sampai;
}

function tandaiGugurJikaPerlu(array $user): bool {
    if (!isAkunGugur($user)) return false;

    if (($user['status'] ?? '') !== 'gugur') {
        try {
            $stmt = getDB()->prepare("UPDATE users SET status = 'gugur' WHERE id = ?");
            $stmt->execute([$user['id']]);
        } catch (PDOException $e) {
            error_log('Gagal menandai akun gugur (user id ' . $user['id'] . '): ' . $e->getMessage());
        }
    }
    return true;
}

function batasWaktuAkunTimestamp(array $user): ?int {
    if (empty($user['aktif_sampai'])) return null;
    $ts = strtotime($user['aktif_sampai']);
    return $ts === false ? null : $ts;
}

/* ---------------------------------------------------------
 * PROFIL AKUN SENDIRI
 * ------------------------------------------------------- */
function updateProfilSendiri(int $userId, string $nama, string $email, string $passwordBaru, string $passwordSaatIni): array {
    $errors = [];
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'errors' => ['Akun tidak ditemukan.']];
    }

    if ($passwordSaatIni === '' || !password_verify($passwordSaatIni, $user['password'])) {
        $errors[] = 'Password saat ini yang Anda masukkan salah.';
    }

    if ($nama === '') {
        $errors[] = 'Nama tidak boleh kosong.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    } else {
        $cek = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $cek->execute([$email, $userId]);
        if ($cek->fetch()) {
            $errors[] = 'Email tersebut sudah digunakan oleh akun lain.';
        }
    }

    if ($passwordBaru !== '' && strlen($passwordBaru) < 6) {
        $errors[] = 'Password baru minimal 6 karakter.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    if ($passwordBaru !== '') {
        $hash = password_hash($passwordBaru, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE users SET nama = ?, email = ?, password = ? WHERE id = ?");
        $upd->execute([$nama, $email, $hash, $userId]);
    } else {
        $upd = $db->prepare("UPDATE users SET nama = ?, email = ? WHERE id = ?");
        $upd->execute([$nama, $email, $userId]);
    }

    $_SESSION['nama'] = $nama;

    return ['success' => true, 'errors' => []];
}

/* ---------------------------------------------------------
 * DATA HELPERS
 * ------------------------------------------------------- */
function getAllPesertaWithStats(): array {
    $sql = "SELECT u.id, u.nama, u.email, u.status, u.created_at,
                   u.jabatan_dilamar, u.tanggal_lahir, u.aktif_mulai, u.aktif_sampai,
                   COUNT(DISTINCT a.id) AS total_tes_diambil,
                   SUM(CASE WHEN a.status = 'berlangsung' THEN 1 ELSE 0 END) AS sedang_berlangsung,
                   SUM(CASE WHEN a.status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                   MAX(a.waktu_mulai) AS aktivitas_terakhir
            FROM users u
            LEFT JOIN attempt a ON a.user_id = u.id
            WHERE u.role = 'peserta'
            GROUP BY u.id
            ORDER BY aktivitas_terakhir DESC, u.nama ASC";
    return getDB()->query($sql)->fetchAll();
}

function getAllAdminAccounts(): array {
    $sql = "SELECT id, nama, email, created_at
            FROM users
            WHERE role = 'admin'
            ORDER BY nama ASC";
    return getDB()->query($sql)->fetchAll();
}

/** Label status seleksi untuk tampilan / export. */
function labelStatusSeleksi(?string $v): string {
    return [
        'diterima'    => 'Diterima',
        'disimpan'    => 'Disimpan',
        'tidak_lolos' => 'Tidak Lolos',
    ][$v ?? ''] ?? 'Belum ditentukan';
}

/** Jabatan dilamar + status seleksi seorang peserta. */
function ambilInfoSeleksiPeserta(int $userId): array {
    $stmt = getDB()->prepare("SELECT jabatan_dilamar, status_seleksi FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: ['jabatan_dilamar' => null, 'status_seleksi' => null];
}

function getDashboardStats(): array {
    $db = getDB();
    $stats = [];
    $stats['total_peserta'] = (int)$db->query("SELECT COUNT(*) c FROM users WHERE role='peserta'")->fetch()['c'];
    $stats['total_tes'] = (int)$db->query("SELECT COUNT(*) c FROM tes")->fetch()['c'];
    $stats['sedang_berlangsung'] = (int)$db->query("SELECT COUNT(*) c FROM attempt WHERE status='berlangsung'")->fetch()['c'];
    $stats['selesai'] = (int)$db->query("SELECT COUNT(*) c FROM attempt WHERE status='selesai'")->fetch()['c'];
    return $stats;
}

/* ---------------------------------------------------------
 * PENILAIAN (logika + psikotes DISC/MBTI)
 * ------------------------------------------------------- */
function hitungSkorAttempt(int $attemptId): void {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT a.id, t.jenis, t.tipe_psikotes
        FROM attempt a
        JOIN tes t ON t.id = a.tes_id
        WHERE a.id = ?
    ");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
    if (!$attempt) return;

    if ($attempt['jenis'] === 'psikotes') {
        hitungProfilPsikotes($attemptId, $attempt['tipe_psikotes']);
        return;
    }

    $stmt = $db->prepare("
        SELECT j.opsi_id, o.is_benar
        FROM jawaban j
        JOIN opsi o ON o.id = j.opsi_id
        WHERE j.attempt_id = ?
    ");
    $stmt->execute([$attemptId]);
    $jawaban = $stmt->fetchAll();

    $totalBenar = 0;
    foreach ($jawaban as $j) {
        if ((int)$j['is_benar'] === 1) $totalBenar++;
    }

    $stmt = $db->prepare("SELECT total_soal FROM attempt WHERE id = ?");
    $stmt->execute([$attemptId]);
    $totalSoal = (int)$stmt->fetch()['total_soal'];

    $totalSkor = $totalSoal > 0 ? (int)round($totalBenar / $totalSoal * 100) : 0;

    $stmt = $db->prepare("UPDATE attempt SET total_skor = ?, total_benar = ?, profil_hasil = NULL WHERE id = ?");
    $stmt->execute([$totalSkor, $totalBenar, $attemptId]);
}

function hitungProfilPsikotes(int $attemptId, ?string $tipePsikotes): void {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT o.dimensi, COUNT(*) AS jumlah
        FROM jawaban j
        JOIN opsi o ON o.id = j.opsi_id
        WHERE j.attempt_id = ? AND o.dimensi IS NOT NULL
        GROUP BY o.dimensi
    ");
    $stmt->execute([$attemptId]);
    $tally = [];
    foreach ($stmt->fetchAll() as $row) {
        $tally[$row['dimensi']] = (int)$row['jumlah'];
    }

    $totalTerjawab = array_sum($tally);
    $profil = null;

    if ($tipePsikotes === 'disc') {
        $urutanTieBreak = ['D', 'I', 'S', 'C'];
        $maxJumlah = -1;
        foreach ($urutanTieBreak as $dim) {
            $j = $tally[$dim] ?? 0;
            if ($j > $maxJumlah) {
                $maxJumlah = $j;
                $profil = $dim;
            }
        }
    } elseif ($tipePsikotes === 'mbti') {
        $pasangan = [['E', 'I'], ['S', 'N'], ['T', 'F'], ['J', 'P']];
        $profil = '';
        foreach ($pasangan as [$a, $b]) {
            $jA = $tally[$a] ?? 0;
            $jB = $tally[$b] ?? 0;
            $profil .= ($jB > $jA) ? $b : $a;
        }
    }

    $stmt = $db->prepare("UPDATE attempt SET total_skor = ?, profil_hasil = ? WHERE id = ?");
    $stmt->execute([$totalTerjawab, $profil, $attemptId]);
}

function labelDimensi(?string $tipePsikotes, string $huruf): string {
    $labelDisc = [
        'D' => 'Dominance (Dominan, tegas, berorientasi hasil)',
        'I' => 'Influence (Persuasif, komunikatif, antusias)',
        'S' => 'Steadiness (Sabar, konsisten, suportif)',
        'C' => 'Conscientiousness (Teliti, sistematis, analitis)',
    ];
    $labelMbti = [
        'E' => 'Extraversion', 'I' => 'Introversion',
        'S' => 'Sensing', 'N' => 'Intuition',
        'T' => 'Thinking', 'F' => 'Feeling',
        'J' => 'Judging', 'P' => 'Perceiving',
    ];
    if ($tipePsikotes === 'disc') return $labelDisc[$huruf] ?? $huruf;
    if ($tipePsikotes === 'mbti') return $labelMbti[$huruf] ?? $huruf;
    return $huruf;
}

function selesaikanAttempt(int $attemptId): bool {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT a.*, t.id AS tes_id
        FROM attempt a
        JOIN tes t ON t.id = a.tes_id
        WHERE a.id = ? AND a.status = 'berlangsung'
    ");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
    if (!$attempt) return false;

    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM soal WHERE tes_id = ?");
    $stmt->execute([$attempt['tes_id']]);
    $totalSoal = (int)$stmt->fetch()['n'];

    $stmt = $db->prepare("UPDATE attempt SET status = 'selesai', waktu_selesai = NOW(), total_soal = ? WHERE id = ?");
    $stmt->execute([$totalSoal, $attemptId]);

    hitungSkorAttempt($attemptId);
    return true;
}

/* ---------------------------------------------------------
 * GRUP TES
 * ------------------------------------------------------- */
function tesGrupBerikutnya(PDO $db, int $userId, int $tesId): ?int {
    $stmt = $db->prepare("SELECT grup_id, urutan_dalam_grup FROM tes WHERE id = ?");
    $stmt->execute([$tesId]);
    $tes = $stmt->fetch();
    if (!$tes || !$tes['grup_id']) return null;

    $stmt = $db->prepare("
        SELECT t.id
        FROM tes t
        LEFT JOIN attempt a ON a.tes_id = t.id AND a.user_id = ? AND a.status = 'selesai'
        WHERE t.grup_id = ? AND t.urutan_dalam_grup > ? AND a.id IS NULL
        ORDER BY t.urutan_dalam_grup ASC
        LIMIT 1
    ");
    $stmt->execute([$userId, $tes['grup_id'], $tes['urutan_dalam_grup']]);
    $next = $stmt->fetch();
    return $next ? (int)$next['id'] : null;
}

/* ---------------------------------------------------------
 * LUPA PASSWORD
 * ------------------------------------------------------- */
function generateResetToken(string $email): ?string {
    $stmt = getDB()->prepare("SELECT id, status FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || in_array($user['status'], ['nonaktif', 'gugur'], true)) {
        return null;
    }

    $tokenMentah = bin2hex(random_bytes(32));
    $tokenHash   = hash('sha256', $tokenMentah);

    $stmt = getDB()->prepare("UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?");
    $stmt->execute([$tokenHash, $user['id']]);

    return $tokenMentah;
}

function sendResetPasswordEmail(string $email, string $nama, string $tokenMentah): bool {
    $resetLink = baseUrl('reset_password.php') . '?token=' . urlencode($tokenMentah);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $nama);

        $mail->isHTML(true);
        $mail->Subject = 'Reset Password - Psikotes Online';
        $mail->Body    = '
            <div style="font-family: sans-serif; max-width: 480px; margin: 0 auto;">
                <h2 style="color:#0f172a;">Reset Password</h2>
                <p>Halo ' . e($nama) . ',</p>
                <p>Kami menerima permintaan untuk mereset password akun Anda. Klik tombol di bawah ini untuk membuat password baru. Link ini berlaku selama <strong>1 jam</strong>.</p>
                <p style="text-align:center; margin: 24px 0;">
                    <a href="' . e($resetLink) . '" style="background:#0f172a; color:#fff; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:600;">Reset Password</a>
                </p>
                <p>Jika Anda tidak merasa meminta reset password, abaikan saja email ini — password Anda tidak akan berubah.</p>
                <p style="color:#64748b; font-size:12px;">Jika tombol tidak berfungsi, salin link berikut ke browser Anda:<br>' . e($resetLink) . '</p>
            </div>
        ';
        $mail->AltBody = "Klik link berikut untuk reset password (berlaku 1 jam): $resetLink";

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Gagal kirim email reset password: ' . $mail->ErrorInfo);
        return false;
    }
}

function validateResetToken(string $tokenMentah): array|false {
    $tokenHash = hash('sha256', $tokenMentah);

    $stmt = getDB()->prepare("
        SELECT id, nama, email
        FROM users
        WHERE reset_token = ? AND reset_expires IS NOT NULL AND reset_expires > NOW()
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $user = $stmt->fetch();

    return $user ?: false;
}

function resetPassword(int $userId, string $passwordBaru): void {
    $hash = password_hash($passwordBaru, PASSWORD_DEFAULT);
    $stmt = getDB()->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
    $stmt->execute([$hash, $userId]);
}