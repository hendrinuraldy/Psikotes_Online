<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');
header('Content-Type: application/json');

// FIX: sekarang toleransi HANYA 1x. Begitu ketahuan pindah tab / keluar
// window, attempt langsung digugurkan (status 'gugur') dan peserta akan
// memulai attempt BARU dari soal #1 saat redirect balik ke halaman tes.
// Catatan: kolom `status` di tabel `attempt` harus bisa menampung nilai
// 'gugur'. Kalau kolomnya ENUM, jalankan dulu migration:
//   ALTER TABLE attempt MODIFY status ENUM('berlangsung','selesai','gugur') NOT NULL;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];
$attemptId = (int)($_POST['attempt_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM attempt WHERE id = ? AND user_id = ? AND status = 'berlangsung'");
$stmt->execute([$attemptId, $userId]);
$attempt = $stmt->fetch();
if (!$attempt) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$count = (int)$attempt['pelanggaran_count'] + 1;

// Langsung gugurkan attempt ini. Jawaban yang sudah tersimpan di tabel
// `jawaban` dibiarkan apa adanya (untuk jejak audit / riwayat pelanggaran),
// tapi tidak akan pernah ditampilkan sebagai hasil karena hasil_detail.php
// hanya menerima attempt dengan status 'selesai'.
$db->prepare("UPDATE attempt SET pelanggaran_count = ?, flag_curang = 1, status = 'gugur' WHERE id = ?")
   ->execute([$count, $attemptId]);

echo json_encode([
    'ok' => true,
    'count' => $count,
    'reset' => true,
    // Redirect ke halaman tes yang sama -> karena attempt lama sudah
    // berstatus 'gugur', tes_kerjakan.php otomatis membuatkan attempt baru
    // dari soal #1 dengan timer baru.
    'redirect' => 'tes.php?id=' . (int)$attempt['tes_id'],
]);