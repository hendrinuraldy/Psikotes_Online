<?php
/**
 * Simpan sebagai: admin/backfill_iq.php  -> buka SEKALI di browser sebagai admin, lalu HAPUS file ini.
 * Menghitung IQ untuk attempt WPT yang sudah selesai tapi iq_score-nya masih kosong.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wpt_scoring.php';
requireRole('admin');

$db = getDB();
$ids = $db->query("
    SELECT a.id FROM attempt a JOIN tes t ON t.id = a.tes_id
    WHERE t.skala_iq = 'wpt' AND a.status = 'selesai' AND a.iq_score IS NULL
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($ids as $id) {
    $h = wptSimpanIq($db, (int)$id);
    echo "Attempt #{$id}: benar={$h['skor_mentah']}, koreksi usia=+{$h['koreksi_usia']}, IQ={$h['iq']} ({$h['klasifikasi']})<br>";
}
echo count($ids) ? '<br>Selesai. Hapus file ini.' : 'Tidak ada attempt yang perlu diproses.';