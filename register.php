<?php
// Pendaftaran mandiri sudah dinonaktifkan. Semua akun peserta
// sekarang dibuat oleh admin lewat halaman admin/peserta.php,
// lalu detail akunnya dikirim otomatis via email.
require_once __DIR__ . '/includes/functions.php';

$_SESSION['flash_success'] = 'Pendaftaran mandiri sudah tidak tersedia. Akun peserta dibuatkan oleh admin dan detail login dikirim via email.';
header('Location: login.php');
exit;