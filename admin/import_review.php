<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();
$draftDir = __DIR__ . '/../uploads/import_draft/';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$token = preg_replace('/[^a-zA-Z0-9_\.]/', '', $token); // sanitasi ketat, ini dipakai sebagai nama file

$draftFile = $draftDir . $token . '.json';
if ($token === '' || !is_file($draftFile)) {
    header('Location: tes.php');
    exit;
}

$draft = json_decode(file_get_contents($draftFile), true);
$tesId = (int)($draft['tes_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tes WHERE id = ?");
$stmt->execute([$tesId]);
$tes = $stmt->fetch();
if (!$tes) { @unlink($draftFile); header('Location: tes.php'); exit; }

$uploadDir = __DIR__ . '/../uploads/soal/';
$error = null;

/**
 * Ambil satu sub-array file dari struktur $_FILES bentuk nested
 * (mis. name="item[3][gambar_soal]"), lalu kembalikan sebagai array
 * file tunggal yang siap dipakai fungsi upload biasa.
 */
function ambilFileNestedTunggal(array $itemFiles, string $field): ?array {
    if (empty($itemFiles['name'][$field])) return null;
    return [
        'name'     => $itemFiles['name'][$field],
        'type'     => $itemFiles['type'][$field] ?? '',
        'tmp_name' => $itemFiles['tmp_name'][$field] ?? '',
        'error'    => $itemFiles['error'][$field] ?? UPLOAD_ERR_NO_FILE,
        'size'     => $itemFiles['size'][$field] ?? 0,
    ];
}

/**
 * Sama seperti di atas, tapi untuk field yang berupa array per-opsi
 * (mis. name="item[3][opsi_gambar][]"), ambil elemen ke-$i saja.
 */
function ambilFileNestedOpsi(array $itemFiles, string $field, int $i): ?array {
    if (empty($itemFiles['name'][$field][$i])) return null;
    return [
        'name'     => $itemFiles['name'][$field][$i],
        'type'     => $itemFiles['type'][$field][$i] ?? '',
        'tmp_name' => $itemFiles['tmp_name'][$field][$i] ?? '',
        'error'    => $itemFiles['error'][$field][$i] ?? UPLOAD_ERR_NO_FILE,
        'size'     => $itemFiles['size'][$field][$i] ?? 0,
    ];
}

/** Simpan satu file gambar hasil upload (array bentuk $_FILES biasa) ke $uploadDir. */
function simpanGambarDraft(?array $file, string $uploadDir): ?string {
    if (!$file || empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return null;
    $newName = uniqid('img_', true) . '.' . $ext;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) return null;
    return $newName;
}

// ============== SIMPAN (setelah admin review & submit) ==============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_import') {
    $items = $_POST['item'] ?? []; // array per blok soal
    $filesItem = $_FILES['item'] ?? null; // struktur nested $_FILES, sejajar index dengan $items
    $disimpan = 0;

    $db->beginTransaction();

    $urutanStmt = $db->prepare("SELECT COALESCE(MAX(urutan),0) AS m FROM soal WHERE tes_id = ?");
    $urutanStmt->execute([$tesId]);
    $urutan = (int)$urutanStmt->fetch()['m'];

    $labels = ['A', 'B', 'C', 'D', 'E'];

    foreach ($items as $idx => $item) {
        if (empty($item['sertakan'])) continue; // checkbox "sertakan soal ini" tidak dicentang -> skip

        $pertanyaan = trim($item['pertanyaan'] ?? '');
        if ($pertanyaan === '') continue;

        // File untuk item ini (kalau ada apa pun yang diupload di blok soal #$idx)
        $itemFiles = $filesItem['name'][$idx] ?? null ? [
            'name'     => $filesItem['name'][$idx],
            'type'     => $filesItem['type'][$idx],
            'tmp_name' => $filesItem['tmp_name'][$idx],
            'error'    => $filesItem['error'][$idx],
            'size'     => $filesItem['size'][$idx],
        ] : null;

        $gambarSoal = $itemFiles ? simpanGambarDraft(ambilFileNestedTunggal($itemFiles, 'gambar_soal'), $uploadDir) : null;

        $urutan++;
        $stmt = $db->prepare("INSERT INTO soal (tes_id, pertanyaan, gambar, urutan) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tesId, $pertanyaan, $gambarSoal, $urutan]);
        $soalId = $db->lastInsertId();

        $opsiTeks = $item['opsi_teks'] ?? [];
        $opsiNilai = $item['opsi_nilai'] ?? [];
        $opsiDimensi = $item['opsi_dimensi'] ?? [];
        $jawabanBenar = $item['jawaban_benar'] ?? null;

        foreach ($labels as $i => $label) {
            $teks = trim($opsiTeks[$i] ?? '');
            $teks = mb_substr($teks, 0, 255);

            $gambarOpsi = $itemFiles ? simpanGambarDraft(ambilFileNestedOpsi($itemFiles, 'opsi_gambar', $i), $uploadDir) : null;

            // Lewati opsi yang benar-benar kosong (tanpa teks maupun gambar)
            if ($teks === '' && empty($gambarOpsi)) continue;

            if ($tes['jenis'] === 'logika') {
                $isBenar = ($jawabanBenar !== null && (string)$jawabanBenar === (string)$i) ? 1 : 0;
                $nilai = $isBenar ? 1 : 0;
                $dimensi = null;
            } else {
                $isBenar = 0;
                $nilai = (int)($opsiNilai[$i] ?? 0);
                $dimensi = trim($opsiDimensi[$i] ?? '') !== '' ? strtoupper(trim($opsiDimensi[$i])) : null;
            }

            $stmt = $db->prepare("INSERT INTO opsi (soal_id, label, teks, gambar, is_benar, nilai, dimensi) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$soalId, $label, $teks, $gambarOpsi, $isBenar, $nilai, $dimensi]);
        }

        $disimpan++;
    }

    $db->commit();
    @unlink($draftFile);

    header('Location: soal.php?tes_id=' . $tesId . '&diimpor=' . $disimpan);
    exit;
}

// ============== BATAL ==============
if (isset($_GET['batal'])) {
    @unlink($draftFile);
    header('Location: soal.php?tes_id=' . $tesId);
    exit;
}

$blocks = $draft['blocks'] ?? [];
$warnings = $draft['warnings'] ?? [];
$isPsikotes = $tes['jenis'] === 'psikotes';

// Draft dari Excel → "Upload ulang" kembali ke halaman import Excel, selain itu ke import PDF
$asalExcel = strpos((string)($draft['metode'] ?? ''), 'excel') === 0;
$linkUlang = ($asalExcel ? 'import_excel.php' : 'import_soal.php') . '?tes_id=' . $tesId;

$pageTitle = 'Review Hasil Import ' . ($asalExcel ? 'Excel' : 'PDF');
$pageDesc = $tes['judul'] . ' &middot; ' . count($blocks) . ' soal terdeteksi';
$activeMenu = 'tes';
include __DIR__ . '/../includes/layout_top.php';
?>

<a href="<?= e($linkUlang) ?>" class="fw-semibold" style="font-size:13px; color:var(--brand);">&larr; Upload ulang</a>

<div class="alert alert-warning py-2 mt-2 mb-3" style="font-size:13px;">
    <i class="bi bi-exclamation-triangle"></i>
    Metode baca: <strong><?= e($draft['metode'] ?? '-') ?></strong>.
    Ini hasil deteksi otomatis dan <strong>belum tersimpan</strong> — cek tiap soal & opsi di bawah,
    perbaiki kalau ada yang salah baca, tambahkan gambar kalau soal/opsinya butuh gambar,
    hilangkan centang "Sertakan" untuk soal yang tidak valid,
    lalu klik <strong>Simpan Semua</strong> di paling bawah.
</div>

<?php if (!empty($warnings)): ?>
    <div class="alert alert-info py-2 mb-3" style="font-size:12.5px;">
        <strong><i class="bi bi-info-circle"></i> Catatan dari pembacaan file:</strong>
        <ul class="mb-0 mt-1" style="padding-left:18px;">
            <?php foreach ($warnings as $w): ?>
                <li><?= e($w) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" id="formReview" enctype="multipart/form-data">
    <input type="hidden" name="aksi" value="simpan_import">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <?php if (empty($blocks)): ?>
        <div class="card-modern text-center text-muted py-4">Tidak ada soal terdeteksi.</div>
    <?php endif; ?>

    <?php foreach ($blocks as $idx => $b): ?>
    <div class="card-modern mb-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <label class="fw-bold d-flex align-items-center gap-2" style="font-size:13.5px;">
                <input type="checkbox" name="item[<?= $idx ?>][sertakan]" value="1" checked>
                Soal #<?= $idx + 1 ?>
                <?php if (count($b['opsi']) < 2): ?><span class="badge-soft" style="background:#fef2f2;color:#991b1b;">Opsi kurang, cek manual</span><?php endif; ?>
            </label>
        </div>

        <div class="mb-2">
            <label class="form-label-modern">Pertanyaan</label>
            <textarea name="item[<?= $idx ?>][pertanyaan]" class="form-control-modern" rows="2"><?= e($b['pertanyaan']) ?></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label-modern">Gambar Pertanyaan (opsional — file import tidak menyertakan gambar, tambahkan manual di sini kalau perlu)</label>
            <input type="file" name="item[<?= $idx ?>][gambar_soal]" class="form-control-modern" accept="image/*">
        </div>

        <?php
        // Pastikan selalu render 5 slot opsi (A-E) walau hasil parsing kurang dari 5
        $opsiSlot = $b['opsi'];
        $labelsAll = ['A', 'B', 'C', 'D', 'E'];
        while (count($opsiSlot) < 5) { $opsiSlot[] = ['label' => $labelsAll[count($opsiSlot)], 'teks' => '']; }
        ?>
        <div class="row g-2">
            <?php foreach ($opsiSlot as $i => $o): ?>
            <div class="col-md-6">
                <div class="d-flex gap-2 align-items-start p-2" style="border:1px solid var(--border); border-radius:10px;">
                    <div class="option-letter"><?= $o['label'] ?></div>
                    <div class="flex-grow-1">
                        <input type="text" name="item[<?= $idx ?>][opsi_teks][]" class="form-control-modern mb-2" value="<?= e($o['teks']) ?>" placeholder="Teks opsi <?= $o['label'] ?> (kosongkan kalau tidak dipakai)">
                        <div class="mb-2">
                            <input type="file" name="item[<?= $idx ?>][opsi_gambar][]" class="form-control-modern" accept="image/*" style="font-size:12px; padding:6px 8px;">
                        </div>
                        <?php if ($isPsikotes): ?>
                            <div class="d-flex gap-2">
                                <label style="font-size:12px;">Nilai:
                                    <input type="number" name="item[<?= $idx ?>][opsi_nilai][]" class="form-control-modern d-inline-block" style="width:70px; padding:6px 8px;" value="<?= (int)($o['nilai'] ?? 0) ?>">
                                </label>
                                <label style="font-size:12px;">Dimensi:
                                    <input type="text" name="item[<?= $idx ?>][opsi_dimensi][]" class="form-control-modern d-inline-block" style="width:60px; padding:6px 8px;" maxlength="2" value="<?= e($o['dimensi'] ?? '') ?>" placeholder="<?= e($tes['tipe_psikotes'] ?? 'D/I/S/C') ?>">
                                </label>
                            </div>
                        <?php else: ?>
                            <label style="font-size:12.5px;">
                                <input type="radio" name="item[<?= $idx ?>][jawaban_benar]" value="<?= $i ?>" <?= (isset($b['kunci']) && $b['kunci'] !== null && (int)$b['kunci'] === $i) ? 'checked' : '' ?>> Jawaban benar
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($blocks)): ?>
    <div class="d-flex gap-2 mb-5">
        <button type="submit" class="btn btn-brand flex-grow-1"><i class="bi bi-check2-circle"></i> Simpan Semua Soal yang Dicentang</button>
        <a href="import_review.php?token=<?= e($token) ?>&batal=1" class="btn btn-outline-soft" onclick="return confirm('Batalkan import ini? Draft akan dihapus.');">Batal</a>
    </div>
    <?php endif; ?>
</form>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>