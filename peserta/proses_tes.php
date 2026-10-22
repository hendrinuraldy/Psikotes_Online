<?php
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];
$attemptId = (int)($_POST['attempt_id'] ?? 0);

// Pastikan attempt ini benar milik peserta yang login & masih berlangsung.
// Sekalian ambil format_soal, jenis, tipe_psikotes, dan durasi dari tes-nya
// untuk menentukan jalur penyelesaian & mesin skor yang tepat.
$stmt = $db->prepare("
    SELECT a.*, t.format_soal, t.jenis, t.tipe_psikotes, t.durasi_menit, t.judul AS judul_tes
    FROM attempt a JOIN tes t ON t.id = a.tes_id
    WHERE a.id = ? AND a.user_id = ? AND a.status = 'berlangsung'
");
$stmt->execute([$attemptId, $userId]);
$attempt = $stmt->fetch();
if (!$attempt) { header('Location: dashboard.php'); exit; }

$isForcedChoice = ($attempt['format_soal'] ?? null) === 'forced_choice';

// Kalau waktu sudah habis, submit otomatis dari timer tidak boleh diblokir
// walau jawaban belum lengkap. Dicek pakai waktu server (bukan kiriman
// browser), toleransi 10 detik untuk selisih timer di sisi klien.
$durasiDetik = (int)$attempt['durasi_menit'] * 60;
$waktuHabis = (time() - strtotime($attempt['waktu_mulai'])) >= ($durasiDetik - 10);

if ($isForcedChoice) {
    // Untuk tes forced-choice (DISC Most/Least), semua jawaban sudah
    // tersimpan lewat autosave (simpan_jawaban.php) setiap kali peserta
    // menandai Most/Least di tiap soal — jadi di sini tidak perlu lagi
    // reconcile ulang dari $_POST['jawaban'] (form-nya memang tidak
    // mengirim field itu untuk soal forced-choice).

    // Pengaman server: DISC wajib lengkap (Most + Least, dan tidak boleh
    // pernyataan yang sama) di SEMUA soal, kecuali waktu sudah habis.
    $stmt = $db->prepare("SELECT id FROM soal WHERE tes_id = ?");
    $stmt->execute([$attempt['tes_id']]);
    $semuaSoalId = array_column($stmt->fetchAll(), 'id');

    $stmt = $db->prepare("SELECT soal_id, opsi_id, tipe_pilihan FROM jawaban WHERE attempt_id = ? AND opsi_id IS NOT NULL AND tipe_pilihan IN ('most','least')");
    $stmt->execute([$attemptId]);
    $mostMap = [];
    $leastMap = [];
    foreach ($stmt->fetchAll() as $j) {
        if ($j['tipe_pilihan'] === 'most') $mostMap[$j['soal_id']] = (int)$j['opsi_id'];
        else $leastMap[$j['soal_id']] = (int)$j['opsi_id'];
    }

    $belumLengkap = 0;
    foreach ($semuaSoalId as $sid) {
        if (!isset($mostMap[$sid]) || !isset($leastMap[$sid]) || $mostMap[$sid] === $leastMap[$sid]) {
            $belumLengkap++;
        }
    }

    if ($belumLengkap > 0 && !$waktuHabis) {
        header('Location: tes.php?id=' . $attempt['tes_id'] . '&err=belum_lengkap');
        exit;
    }

    // Langsung hitung skor & tutup attempt-nya.
    require_once __DIR__ . '/../includes/disc_scoring.php';
    simpanHasilDisc($attemptId, $db);

    // Kalau tes ini bagian dari grup (mis. CFIT 1-4), lanjut otomatis ke
    // bagian berikutnya yang belum selesai; kalau sudah bagian terakhir
    // (atau tes ini tidak tergabung grup), arahkan ke halaman hasil seperti biasa.
    $nextTesId = tesGrupBerikutnya($db, $userId, $attempt['tes_id']);
    header('Location: ' . ($nextTesId ? 'tes.php?id=' . $nextTesId : 'hasil_detail.php?id=' . $attemptId));
    exit;
}

$jawabanInput = $_POST['jawaban'] ?? [];
$raguInput = $_POST['ragu'] ?? [];

// Ambil soal yang valid untuk tes ini (sekalian ambil tipe_soal-nya)
$stmt = $db->prepare("SELECT id, tipe_soal FROM soal WHERE tes_id = ?");
$stmt->execute([$attempt['tes_id']]);
$soalRows = $stmt->fetchAll();
$soalTipeMap = [];
foreach ($soalRows as $sr) {
    $soalTipeMap[$sr['id']] = $sr['tipe_soal'] ?? 'single';
}

$db->beginTransaction();

// CFIT 2: maksimal 2 gambar per soal (pengaman server, walau UI sudah membatasi)
$maxPilihan = 0;
if ($attempt['jenis'] === 'logika'
    && preg_match('/cfit\D*([1-4])/i', (string)($attempt['judul_tes'] ?? ''), $mCfit)
    && (int)$mCfit[1] === 2) {
    $maxPilihan = 2;
}
$del = $db->prepare("DELETE FROM jawaban WHERE attempt_id = ? AND soal_id = ?");
$insert = $db->prepare("INSERT INTO jawaban (attempt_id, soal_id, opsi_id, ragu_ragu) VALUES (?, ?, ?, ?)");
$cekOpsi = $db->prepare("SELECT id FROM opsi WHERE id = ? AND soal_id = ?");

// Hitung soal yang benar-benar belum dijawab (untuk validasi Papikostik)
$belumDijawab = 0;

// Ini adalah pengaman terakhir: sebagian besar jawaban sebenarnya sudah
// tersimpan lewat autosave (simpan_jawaban.php) saat peserta pindah soal.
foreach ($soalTipeMap as $soalId => $tipeSoal) {
    $ragu = !empty($raguInput[$soalId]) ? 1 : 0;

    // Normalisasi input jawaban jadi array opsi_id, apa pun bentuk yang dikirim form
    $rawJawaban = $jawabanInput[$soalId] ?? null;
    if ($rawJawaban === null || $rawJawaban === '') {
        $opsiIdsMentah = [];
    } elseif (is_array($rawJawaban)) {
        $opsiIdsMentah = $rawJawaban;
    } else {
        $opsiIdsMentah = [$rawJawaban];
    }
    $opsiIdsMentah = array_values(array_unique(array_map('intval', $opsiIdsMentah)));

    if ($tipeSoal !== 'multiple' && count($opsiIdsMentah) > 1) {
        $opsiIdsMentah = [$opsiIdsMentah[0]];
    }
    if ($maxPilihan > 0 && $tipeSoal === 'multiple' && count($opsiIdsMentah) > $maxPilihan) {
        $opsiIdsMentah = array_slice($opsiIdsMentah, 0, $maxPilihan);
    }

    // Validasi setiap opsi memang milik soal ini
    $opsiIdsValid = [];
    foreach ($opsiIdsMentah as $oid) {
        $cekOpsi->execute([$oid, $soalId]);
        if ($cekOpsi->fetch()) $opsiIdsValid[] = $oid;
    }

    if (empty($opsiIdsValid)) $belumDijawab++;

    // Hapus dulu jawaban lama utk soal ini, baru insert ulang yang valid
    $del->execute([$attemptId, $soalId]);

    if (!empty($opsiIdsValid)) {
        foreach ($opsiIdsValid as $oid) {
            $insert->execute([$attemptId, $soalId, $oid, $ragu]);
        }
    } elseif ($ragu === 1) {
        $insert->execute([$attemptId, $soalId, null, 1]);
    }
    // kalau kosong & tidak ragu -> soal ini memang tidak dijawab, biarkan tidak ada baris
}

// Pengaman server: Papikostik wajib lengkap di SEMUA soal, kecuali waktu
// sudah habis. Rollback aman karena jawaban yang sudah terisi tersimpan
// lewat autosave.
if ($attempt['jenis'] === 'psikotes' && $attempt['tipe_psikotes'] === 'papikostik'
    && $belumDijawab > 0 && !$waktuHabis) {
    $db->rollBack();
    header('Location: tes.php?id=' . $attempt['tes_id'] . '&err=belum_lengkap');
    exit;
}

$db->commit();

// Tes Papikostik punya mesin skor sendiri (20 dimensi), sama seperti DISC
// yang bypass selesaikanAttempt() generik dan menghitung + menyimpan hasil
// akhirnya sendiri lewat simpanHasilDisc(). Untuk psikotes lain (mis. MBTI)
// dan tes logika, alur lama tetap dipakai lewat selesaikanAttempt().
if ($attempt['jenis'] === 'psikotes' && $attempt['tipe_psikotes'] === 'papikostik') {
    require_once __DIR__ . '/../includes/papikostik_scoring.php';
    simpanHasilPapikostik($attemptId, $db);
} else {
    selesaikanAttempt($attemptId);

    // Tes logika (WPT) perlu konversi skor mentah -> IQ setelah
    // selesaikanAttempt() menghitung & menyimpan total_benar.
    if ($attempt['jenis'] === 'logika') {
        require_once __DIR__ . '/../includes/wpt_scoring.php';
        wptSimpanIq($db, $attemptId);
        require_once __DIR__ . '/../includes/cfit_scoring.php';
        cfitSimpanIq($db, $attempt['id']);
    }
}

// Kalau tes ini bagian dari grup (mis. CFIT 1-4), lanjut otomatis ke bagian
// berikutnya yang belum selesai; kalau sudah bagian terakhir (atau tes ini
// tidak tergabung grup), arahkan ke halaman hasil seperti biasa.
$nextTesId = tesGrupBerikutnya($db, $userId, $attempt['tes_id']);
header('Location: ' . ($nextTesId ? 'tes.php?id=' . $nextTesId : 'hasil_detail.php?id=' . $attemptId));
exit;