<?php
/**
 * peserta/tes_eksternal.php
 * ---------------------------------------------------------------
 * Tes eksternal 16Personalities:
 *  1. Peserta mengerjakan tes di 16personalities.com.
 *  2. Peserta memilih sisi (mis. Ekstrovert / Introvert) + persentase untuk
 *     5 sifat. Tipe (mis. ENFJ-T) dihitung otomatis dari pilihan tersebut.
 *  3. Peserta menempelkan link hasil pribadi (tombol Bagikan -> link kedua).
 * Data disimpan: attempt.profil_hasil (tipe) dan attempt.detail_skor (JSON:
 * tipe, sisi, persen, link). Admin hanya meninjau (admin/hasil_detail.php).
 * ---------------------------------------------------------------
 */
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');

const LINK_16P = 'https://www.16personalities.com/id/tes-kepribadian';

// Definisi 5 skala: [judul, [kode, label] kiri, [kode, label] kanan]
// Urutan kiri-kanan sama seperti bar di halaman 16Personalities.
$SKALA = [
    ['Energi',     ['E', 'Ekstrovert'],  ['I', 'Introvert']],
    ['Pikiran',    ['N', 'Visioner'],    ['S', 'Realistik']],
    ['Perasaan',   ['T', 'Rasional'],    ['F', 'Emosional']],
    ['Taktik',     ['J', 'Perencanaan'], ['P', 'Improvisasi']],
    ['Identitas',  ['A', 'Tegas'],       ['T', 'Waspada']],
];

$db = getDB();
$userId = $_SESSION['user_id'];
$tesId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tes WHERE id = ? AND status = 'aktif' AND jenis = 'eksternal'");
$stmt->execute([$tesId]);
$tes = $stmt->fetch();
if (!$tes) { header('Location: dashboard.php'); exit; }

