<?php
// Simpan sebagai: includes/attempt_auto.php
//
// Menutup otomatis semua attempt berstatus 'berlangsung' yang waktunya sudah
// habis (waktu_mulai + durasi_menit <= sekarang). Jawaban yang sudah tersimpan
// lewat autosave tetap dinilai, jadi peserta yang keluar / koneksi putus /
// tidak sempat klik "Selesaikan Tes" tetap punya hasil.
//
// Dipanggil secara "lazy" dari halaman yang sering dibuka (admin/hasil.php,
// peserta/dashboard.php), tidak perlu cron.

require_once __DIR__ . '/functions.php';

function finalisasiAttemptKadaluarsa(PDO $db): int
{
    date_default_timezone_set('Asia/Jakarta');
    $sekarang = time();

    $rows = $db->query("
        SELECT a.id, a.waktu_mulai, t.durasi_menit, t.format_soal, t.jenis, t.tipe_psikotes
        FROM attempt a
        JOIN tes t ON t.id = a.tes_id
        WHERE a.status = 'berlangsung'
    ")->fetchAll();

    $jumlah = 0;
    foreach ($rows as $r) {
        $batas = strtotime($r['waktu_mulai']) + ((int)$r['durasi_menit'] * 60);
        if ($sekarang < $batas) continue; // masih ada waktu, biarkan berlangsung

        $id = (int)$r['id'];
        try {
            if (($r['format_soal'] ?? null) === 'forced_choice') {
                require_once __DIR__ . '/disc_scoring.php';
                simpanHasilDisc($id, $db);
            } elseif ($r['jenis'] === 'psikotes' && $r['tipe_psikotes'] === 'papikostik') {
                require_once __DIR__ . '/papikostik_scoring.php';
                simpanHasilPapikostik($id, $db);
            } elseif ($r['jenis'] === 'eksternal') {
                // Tes eksternal (MBTI/16Personalities) tidak punya skor otomatis.
                // Hanya tutup statusnya; JANGAN lewat selesaikanAttempt() karena
                // itu mengosongkan profil_hasil yang mungkin sudah diisi peserta.
                $db->prepare("UPDATE attempt SET status = 'selesai', waktu_selesai = NOW() WHERE id = ? AND status = 'berlangsung'")
                   ->execute([$id]);
            } else {
                selesaikanAttempt($id);
                if ($r['jenis'] === 'logika') {
                    require_once __DIR__ . '/wpt_scoring.php';
                    wptSimpanIq($db, $id);
                    require_once __DIR__ . '/cfit_scoring.php';
                    cfitSimpanIq($db, $id);
                }
            }

            // Catat waktu selesai = batas waktu ujian (bukan saat admin membuka halaman)
            $upd = $db->prepare("UPDATE attempt SET waktu_selesai = ? WHERE id = ? AND status = 'selesai'");
            $upd->execute([date('Y-m-d H:i:s', $batas), $id]);

            $jumlah++;
        } catch (Throwable $e) {
            error_log('finalisasiAttemptKadaluarsa gagal untuk attempt ' . $id . ': ' . $e->getMessage());
        }
    }
    return $jumlah;
}