<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

$attemptId = (int)($_POST['attempt_id'] ?? 0);
$soalId    = (int)($_POST['soal_id'] ?? 0);
$raguRagu  = !empty($_POST['ragu_ragu']) ? 1 : 0;

// Pastikan attempt ini milik peserta yang login dan masih berlangsung
$stmt = $db->prepare("SELECT * FROM attempt WHERE id = ? AND user_id = ? AND status = 'berlangsung'");
$stmt->execute([$attemptId, $userId]);
$attempt = $stmt->fetch();
if (!$attempt) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'attempt_invalid']);
    exit;
}

// Pastikan soal memang milik tes dari attempt ini. Sekalian ambil
// format_soal dari tes-nya untuk menentukan jalur mana yang dipakai:
// forced-choice (Most/Least, format_soal='forced_choice') atau
// single/multiple biasa.
$stmt = $db->prepare("
    SELECT s.id, s.tipe_soal, t.format_soal
    FROM soal s JOIN tes t ON t.id = s.tes_id
    WHERE s.id = ? AND s.tes_id = ?
");
$stmt->execute([$soalId, $attempt['tes_id']]);
$soal = $stmt->fetch();
if (!$soal) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'soal_invalid']);
    exit;
}

$isForcedChoice = ($soal['format_soal'] ?? null) === 'forced_choice';

try {
    if ($isForcedChoice) {
        // ============== JALUR FORCED-CHOICE (Most / Least) ==============
        $mostId  = isset($_POST['opsi_id_most'])  && $_POST['opsi_id_most']  !== '' ? (int)$_POST['opsi_id_most']  : null;
        $leastId = isset($_POST['opsi_id_least']) && $_POST['opsi_id_least'] !== '' ? (int)$_POST['opsi_id_least'] : null;

        // Satu pernyataan tidak boleh jadi Most sekaligus Least sekaligus
        if ($mostId !== null && $leastId !== null && $mostId === $leastId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'most_least_sama']);
            exit;
        }

        // Validasi opsi memang milik soal ini
        foreach (['most' => $mostId, 'least' => $leastId] as $peran => $oid) {
            if ($oid === null) continue;
            $cek = $db->prepare("SELECT id FROM opsi WHERE id = ? AND soal_id = ?");
            $cek->execute([$oid, $soalId]);
            if (!$cek->fetch()) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'opsi_invalid_' . $peran]);
                exit;
            }
        }

        $db->beginTransaction();
        $db->prepare("DELETE FROM jawaban WHERE attempt_id = ? AND soal_id = ?")->execute([$attemptId, $soalId]);

        $insert = $db->prepare("INSERT INTO jawaban (attempt_id, soal_id, opsi_id, ragu_ragu, tipe_pilihan) VALUES (?, ?, ?, ?, ?)");
        if ($mostId !== null) {
            $insert->execute([$attemptId, $soalId, $mostId, $raguRagu, 'most']);
        }
        if ($leastId !== null) {
            $insert->execute([$attemptId, $soalId, $leastId, $raguRagu, 'least']);
        }
        if ($mostId === null && $leastId === null && $raguRagu === 1) {
            // Belum ada pilihan sama sekali tapi ditandai ragu -> simpan penanda saja
            $insert->execute([$attemptId, $soalId, null, 1, null]);
        }

        $db->commit();
        echo json_encode(['ok' => true]);
        exit;
    }

    // ============== JALUR SINGLE / MULTIPLE (perilaku lama) ==============
    $opsiRaw = $_POST['opsi_id'] ?? [];
    if (!is_array($opsiRaw)) {
        $opsiRaw = ($opsiRaw !== '') ? [$opsiRaw] : [];
    }
    $opsiIds = array_values(array_unique(array_map('intval', $opsiRaw)));

    // Kalau soal single, paksa maksimal 1 opsi (jaga-jaga kalau ada request aneh)
    if (($soal['tipe_soal'] ?? 'single') !== 'multiple' && count($opsiIds) > 1) {
        $opsiIds = [$opsiIds[0]];
    }

    // Pastikan semua opsi yang dikirim memang milik soal ini
    if (!empty($opsiIds)) {
        $placeholders = implode(',', array_fill(0, count($opsiIds), '?'));
        $stmt = $db->prepare("SELECT id FROM opsi WHERE soal_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$soalId], $opsiIds));
        $validIds = array_column($stmt->fetchAll(), 'id');
        $validIds = array_map('intval', $validIds);

        if (count($validIds) !== count($opsiIds)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'opsi_invalid']);
            exit;
        }
    }

    $db->beginTransaction();

    $del = $db->prepare("DELETE FROM jawaban WHERE attempt_id = ? AND soal_id = ?");
    $del->execute([$attemptId, $soalId]);

    $insert = $db->prepare("INSERT INTO jawaban (attempt_id, soal_id, opsi_id, ragu_ragu) VALUES (?, ?, ?, ?)");

    if (!empty($opsiIds)) {
        foreach ($opsiIds as $oid) {
            $insert->execute([$attemptId, $soalId, $oid, $raguRagu]);
        }
    } elseif ($raguRagu === 1) {
        $insert->execute([$attemptId, $soalId, null, 1]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['ok' => true]);