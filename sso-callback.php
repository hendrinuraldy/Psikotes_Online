<?php
require_once __DIR__ . '/includes/functions.php';
$ssoConfig = require __DIR__ . '/config/sso.php';

$token = $_GET['token'] ?? '';
$signature = $_GET['sig'] ?? '';
$clientId = $_GET['client_id'] ?? '';

if ($token === '' || $signature === '' || $clientId !== $ssoConfig['client_id']) {
    http_response_code(400);
    die('Token SSO tidak valid.');
}

$expectedSignature = hash_hmac('sha256', $token, $ssoConfig['client_secret']);
if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(403);
    die('Tanda tangan token tidak valid.');
}

$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['exp'], $payload['email'], $payload['role'], $payload['name'])) {
    http_response_code(400);
    die('Payload token rusak.');
}

if (time() > $payload['exp']) {
    http_response_code(401);
    die('Token SSO sudah kadaluarsa, silakan klik ulang dari Portal SSO.');
}

if ($payload['role'] !== 'admin') {
    http_response_code(403);
    die('Akun Anda tidak memiliki akses ke Psikotes Online.');
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$payload['email']]);
$user = $stmt->fetch();

if (!$user) {
    $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $insert = $db->prepare("INSERT INTO users (nama, email, password, role, status) VALUES (?, ?, ?, 'admin', 'aktif')");
    $insert->execute([$payload['name'], $payload['email'], $randomPassword]);

    $userId = $db->lastInsertId();
    $nama = $payload['name'];
} else {
    if ($user['status'] === 'nonaktif') {
        http_response_code(403);
        die('Akun Anda dinonaktifkan. Hubungi admin.');
    }

    // Sync nama kalau beda dari yang tersimpan
    if ($user['nama'] !== $payload['name']) {
        $update = $db->prepare("UPDATE users SET nama = ? WHERE id = ?");
        $update->execute([$payload['name'], $user['id']]);
    }

    $userId = $user['id'];
    $nama = $payload['name'];
}

$_SESSION['user_id'] = $userId;
$_SESSION['role'] = 'admin';
$_SESSION['nama'] = $nama;

header('Location: admin/dashboard.php');
exit;