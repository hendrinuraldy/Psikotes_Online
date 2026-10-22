<?php
// Simpan file ini sebagai: admin/download_lamaran.php
// Tujuannya: file CV/lamaran TIDAK bisa diakses langsung lewat URL publik,
// hanya lewat script ini yang mengecek role admin dulu.

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? '';

if (!in_array($type, ['cv', 'lamaran'], true)) {
    http_response_code(400);
    exit('Tipe dokumen tidak valid.');
}

$stmt = $db->prepare("SELECT * FROM lamaran WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Data tidak ditemukan.');
}

$storedName   = $type === 'cv' ? $row['cv_path'] : $row['lamaran_path'];
$originalName = $type === 'cv' ? $row['cv_filename'] : $row['lamaran_filename'];
$fullPath     = realpath(__DIR__ . '/../storage/lamaran/' . basename($storedName));

$storageRoot = realpath(__DIR__ . '/../storage/lamaran/');
if (!$fullPath || strpos($fullPath, $storageRoot) !== 0 || !file_exists($fullPath)) {
    http_response_code(404);
    exit('File tidak ditemukan di server.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . basename($originalName) . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit; 