<?php
/**
 * Simpan sebagai: admin/hitung_ulang_cfit.php
 * Jalankan SEKALI (buka lewat browser sebagai admin) untuk menghitung IQ CFIT
 * attempt lama yang sudah selesai sebelum cfit_scoring.php dipasang.
 * Setelah selesai, file ini boleh dihapus.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cfit_scoring.php';
requireRole('admin');

$db = getDB();

// Attempt selesai di bagian TERAKHIR tiap grup CFIT (skala_iq = 'cfit')
$rows = $db->query("
    SELECT a.id, u.nama, t.judul
    FROM attempt a
    JOIN users u ON u.id = a.user_id
    JOIN tes t ON t.id = a.tes_id
    WHERE t.skala_iq = 'cfit' AND a.status = 'selesai'
      AND t.urutan_dalam_grup = (SELECT MAX(t2.urutan_dalam_grup) FROM tes t2 WHERE t2.grup_id = t.grup_id)
    ORDER BY a.id ASC
")->fetchAll();

echo '<pre>';
foreach ($rows as $r) {
    $h = cfitSimpanIq($db, (int)$r['id']);
    echo e($r['nama']) . ' - ' . e($r['judul']) . ' (attempt ' . (int)$r['id'] . '): '
       . ($h ? 'benar ' . $h['skor_mentah'] . ' => IQ ' . $h['iq'] . ' (' . $h['klasifikasi'] . ')'
             : 'dilewati (bagian lain belum selesai / skala_iq belum cfit)') . "\n";
}
echo count($rows) ? '' : 'Tidak ada attempt CFIT yang selesai.';
echo '</pre>';