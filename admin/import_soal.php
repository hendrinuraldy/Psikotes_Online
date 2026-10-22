<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf_import.php';
requireRole('admin');

$db = getDB();
$tesId = (int)($_GET['tes_id'] ?? $_POST['tes_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tes WHERE id = ?");
$stmt->execute([$tesId]);
$tes = $stmt->fetch();
if (!$tes) { header('Location: tes.php'); exit; }

$draftDir = __DIR__ . '/../uploads/import_draft/';
if (!is_dir($draftDir)) mkdir($draftDir, 0755, true);
bersihkanDraftLama($draftDir);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['pdf_soal']['name'])) {
    if ($_FILES['pdf_soal']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Gagal mengunggah file. Coba lagi.';
    } elseif (strtolower(pathinfo($_FILES['pdf_soal']['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        $error = 'File harus berformat PDF.';
    } else {
        $tmpPdf = $draftDir . uniqid('src_') . '.pdf';
        move_uploaded_file($_FILES['pdf_soal']['tmp_name'], $tmpPdf);

        $hasil = extractPdfText($tmpPdf);

        if ($hasil['method'] === 'failed' || trim($hasil['text']) === '') {
            @unlink($tmpPdf);
            $error = 'Tidak bisa membaca teks dari PDF ini. Kemungkinan ini hasil scan/gambar dan OCR '
                . 'tidak tersedia di server ini. Silakan input soal ini manual lewat form "Tambah Soal", '
                . 'atau minta developer memasang poppler-utils & tesseract-ocr di server.';
        } else {
            // Jalur 1: coba deteksi format TABEL berkolom dulu (nomor + kolom
            // opsi A/B atau A/B/C/D) berbasis koordinat kata di PDF asli —
            // ini yang paling akurat untuk soal psikotes/forced-choice hasil
            // export dari Excel/Word. Hanya masuk akal untuk teks native
            // (bukan hasil OCR gambar, karena OCR tidak punya koordinat asli).
            $blocksTabel = ($hasil['method'] === 'native') ? parseTabelSoalDariPdf($tmpPdf) : null;
            @unlink($tmpPdf);

            if ($blocksTabel !== null) {
                $blocks = $blocksTabel;
                $metodeFinal = 'tabel (' . $hasil['method'] . ')';
            } else {
                // Jalur 2: fallback ke parser naskah biasa "1. ... A. ..."
                $blocks = parseTeksSoal($hasil['text']);
                $metodeFinal = $hasil['method'];
            }

            if (empty($blocks)) {
                $error = 'Teks berhasil dibaca (metode: ' . e($hasil['method']) . '), tapi polanya tidak '
                    . 'terdeteksi sebagai daftar soal (format yang dikenali: "1. Pertanyaan ... A. Opsi ..." '
                    . 'atau tabel berkolom "No | A | B [| C | D]"). Coba rapikan format PDF, atau input manual.';
            } else {
                $token = uniqid('draft_', true);
                file_put_contents($draftDir . $token . '.json', json_encode([
                    'tes_id' => $tesId,
                    'metode' => $metodeFinal,
                    'blocks' => $blocks,
                ]));
                header('Location: import_review.php?token=' . urlencode($token));
                exit;
            }
        }
    }
}

$pageTitle = 'Import Soal dari PDF';
$pageDesc = $tes['judul'];
$activeMenu = 'tes';
include __DIR__ . '/../includes/layout_top.php';
?>

<a href="soal.php?tes_id=<?= $tesId ?>" class="fw-semibold" style="font-size:13px; color:var(--brand);">&larr; Kembali ke Daftar Soal</a>

<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="card-modern">
            <h6 class="fw-bold mb-3">Upload PDF Soal — <?= e($tes['judul']) ?></h6>
            <?php if ($error): ?><div class="alert alert-danger py-2" style="font-size:13px;"><?= e($error) ?></div><?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tes_id" value="<?= $tesId ?>">
                <div class="mb-3">
                    <label class="form-label-modern">File PDF</label>
                    <input type="file" name="pdf_soal" class="form-control-modern" accept="application/pdf" required>
                </div>
                <button type="submit" class="btn btn-brand w-100">
                    <i class="bi bi-magic"></i> Proses & Tinjau Hasil
                </button>
            </form>

            <div class="mt-3" style="font-size:12.5px; color:var(--muted);">
                Hasil pembacaan PDF <strong>tidak langsung tersimpan</strong> — akan ditampilkan dulu di halaman
                review supaya bisa Anda cek/edit satu-satu sebelum masuk ke sistem.
            </div>
            <div class="mt-3 pt-3 border-top" style="font-size:12.5px;">
                Soalnya dalam bentuk Excel/CSV?
                <a href="import_excel.php?tes_id=<?= $tesId ?>" class="fw-semibold" style="color:var(--brand);">Import dari Excel &rarr;</a>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-modern" style="background:#f8f8fb;">
            <h6 class="fw-bold mb-2" style="font-size:13.5px;"><i class="bi bi-lightbulb"></i> Format PDF yang didukung</h6>
            <p style="font-size:12.5px; color:var(--muted);">Sistem otomatis mendeteksi salah satu dari 2 format ini:</p>
            <div style="font-size:12px; margin-bottom:10px;">
                <strong>1. Naskah biasa (mengalir ke bawah)</strong>
                <pre style="font-size:12px; white-space:pre-wrap; background:#fff; padding:8px; border-radius:8px; border:1px solid var(--border); margin-top:4px;">1. Saat menghadapi masalah besar, saya cenderung...
A. Langsung mencari solusi sendiri
B. Berdiskusi dengan orang lain dahulu
C. Menunda dan memikirkannya nanti
D. Merasa cemas dan sulit fokus</pre>
            </div>
            <div style="font-size:12px; margin-bottom:10px;">
                <strong>2. Tabel berkolom (mis. hasil export Excel)</strong>
                <pre style="font-size:12px; white-space:pre-wrap; background:#fff; padding:8px; border-radius:8px; border:1px solid var(--border); margin-top:4px;">No |  A                              |  B
1  |  Saya seorang pekerja keras.     |  Saya bukan seorang pemurung.
2  |  Saya suka bekerja lebih baik... |  Saya suka menekuni pekerjaan...</pre>
                <div style="color:var(--muted); margin-top:4px;">Jumlah opsi mengikuti isi PDF — cukup A/B saja atau lengkap A/B/C/D, sistem menyesuaikan otomatis.</div>
            </div>
            <div style="font-size:12px; color:var(--muted);">
                <ul class="mb-2" style="padding-left:18px;">
                    <li>Kalau PDF hasil scan/foto (bukan teks asli), sistem akan coba OCR — hasilnya
                        biasanya kurang rapi, jadi lebih banyak yang perlu dikoreksi di halaman review.</li>
                    <li>Gambar di dalam PDF (untuk soal pola/gambar) <strong>tidak ikut ter-extract</strong> —
                        kalau soal butuh gambar, tambahkan manual lewat form "Tambah Soal" setelah import.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>