<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf_import.php';   // untuk bersihkanDraftLama()
require_once __DIR__ . '/../includes/excel_import.php';
requireRole('admin');

$db = getDB();
$tesId = (int)($_GET['tes_id'] ?? $_POST['tes_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tes WHERE id = ?");
$stmt->execute([$tesId]);
$tes = $stmt->fetch();
if (!$tes || $tes['jenis'] === 'eksternal') { header('Location: tes.php'); exit; }

$isPsikotes = ($tes['jenis'] === 'psikotes');
$dimensiValid = $isPsikotes ? excelDimensiPsikotes($tes['tipe_psikotes'] ?? null) : [];

// ============== UNDUH TEMPLATE ==============
if (isset($_GET['template'])) {
    try {
        $data = excelDataTemplate($tes['jenis'], $tes['tipe_psikotes'] ?? null);
        $lebar = $isPsikotes
            ? [6, 40, 26, 26, 26, 26, 26, 11, 11, 11, 11, 11]
            : [6, 40, 22, 22, 22, 22, 22, 9];
        $bin = excelBuatXlsx($data, $lebar);
    } catch (RuntimeException $ex) {
        http_response_code(500);
        exit(e($ex->getMessage()));
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_soal_' . $tes['jenis'] . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

$draftDir = __DIR__ . '/../uploads/import_draft/';
if (!is_dir($draftDir)) mkdir($draftDir, 0755, true);
bersihkanDraftLama($draftDir);

$error = null;

// ============== PROSES UPLOAD ==============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file_excel']['name'])) {
    $f = $_FILES['file_excel'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

    if ($f['error'] !== UPLOAD_ERR_OK) {
        $error = 'Gagal mengunggah file. Coba lagi.';
    } elseif (!in_array($ext, ['xlsx', 'xlsm', 'xls', 'csv'], true)) {
        $error = 'File harus berformat Excel (.xlsx) atau CSV.';
    } elseif ($f['size'] > EXCEL_MAX_BYTES) {
        $error = 'Ukuran file terlalu besar (maksimal ' . (EXCEL_MAX_BYTES / 1024 / 1024) . ' MB).';
    } elseif ($isPsikotes && empty($dimensiValid)) {
        $error = 'Tes psikotes ini belum punya kerangka penilaian (DISC/MBTI/Papikostik). Atur dulu di Kelola Tes.';
    } else {
        try {
            $tabel = excelBacaFile($f['tmp_name'], $ext);
            $hasil = excelParseSoal($tabel['rows'], $tes['jenis'], $dimensiValid);

            $token = uniqid('draft_', true);
            file_put_contents($draftDir . $token . '.json', json_encode([
                'tes_id'   => $tesId,
                'metode'   => 'excel (' . $tabel['method'] . ', format ' . $hasil['mode'] . ')',
                'blocks'   => $hasil['blocks'],
                'warnings' => $hasil['warnings'],
            ]));
            header('Location: import_review.php?token=' . urlencode($token));
            exit;
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}

$pageTitle = 'Import Soal dari Excel';
$pageDesc = $tes['judul'];
$activeMenu = 'tes';
include __DIR__ . '/../includes/layout_top.php';
?>

<a href="soal.php?tes_id=<?= $tesId ?>" class="fw-semibold" style="font-size:13px; color:var(--brand);">&larr; Kembali ke Daftar Soal</a>

<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="card-modern">
            <h6 class="fw-bold mb-3">Upload Excel Soal — <?= e($tes['judul']) ?></h6>
            <?php if ($error): ?><div class="alert alert-danger py-2" style="font-size:13px;"><?= e($error) ?></div><?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tes_id" value="<?= $tesId ?>">
                <div class="mb-3">
                    <label class="form-label-modern">File Excel (.xlsx) atau CSV</label>
                    <input type="file" name="file_excel" class="form-control-modern"
                           accept=".xlsx,.xlsm,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                </div>
                <button type="submit" class="btn btn-brand w-100">
                    <i class="bi bi-magic"></i> Proses &amp; Tinjau Hasil
                </button>
            </form>

            <div class="mt-3" style="font-size:12.5px; color:var(--muted);">
                Sama seperti import PDF, hasil pembacaan <strong>tidak langsung tersimpan</strong> — ditampilkan dulu
                di halaman review untuk dicek/diedit. Maksimal <?= (int)EXCEL_MAX_SOAL ?> soal per sekali import.
            </div>

            <div class="mt-3 pt-3 border-top" style="font-size:12.5px;">
                Soalnya berbentuk PDF?
                <a href="import_soal.php?tes_id=<?= $tesId ?>" class="fw-semibold" style="color:var(--brand);">Import dari PDF &rarr;</a>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-modern" style="background:#f8f8fb;">
            <h6 class="fw-bold mb-2" style="font-size:13.5px;"><i class="bi bi-lightbulb"></i> Format Excel yang didukung</h6>

            <a href="import_excel.php?tes_id=<?= $tesId ?>&template=1" class="btn btn-outline-soft btn-sm w-100 mb-3">
                <i class="bi bi-download"></i> Unduh Template (<?= $isPsikotes ? 'Psikotes' : 'Logika' ?>)
            </a>

            <p style="font-size:12.5px; color:var(--muted);">
                Sistem otomatis mengenali salah satu dari 2 format ini:
            </p>

            <div style="font-size:12px; margin-bottom:10px;">
                <strong>1. Naskah (soal ditulis berurutan ke bawah)</strong>
                <pre style="font-size:12px; white-space:pre-wrap; background:#fff; padding:8px; border-radius:8px; border:1px solid var(--border); margin-top:4px;">1. | Berapa hasil 12 + 8 ?
   | a.  18
   | b.  20
   | c.  22
2. | Ibu kota Indonesia adalah ...
   | a.  Bandung
   | b.  Jakarta</pre>
                <div style="color:var(--muted); margin-top:4px;">
                    Nomor di kolom A, pertanyaan di kolom B (atau satu sel), opsi <em>a. b. c. ...</em> berurutan.
                    Judul bagian ("A. DERET BILANGAN") diabaikan. Teks/tabel di antara soal yang menyebut
                    "nomor 7 — 8" otomatis ditempel ke soal 7 dan 8.
                </div>
            </div>

            <div style="font-size:12px; margin-bottom:6px;">
                <strong>2. Tabel (satu soal per baris)</strong> — baris pertama = judul kolom, urutan kolom bebas:
            </div>

            <?php if ($isPsikotes): ?>
            <pre style="font-size:12px; white-space:pre-wrap; background:#fff; padding:8px; border-radius:8px; border:1px solid var(--border);">No | Pertanyaan | A | B | C | D | E | Dimensi A | Dimensi B | ...
1  | Saat tim...| ...| ...|   |   |   | D         | I         |</pre>
            <ul style="font-size:12px; color:var(--muted); padding-left:18px;">
                <li>Kolom <strong>A</strong> dan <strong>B</strong> wajib, C-E opsional (cukup A/B untuk pilihan paksa).</li>
                <li><strong>Pertanyaan</strong> boleh dikosongkan (mis. Papikostik) — diisi teks bawaan.</li>
                <li><strong>Dimensi A-E</strong> diisi kode dimensi:
                    <code><?= e(implode(', ', $dimensiValid) ?: '—') ?></code>. Kode di luar daftar ini dikosongkan.</li>
                <li>Bobot per opsi (opsional): kolom <strong>Nilai A</strong>–<strong>Nilai E</strong>.</li>
            </ul>
            <?php else: ?>
            <pre style="font-size:12px; white-space:pre-wrap; background:#fff; padding:8px; border-radius:8px; border:1px solid var(--border);">No | Pertanyaan          | A  | B  | C  | D  | E | Kunci
1  | Berapa 12 + 8 ?      | 18 | 20 | 22 | 24 |   | B</pre>
            <ul style="font-size:12px; color:var(--muted); padding-left:18px;">
                <li>Kolom <strong>A</strong> dan <strong>B</strong> wajib, C-E opsional.</li>
                <li><strong>Kunci</strong> berisi huruf A-E (opsional — kalau kosong, pilih di halaman review).</li>
                <li>Format naskah tidak punya kunci jawaban — pilih di halaman review.</li>
            </ul>
            <?php endif; ?>

            <div style="font-size:12px; color:var(--muted);">
                <ul class="mb-0" style="padding-left:18px;">
                    <li>Yang dibaca hanya <strong>sheet pertama</strong>.</li>
                    <li>Gambar di dalam Excel <strong>tidak ikut ter-import</strong> — tambahkan di halaman review.</li>
                    <li>Jawaban multi-pilihan (checkbox) diatur lewat Kelola Soal.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>