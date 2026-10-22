<?php
/**
 * CATATAN INTEGRASI:
 * File-file peserta (login, mulai attempt, timer, dsb) tidak ikut dibagikan,
 * jadi file ini adalah TEMPLATE yang mengasumsikan pola yang sama dengan
 * file-file admin yang sudah ada:
 *   - requireRole('peserta') tersedia di includes/functions.php
 *   - $_SESSION['user_id'] menyimpan id peserta yang sedang login
 *   - Sebuah attempt (baris di tabel attempt) sudah dibuat sebelumnya saat
 *     peserta menekan "Mulai Tes", dan attempt_id dikirim lewat GET/session
 * Sesuaikan bagian-bagian ini dengan pola yang sebenarnya dipakai di
 * peserta/tes_kerjakan.php (atau file sejenis) yang sudah Anda punya.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/disc_scoring.php';
requireRole('peserta');

$db = getDB();
$attemptId = (int)($_GET['attempt_id'] ?? 0);

$stmt = $db->prepare("
    SELECT a.*, t.judul AS judul_tes, t.format_soal
    FROM attempt a JOIN tes t ON t.id = a.tes_id
    WHERE a.id = ? AND a.user_id = ? AND a.status = 'berlangsung'
");
$stmt->execute([$attemptId, $_SESSION['user_id']]);
$attempt = $stmt->fetch();
if (!$attempt || $attempt['format_soal'] !== 'forced_choice') {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pilihanMost = $_POST['most'] ?? [];   // [soal_id => opsi_id]
    $pilihanLeast = $_POST['least'] ?? []; // [soal_id => opsi_id]

    $stmt = $db->prepare("SELECT id FROM soal WHERE tes_id = ?");
    $stmt->execute([$attempt['tes_id']]);
    $semuaSoalId = array_column($stmt->fetchAll(), 'id');

    $belumLengkap = false;
    foreach ($semuaSoalId as $sid) {
        if (empty($pilihanMost[$sid]) || empty($pilihanLeast[$sid])) { $belumLengkap = true; break; }
        if ($pilihanMost[$sid] === $pilihanLeast[$sid]) { $belumLengkap = true; break; } // Most & Least tidak boleh sama
    }

    if ($belumLengkap) {
        $error = 'Setiap nomor wajib diisi PALING dan PALING TIDAK, dan keduanya tidak boleh pernyataan yang sama.';
    } else {
        $db->beginTransaction();
        $db->prepare("DELETE FROM jawaban WHERE attempt_id = ?")->execute([$attemptId]);
        $insert = $db->prepare("INSERT INTO jawaban (attempt_id, soal_id, opsi_id, tipe_pilihan) VALUES (?, ?, ?, ?)");
        foreach ($semuaSoalId as $sid) {
            $insert->execute([$attemptId, $sid, (int)$pilihanMost[$sid], 'most']);
            $insert->execute([$attemptId, $sid, (int)$pilihanLeast[$sid], 'least']);
        }
        $db->commit();

        simpanHasilDisc($attemptId, $db); // hitung skor & tandai attempt selesai

        header('Location: hasil_saya.php?attempt_id=' . $attemptId);
        exit;
    }
}

$stmt = $db->prepare("SELECT * FROM soal WHERE tes_id = ? ORDER BY urutan ASC, id ASC");
$stmt->execute([$attempt['tes_id']]);
$soalList = $stmt->fetchAll();
foreach ($soalList as &$s) {
    $stmt2 = $db->prepare("SELECT * FROM opsi WHERE soal_id = ? ORDER BY label ASC");
    $stmt2->execute([$s['id']]);
    $s['opsi'] = $stmt2->fetchAll();
}
unset($s);

$pageTitle = 'Mengerjakan: ' . $attempt['judul_tes'];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="alert alert-info small">
    Untuk setiap nomor: lingkari/pilih SATU pernyataan yang <strong>PALING SESUAI</strong> menggambarkan diri Anda,
    dan SATU pernyataan yang <strong>PALING TIDAK SESUAI</strong> menggambarkan diri Anda. Setiap nomor wajib diisi keduanya.
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="POST">
    <?php foreach ($soalList as $idx => $s): ?>
    <div class="card-modern mb-3 p-3">
        <div class="fw-bold mb-2">No. <?= $idx + 1 ?></div>
        <table class="table table-sm align-middle">
            <thead>
                <tr><th style="width:70px;" class="text-center">Paling</th><th style="width:70px;" class="text-center">Paling Tidak</th><th>Pernyataan</th></tr>
            </thead>
            <tbody>
                <?php foreach ($s['opsi'] as $o): ?>
                <tr>
                    <td class="text-center"><input type="radio" name="most[<?= $s['id'] ?>]" value="<?= $o['id'] ?>" required></td>
                    <td class="text-center"><input type="radio" name="least[<?= $s['id'] ?>]" value="<?= $o['id'] ?>" required></td>
                    <td><?= e($o['label']) ?>. <?= e($o['teks']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-brand w-100 py-2 fw-semibold mb-5">Selesai & Kirim Jawaban</button>
</form>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>