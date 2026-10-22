<?php
require_once __DIR__ . '/includes/functions.php';

// Menentukan path dasar aplikasi secara dinamis berdasarkan letak file ini
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';

if (isLoggedIn()) {
    $target = ($_SESSION['role'] === 'admin') ? 'admin/dashboard.php' : 'peserta/dashboard.php';
    header('Location: ' . $baseUrl . $target);
} else {
    header('Location: ' . $baseUrl . 'login.php');
}
exit;