$stmt = $db->prepare("SELECT * FROM attempt WHERE user_id = ? AND tes_id = ? AND status = 'berlangsung' ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId, $tesId]);
$attempt = $stmt->fetch();

$stmt = $db->prepare("SELECT id FROM attempt WHERE user_id = ? AND tes_id = ? AND status = 'selesai' ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId, $tesId]);
$selesai = $stmt->fetch();
if ($selesai && !$attempt) {
    header('Location: hasil_detail.php?id=' . $selesai['id']);
    exit;
}

if (!$attempt) {
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT INTO attempt (user_id, tes_id, waktu_mulai, status) VALUES (?, ?, ?, 'berlangsung')");
    $stmt->execute([$userId, $tesId, $now]);
    $attemptId = $db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM attempt WHERE id = ?");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
}

$mulaiTs = strtotime($attempt['waktu_mulai']);
$durasiDetik = (int)$tes['durasi_menit'] * 60;
$sisaDetik = $durasiDetik - (time() - $mulaiTs);

if ($sisaDetik <= 0) {
    $stmt = $db->prepare("UPDATE attempt SET status = 'selesai', waktu_selesai = NOW() WHERE id = ? AND status = 'berlangsung'");
    $stmt->execute([$attempt['id']]);
    header('Location: hasil_detail.php?id=' . $attempt['id']);
    exit;
}

$error = null;
$inSisi   = $_POST['sisi']   ?? [];
$inPersen = $_POST['persen'] ?? ['', '', '', '', ''];
$inLink   = trim($_POST['link_hasil'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'submit_hasil') {
    $sisi = [];
    $persen = [];
    foreach ($SKALA as $i => $sk) {
        $kode = $inSisi[$i] ?? '';
        if (!in_array($kode, [$sk[1][0], $sk[2][0]], true)) { $sisi = null; break; }
        $sisi[] = $kode;
        $v = $inPersen[$i] ?? '';
        if ($v === '' || !ctype_digit((string)$v) || (int)$v < 50 || (int)$v > 100) { $persen = null; break; }
        $persen[] = (int)$v;
    }

    $pu = parse_url($inLink);
    $host = strtolower($pu['host'] ?? '');
    $path = $pu['path'] ?? '';

    if ($sisi === null) {
        $error = 'Masih ada sifat yang belum dipilih. Untuk setiap baris (Energi, Pikiran, Perasaan, Taktik, Identitas), klik salah satu tombol sesuai kata yang tertulis di hasil Anda.';
    } elseif ($persen === null || count($persen) !== 5) {
        $error = 'Angka persentase belum lengkap atau tidak sesuai. Isi kelima kolom dengan angka saja (tanpa tanda %), antara 50 sampai 100.';
    } elseif (($pu['scheme'] ?? '') !== 'https' || !in_array($host, ['16personalities.com', 'www.16personalities.com'], true)) {
        $error = 'Link yang ditempel bukan dari 16Personalities. Link yang benar diawali https://www.16personalities.com/';
    } elseif (!preg_match('#^/(?:[a-z]{2}(?:-[A-Za-z]{2})?/)?profile?/[A-Za-z0-9]+/?$#', $path)) {
        $error = 'Itu bukan link hasil pribadi Anda. Buka lagi jendela "Bagikan hasil Anda", lalu salin link KEDUA yang tertulis "Hasil pribadi Anda" (ada kata "profil" di dalam link). Link pertama tidak bisa dipakai.';
    } else {
        $tipe = $sisi[0] . $sisi[1] . $sisi[2] . $sisi[3] . '-' . $sisi[4];
        $linkBersih = 'https://' . $host . $path;
        $detail = json_encode(['tipe16' => [
            'tipe'   => $tipe,
            'sisi'   => $sisi,
            'persen' => $persen,
            'link'   => $linkBersih,
        ]]);
        $stmt = $db->prepare("UPDATE attempt SET profil_hasil = ?, detail_skor = ?, status = 'selesai', waktu_selesai = NOW() WHERE id = ?");
        $stmt->execute([$tipe, $detail, $attempt['id']]);
        header('Location: hasil_detail.php?id=' . $attempt['id']);
        exit;
    }
}

$pageTitle = $tes['judul'];
$activeMenu = 'dashboard';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    .ext-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(15,23,42,.03); }
    .ext-step-badge { width:30px; height:30px; border-radius:8px; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:.9rem; flex-shrink:0; }

    .skala-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:12px 0; border-bottom:1px solid #f1f5f9; }
    .skala-row:last-of-type { border-bottom:0; }
    .skala-judul { width:90px; font-weight:700; font-size:14px; color:#334155; }
    .skala-judul small { display:block; font-weight:500; font-size:11.5px; color:#94a3b8; }
    .skala-pilih { display:flex; gap:6px; flex-wrap:wrap; flex:1; }
    .skala-persen { width:120px; }
    .kode-huruf { display:inline-block; min-width:20px; margin-left:6px; padding:0 5px; border-radius:6px; font-size:11.5px; font-weight:800; line-height:1.6; text-align:center; background:rgba(37,99,235,.12); color:#1d4ed8; }
    .btn-check:checked + label .kode-huruf { background:rgba(255,255,255,.28); color:#fff; }
    .tipe-preview { display:inline-block; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-weight:800; font-size:18px; letter-spacing:.5px; padding:2px 14px; border-radius:10px; min-width:90px; text-align:center; }

    /* Validasi: baris yang belum diisi */
    .skala-row.is-missing { background:#fef2f2; border-radius:10px; padding-left:10px; padding-right:10px; }
    .skala-row.is-missing .skala-judul { color:#dc2626; }
    .skala-error { color:#dc2626; font-size:12.5px; font-weight:600; width:100%; display:none; }
    .skala-row.is-missing .skala-error { display:block; }
    #alertForm { display:none; }

    .mail-steps { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; margin-bottom:16px; font-size:13.5px; color:#334155; }
    .mail-steps ol { padding-left:1.2rem; margin:8px 0 0; }
    .mail-steps li { margin-bottom:8px; line-height:1.55; }
    .btn-mock { display:inline-block; background:#33a4b4; color:#fff; font-weight:700; font-size:12.5px; padding:2px 10px; border-radius:999px; border:2px solid #0f172a; }
    .contoh-label { font-size:12.5px; font-weight:700; color:#475569; margin-bottom:6px; }
    .contoh-frame { display:block; width:100%; aspect-ratio:4/3; background:#f1f5f9; border:1px solid #e2e8f0 !important; border-radius:10px; overflow:hidden; outline:none !important; transition:box-shadow .2s ease; }
    .contoh-frame:hover { box-shadow:0 8px 20px -8px rgba(15,23,42,.3); }
    .contoh-frame img { width:100%; height:100%; object-fit:contain; display:block; border:0 !important; border-radius:0 !important; }

    /* Kotak panduan tambahan (contoh, tips, peringatan) */
    .panduan-box { border-radius:12px; padding:12px 14px; font-size:13.5px; line-height:1.55; margin-bottom:14px; }
    .panduan-box.contoh { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    .panduan-box.tips   { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .panduan-box.awas   { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .panduan-box .judul { font-weight:700; margin-bottom:4px; }
    .panduan-box ul { margin:6px 0 0; padding-left:1.2rem; }
    .panduan-box li { margin-bottom:3px; }
    .panduan-urutan { font-size:13.5px; color:#334155; margin-bottom:12px; }
    .panduan-urutan .chip { display:inline-block; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:999px; font-size:12px; font-weight:600; padding:1px 9px; margin:2px 2px 2px 0; }
    .contoh-baris { display:inline-block; background:#fff; border:1px solid #bbf7d0; border-radius:8px; padding:2px 10px; font-weight:600; }

    .timer-badge-modern { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:.95rem; font-weight:700; padding:8px 16px; border-radius:10px; display:inline-flex; align-items:center; gap:8px; font-variant-numeric:tabular-nums; }
    .timer-badge-modern.low { background:#fef2f2; color:#dc2626; border-color:#fecaca; animation:pulseTimer 1s infinite alternate; }
    .timer-wrap { display:inline-flex; align-items:center; gap:10px; }
    .timer-label { color:#dc2626; font-size:.85rem; font-weight:700; white-space:nowrap; }
    @keyframes pulseTimer { from { opacity:1; } to { opacity:.55; } }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h5 class="fw-bold text-dark mb-0"><?= e($tes['judul']) ?></h5>
        <span class="text-muted small">Tes eksternal &middot; dikerjakan di 16Personalities, lalu isi hasilnya dan tempel link hasil di sini</span>
    </div>
    <div class="timer-wrap">
        <span class="timer-label">Sisa Waktu</span>
        <div class="timer-badge-modern" id="timerBadge">
            <i class="bi bi-clock-fill"></i>
            <span id="timerText">--:--</span>
        </div>
    </div>
</div>

<?php if ($tes['deskripsi']): ?>
<div class="alert alert-light border mb-3" style="font-size:13.5px;"><?= nl2br(e($tes['deskripsi'])) ?></div>
<?php endif; ?>

<?php if ($error): ?><div class="alert alert-danger py-2" style="font-size:13.5px;"><?= e($error) ?></div><?php endif; ?>

<!-- ============ CARD 1: Buka tes eksternal ============ -->
<div class="ext-card mb-3">
    <div class="d-flex align-items-start gap-3">
        <span class="ext-step-badge">1</span>
        <div class="flex-grow-1">
            <h6 class="fw-bold mb-1">Kerjakan tes di 16Personalities</h6>
            <p class="text-muted small mb-3">
                Klik tombol di bawah untuk membuka tes di tab baru, lalu jawab semua pertanyaan sampai selesai.
                Halaman ini jangan ditutup, karena Anda akan kembali ke sini setelah tes selesai.
                Gunakan email aktif Anda karena hasil tes akan dikirim ke email tersebut.
                Setelah tes selesai, Anda akan melihat halaman hasil. Ikuti langkah 2 dan 3 di bawah untuk mengisinya di sini.
            </p>
            <a href="<?= e(LINK_16P) ?>" target="_blank" rel="noopener" class="btn btn-primary fw-semibold">
                <i class="bi bi-box-arrow-up-right me-1"></i> Buka Tes di Tab Baru
            </a>
        </div>
    </div>
</div>

<form method="POST" id="formHasil" novalidate>
    <input type="hidden" name="aksi" value="submit_hasil">

    <!-- ============ CARD 2: Pilih sisi + persen ============ -->
    <div class="ext-card mb-3">
        <div class="d-flex align-items-start gap-3">
            <span class="ext-step-badge">2</span>
            <div class="flex-grow-1">
                <h6 class="fw-bold mb-1">Isi hasil tes Anda</h6>
                <p class="text-muted small mb-3">
                    Di halaman hasil 16Personalities, Anda akan melihat <strong>5 garis (bar) berwarna</strong> yang masing-masing
                    punya <strong>angka persen</strong> dan <strong>satu kata</strong>, misalnya <em>"56% Introvert"</em>.
                    Salin kelimanya ke form di bawah ini.
                </p>

                <div class="panduan-urutan">
                    <strong>Urutan 5 garis dari atas ke bawah di halaman hasil:</strong><br>
                    <span class="chip">1. Energi</span>
                    <span class="chip">2. Pikiran</span>
                    <span class="chip">3. Perasaan</span>
                    <span class="chip">4. Taktik</span>
                    <span class="chip">5. Identitas</span><br>
                    <span class="text-muted">Urutan baris di form ini sama persis, jadi tinggal isi satu per satu dari atas.</span>
                </div>

                <div class="panduan-box tips">
                    <div class="judul"><i class="bi bi-translate me-1"></i> Arti inisial pada tombol</div>
                    <ul>
                        <li><strong>Energi:</strong> Ekstrovert = E, Introvert = I</li>
                        <li><strong>Pikiran:</strong> Visioner = N, Realistik = S</li>
                        <li><strong>Perasaan:</strong> Rasional = T, Emosional = F</li>
                        <li><strong>Taktik:</strong> Perencanaan = J, Improvisasi = P</li>
                        <li><strong>Identitas:</strong> Tegas = A, Waspada = T</li>
                    </ul>
                </div>

                <div class="panduan-box contoh">
                    <div class="judul"><i class="bi bi-lightbulb-fill me-1"></i> Contoh cara mengisi</div>
                    Di halaman hasil tertulis: <span class="contoh-baris">Energi &mdash; 56% Introvert</span><br>
                    Maka di form ini: pada baris <strong>Energi</strong>, klik tombol <strong>Introvert</strong> (huruf <strong>I</strong>),
                    lalu ketik <strong>56</strong> di kolom persen. Lakukan hal yang sama untuk 4 baris lainnya.
                </div>

                <div class="panduan-box tips">
                    <div class="judul"><i class="bi bi-info-circle-fill me-1"></i> Hal yang perlu diperhatikan</div>
                    <ul>
                        <li>Pilih <strong>sisi yang tertulis di hasil Anda</strong>. Pada setiap garis hanya ada satu kata yang disertai angka persen, itulah yang dipilih.</li>
                        <li>Huruf kecil di samping nama pada tombol adalah <strong>inisialnya</strong> (contoh: Ekstrovert = <strong>E</strong>, Introvert = <strong>I</strong>). Anda tidak perlu menghafalnya, huruf-huruf ini akan otomatis tersusun menjadi tipe kepribadian Anda di bagian bawah.</li>
                        <li>Ketik <strong>angkanya saja</strong>, tanpa tanda %. Angka selalu antara 50 sampai 100.</li>
                        <li>Tulisan di tombol bisa sedikit berbeda dengan di website karena beda terjemahan. Cocokkan dengan <strong>urutan barisnya</strong> dan arti katanya.</li>
                        <li>Setelah semua terisi, lihat kotak <strong>"Tipe kepribadian Anda"</strong> di bawah. Hurufnya harus sama dengan tipe di halaman hasil (contoh: <strong>INFJ-T</strong>). Kalau berbeda, ada pilihan yang salah, silakan periksa lagi.</li>
                    </ul>
                </div>

                <!-- Alert ringkasan validasi -->
                <div class="alert alert-danger py-2" id="alertForm" style="font-size:13.5px;" role="alert"></div>

                <?php foreach ($SKALA as $i => $sk): ?>
                <div class="skala-row">
                    <div class="skala-judul"><?= e($sk[0]) ?><small>Garis ke-<?= $i + 1 ?></small></div>
                    <div class="skala-pilih">
                        <?php foreach ([$sk[1], $sk[2]] as $opt):
                            $idr = 'sisi' . $i . $opt[0] . ($opt === $sk[2] ? 'b' : 'a'); ?>
                            <input type="radio" class="btn-check sisi-radio" name="sisi[<?= $i ?>]" id="<?= $idr ?>"
                                   value="<?= e($opt[0]) ?>" autocomplete="off" required
                                   <?= (($inSisi[$i] ?? '') === $opt[0]) ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary btn-sm fw-semibold" for="<?= $idr ?>"><?= e($opt[1]) ?><span class="kode-huruf"><?= e($opt[0]) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="input-group input-group-sm skala-persen">
                        <input type="number" name="persen[<?= $i ?>]" class="form-control" min="50" max="100" required
                               placeholder="0" value="<?= e($inPersen[$i] ?? '') ?>">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="skala-error" id="err<?= $i ?>"></div>
                </div>
                <?php endforeach; ?>

                <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                    <span class="small text-muted">Tipe kepribadian Anda:</span>
                    <span class="tipe-preview" id="tipePreview">-</span>
                    <span class="small text-muted">(muncul otomatis setelah semua pilihan terisi, cocokkan dengan tipe di halaman hasil)</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ CARD 3: Tempel link hasil ============ -->
    <div class="ext-card">
        <div class="d-flex align-items-start gap-3">
            <span class="ext-step-badge">3</span>
            <div class="flex-grow-1">
                <h6 class="fw-bold mb-1">Tempel link hasil Anda</h6>
                <p class="text-muted small mb-3">
                    Link ini berfungsi sebagai bukti bahwa hasil yang Anda isi di atas benar-benar milik Anda.
                    Caranya mudah, ikuti 4 langkah di bawah ini.
                </p>

                <div class="mail-steps">
                    <div class="fw-semibold"><i class="bi bi-link-45deg me-1 text-primary"></i> Cara mengambil link hasil</div>
                    <ol>
                        <li>
                            Masih di halaman hasil 16Personalities, cari tombol <span class="btn-mock">Bagikan</span>.
                            Biasanya ada di bagian <strong>"Sifat-sifat Kepribadian"</strong> (gulir ke bawah sedikit),
                            atau di menu samping dengan tulisan <strong>"Bagikan hasil Anda"</strong>. Klik tombol tersebut.
                        </li>
                        <li>
                            Akan muncul jendela kecil bernama <strong>"Bagikan hasil Anda"</strong> yang berisi <strong>dua kotak link</strong>.
                            Perhatikan tulisan di atas masing-masing kotak, lalu pilih yang <strong>KEDUA (paling bawah)</strong>,
                            yaitu yang tertulis <strong>"Hasil pribadi Anda"</strong>.
                        </li>
                        <li>
                            Klik tombol <strong>Salin</strong> di sebelah kotak link tersebut.
                            Kalau tidak ada tombolnya, tekan dan tahan tulisan link (di HP) atau blok seluruh link lalu tekan
                            <strong>Ctrl + C</strong> (di komputer), kemudian pilih <strong>Salin</strong>.
                        </li>
                        <li>
                            Kembali ke halaman ini, klik kolom <strong>"Link hasil pribadi Anda"</strong> di bawah, lalu tempel.
                            Di komputer tekan <strong>Ctrl + V</strong>, di HP tekan dan tahan kolomnya lalu pilih <strong>Tempel</strong>.
                        </li>
                    </ol>

                    <div class="panduan-box awas mt-3 mb-2">
                        <div class="judul"><i class="bi bi-exclamation-triangle-fill me-1"></i> Jangan salah ambil link</div>
                        Link <strong>pertama</strong> hanya berisi penjelasan umum tentang tipe kepribadian dan <strong>tidak akan diterima</strong>.
                        Link yang benar ada kata <strong>"profil"</strong> di dalamnya, contohnya:
                        <code>https://www.16personalities.com/id/profil/abc123xyz</code>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <div class="contoh-label">Langkah 1: klik tombol Bagikan</div>
                            <a href="../assets/contoh_bagikan_16p.png" target="_blank" rel="noopener" class="contoh-frame">
                                <img src="../assets/contoh_bagikan_16p.png" alt="Letak tombol Bagikan">
                            </a>
                        </div>
                        <div class="col-md-6">
                            <div class="contoh-label">Langkah 2 &amp; 3: salin link kedua "Hasil pribadi Anda"</div>
                            <a href="../assets/contoh_link_16p.png" target="_blank" rel="noopener" class="contoh-frame">
                                <img src="../assets/contoh_link_16p.png" alt="Link hasil pribadi yang disalin">
                            </a>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">Klik gambar untuk memperbesar.</div>

                    <div class="small text-muted mt-3">
                        <strong>Tombol Bagikan tidak ketemu?</strong> Pastikan tes sudah selesai sampai muncul halaman hasil lengkap
                        (bukan halaman pertanyaan), lalu gulir halaman ke bawah pelan-pelan.
                    </div>
                </div>

                <label class="form-label fw-semibold small mb-1" for="inputLink">Link hasil pribadi Anda</label>
                <input type="url" name="link_hasil" id="inputLink" class="form-control"
                       placeholder="Tempel link di sini, contoh: https://www.16personalities.com/id/profil/..." required
                       value="<?= e($inLink) ?>">
                <div class="text-danger small fw-semibold mt-1 d-none" id="errLink"></div>
                <div class="form-text">Pastikan link diawali <code>https://www.16personalities.com/</code> dan mengandung kata <code>profil</code>.</div>

                <div class="small text-muted mt-3">
                    Sebelum menekan tombol di bawah, periksa sekali lagi isian di langkah 2 dan link di atas.
                    Setelah dikumpulkan, hasil tidak bisa diubah.
                </div>

                <button type="submit" class="btn btn-brand w-100 fw-semibold mt-2">
                    <i class="bi bi-check2-circle me-1"></i> Kumpulkan Hasil & Selesaikan Tes
                </button>
            </div>
        </div>
    </div>
</form>

<script>
const formHasil = document.getElementById('formHasil');

/* Validasi sendiri (form memakai novalidate, karena radio .btn-check
   disembunyikan sehingga bubble bawaan browser tidak muncul). */
function validasiForm() {
    let pertama = null;
    let jumlah = 0;

    document.querySelectorAll('.skala-row').forEach(function (row, i) {
        const sisi = document.querySelector('input[name="sisi[' + i + ']"]:checked');
        const p = document.querySelector('input[name="persen[' + i + ']"]').value.trim();
        let msg = '';

        if (!sisi && p === '') msg = 'Wajib diisi: pilih salah satu sisi dan isi persentase.';
        else if (!sisi) msg = 'Wajib diisi: pilih salah satu sisi.';
        else if (p === '') msg = 'Wajib diisi: persentase belum diisi.';
        else if (!/^\d+$/.test(p) || +p < 50 || +p > 100) msg = 'Persentase harus angka 50 sampai 100.';

        document.getElementById('err' + i).textContent = msg;
        row.classList.toggle('is-missing', msg !== '');
        if (msg) { jumlah++; if (!pertama) pertama = row; }
    });

    const link = document.getElementById('inputLink');
    const errLink = document.getElementById('errLink');
    if (link.value.trim() === '') {
        errLink.textContent = 'Wajib diisi: link hasil pribadi belum ditempel.';
        errLink.classList.remove('d-none');
        link.classList.add('is-invalid');
        jumlah++;
        if (!pertama) pertama = link;
    } else {
        errLink.classList.add('d-none');
        link.classList.remove('is-invalid');
    }

    const alertBox = document.getElementById('alertForm');
    if (jumlah > 0) {
        alertBox.textContent = 'Masih ada ' + jumlah + ' isian yang belum lengkap. Lengkapi bagian yang ditandai merah.';
        alertBox.style.display = 'block';
        pertama.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
    }
    alertBox.style.display = 'none';
    return true;
}

formHasil.addEventListener('submit', function (e) {
    if (!validasiForm()) { e.preventDefault(); return; }
    const btn = this.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Menyimpan...';
});

/* Hilangkan tanda merah begitu baris diperbaiki */
document.querySelectorAll('.skala-row').forEach(function (row) {
    const hapus = function () { row.classList.remove('is-missing'); };
    row.addEventListener('change', hapus);
    row.addEventListener('input', hapus);
});
document.getElementById('inputLink').addEventListener('input', function () {
    this.classList.remove('is-invalid');
    document.getElementById('errLink').classList.add('d-none');
});

/* Tipe dihitung otomatis dari 5 pilihan sisi */
function updateTipe() {
    const v = [];
    for (let i = 0; i < 5; i++) {
        const c = document.querySelector('input[name="sisi[' + i + ']"]:checked');
        v.push(c ? c.value : null);
    }
    const el = document.getElementById('tipePreview');
    if (v.some(x => x === null)) {
        el.textContent = v.slice(0, 4).map(x => x || '_').join('') + '-' + (v[4] || '_');
    } else {
        el.textContent = v[0] + v[1] + v[2] + v[3] + '-' + v[4];
    }
}
document.querySelectorAll('.sisi-radio').forEach(r => r.addEventListener('change', updateTipe));
updateTipe();

/* Timer: saat habis, reload supaya server menandai attempt selesai. */
let sisa = <?= (int)$sisaDetik ?>;
const timerText = document.getElementById('timerText');
const timerBadge = document.getElementById('timerBadge');
function renderExtTimer() {
    const h = Math.floor(sisa / 3600), m = Math.floor((sisa % 3600) / 60), s = sisa % 60;
    timerText.textContent = h > 0
        ? h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
        : String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    if (sisa <= 60) timerBadge.classList.add('low');
}
renderExtTimer();
const extInterval = setInterval(function () {
    sisa--;
    if (sisa <= 0) { clearInterval(extInterval); window.location.reload(); return; }
    renderExtTimer();
}, 1000);
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>