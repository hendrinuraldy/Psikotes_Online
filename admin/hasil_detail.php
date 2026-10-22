<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/papikostik_scoring.php';
require_once __DIR__ . '/../includes/disc_scoring.php';
require_once __DIR__ . '/../includes/disc_data.php';
require_once __DIR__ . '/../includes/mbti_data.php';
requireRole('admin');

$db = getDB();
$attemptId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT a.*, u.nama AS nama_peserta, u.email, t.judul AS judul_tes, t.jenis, t.tipe_psikotes
    FROM attempt a
    JOIN users u ON u.id = a.user_id
    JOIN tes t ON t.id = a.tes_id
    WHERE a.id = ?
");
$stmt->execute([$attemptId]);
$attempt = $stmt->fetch();
if (!$attempt) { header('Location: hasil.php'); exit; }

$error = null;
$success = null;

// Hitung ulang rincian skor Papikostik (skor per dimensi 0-9 + interpretasi
// + 3 dimensi dominan) dan simpan ke attempt.detail_skor. Berguna untuk
// attempt lama yang diselesaikan sebelum mesin skor ini lengkap, atau
// setelah bank soal/opsi.dimensi diubah dan skor perlu disegarkan.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hitung_ulang_papikostik') {
    if ($attempt['tipe_psikotes'] !== 'papikostik') {
        $error = 'Aksi ini hanya berlaku untuk tes Papikostik.';
    } else {
        simpanHasilPapikostik($attemptId, $db);

        // Refresh data attempt setelah update
        $stmt = $db->prepare("
            SELECT a.*, u.nama AS nama_peserta, u.email, t.judul AS judul_tes, t.jenis, t.tipe_psikotes
            FROM attempt a
            JOIN users u ON u.id = a.user_id
            JOIN tes t ON t.id = a.tes_id
            WHERE a.id = ?
        ");
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch();

        $success = 'Rincian skor Papikostik berhasil dihitung ulang.';
    }
}
// Hitung ulang DISC dari jawaban tersimpan (status & waktu_selesai tidak diubah)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hitung_ulang_disc') {
    if ($attempt['tipe_psikotes'] !== 'disc') {
        $error = 'Aksi ini hanya berlaku untuk tes DISC.';
    } else {
        simpanHasilDisc($attemptId, $db, true);

        $stmt = $db->prepare("
            SELECT a.*, u.nama AS nama_peserta, u.email, t.judul AS judul_tes, t.jenis, t.tipe_psikotes
            FROM attempt a
            JOIN users u ON u.id = a.user_id
            JOIN tes t ON t.id = a.tes_id
            WHERE a.id = ?
        ");
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch();

        $success = 'Skor DISC berhasil dihitung ulang.';
    }
}

$stmt = $db->prepare("
    SELECT s.id AS soal_id, s.urutan, s.pertanyaan, s.gambar AS gambar_soal,
           o.label, o.teks, o.is_benar, o.nilai, j.tipe_pilihan
    FROM jawaban j
    JOIN soal s ON s.id = j.soal_id
    JOIN opsi o ON o.id = j.opsi_id
    WHERE j.attempt_id = ?
    ORDER BY s.urutan ASC, s.id ASC, j.tipe_pilihan ASC, o.label ASC
");
$stmt->execute([$attemptId]);
$jawaban = $stmt->fetchAll();

// Kelompokkan per soal - satu soal bisa punya lebih dari satu baris
// jawaban: untuk tipe_soal 'multiple' (checkbox, banyak opsi_id sekaligus)
// maupun untuk tes forced-choice DISC (tipe_pilihan 'most' + 'least').
// Ditampilkan bersebelahan dalam satu kartu, bukan sebagai nomor
// soal yang terpisah-pisah.
$jawabanPerSoal = [];
foreach ($jawaban as $j) {
    $sid = $j['soal_id'];
    if (!isset($jawabanPerSoal[$sid])) {
        $jawabanPerSoal[$sid] = [
            'pertanyaan'  => $j['pertanyaan'],
            'gambar_soal' => $j['gambar_soal'],
            'pilihan'     => [],
        ];
    }
    $jawabanPerSoal[$sid]['pilihan'][] = [
        'label'        => $j['label'],
        'teks'         => $j['teks'],
        'is_benar'     => $j['is_benar'],
        'nilai'        => $j['nilai'],
        'tipe_pilihan' => $j['tipe_pilihan'],
    ];
}

// Rincian DISC (kalau ada) - disimpan otomatis oleh includes/disc_scoring.php
// ke attempt.detail_skor sebagai JSON. Yang dipakai di tampilan hanya index
// pola (index_most / index_least / index_selisih); sifat, deskripsi dan job
// match diambil dari includes/disc_data.php (salinan sheet "Def" di Excel).
$rincianDisc = null;
if ($attempt['tipe_psikotes'] === 'disc' && !empty($attempt['detail_skor'])) {
    $rincianDisc = json_decode($attempt['detail_skor'], true);
}

// Profil akhir DISC = Core Private Self (index_least), bukan Mirror.
// Dihitung langsung dari detail_skor sehingga data lama ikut benar
// tanpa perlu "Hitung ulang".
$profilDisc = null;
if ($rincianDisc && isset($rincianDisc['index_least'])) {
    $polaCoreHead = discDataPola((int)$rincianDisc['index_least']);
    $profilDisc = $polaCoreHead['nama'] ?? null;
}

// Rincian skor Papikostik (kalau ada) - disimpan otomatis oleh
// includes/papikostik_scoring.php ke attempt.detail_skor sebagai JSON.
$rincianPapikostik = null;
if ($attempt['tipe_psikotes'] === 'papikostik' && !empty($attempt['detail_skor'])) {
    $rincianPapikostik = json_decode($attempt['detail_skor'], true);
}

// Hasil tes eksternal (16Personalities) - diisi sendiri oleh peserta di
// peserta/tes_eksternal.php: tipe -> attempt.profil_hasil, sisanya di
// attempt.detail_skor = {"tipe16": {"tipe":"ENFJ-T","sisi":["E","N","F","J","T"],
//                                   "persen":[56,53,56,56,56],"link":"https://..."}}
$NAMA_SIFAT16 = ['Energi', 'Pikiran', 'Perasaan', 'Taktik', 'Identitas'];
$LABEL_SISI16 = [
    ['E' => 'Ekstrovert',  'I' => 'Introvert'],
    ['N' => 'Visioner',    'S' => 'Realistik'],
    ['T' => 'Rasional',    'F' => 'Emosional'],
    ['J' => 'Perencanaan', 'P' => 'Improvisasi'],
    ['A' => 'Tegas',       'T' => 'Waspada'],
];

$tipeEksternal   = null;   // mis. "ENFJ-T"
$sisiEksternal   = [];     // 5 kode huruf
$persenEksternal = [];     // 5 angka
$linkEksternal   = null;   // link hasil pribadi
$labelEksternal  = [];     // 5 label sisi yang dipilih
$deskMbti        = null;   // deskripsi tipe dari includes/mbti_data.php (sumber: Word)

if ($attempt['jenis'] === 'eksternal') {
    if (!empty($attempt['detail_skor'])) {
        $dd = json_decode($attempt['detail_skor'], true);
        if (is_array($dd) && isset($dd['tipe16']) && is_array($dd['tipe16'])) {
            $t16 = $dd['tipe16'];
            $tipeEksternal   = $t16['tipe'] ?? null;
            $sisiEksternal   = is_array($t16['sisi'] ?? null) ? array_values($t16['sisi']) : [];
            $persenEksternal = is_array($t16['persen'] ?? null) ? array_values($t16['persen']) : [];
            $linkEksternal   = $t16['link'] ?? null;
        }
    }
    // Fallback: attempt lama yang hanya punya profil_hasil
    if (!$tipeEksternal && !empty($attempt['profil_hasil'])) {
        $tipeEksternal = $attempt['profil_hasil'];
    }
    // Kalau 'sisi' tidak tersimpan (data lama), turunkan dari tipe
    if (count($sisiEksternal) !== 5 && $tipeEksternal
        && preg_match('/^([EI])([NS])([TF])([JP])-([AT])$/', strtoupper($tipeEksternal), $mm)) {
        $sisiEksternal = [$mm[1], $mm[2], $mm[3], $mm[4], $mm[5]];
    }
    if (count($sisiEksternal) === 5) {
        foreach ($sisiEksternal as $i => $kode) {
            $labelEksternal[$i] = $LABEL_SISI16[$i][$kode] ?? $kode;
        }
    }
    // Hanya tampilkan link yang benar-benar dari 16personalities.com
    if ($linkEksternal) {
        $pl = parse_url($linkEksternal);
        if (($pl['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($pl['host'] ?? ''), ['16personalities.com', 'www.16personalities.com'], true)) {
            $linkEksternal = null;
        }
    }
    // Deskripsi tipe (4 huruf pertama, mis. ENFJ dari ENFJ-T)
    if ($tipeEksternal) {
        $deskMbti = mbtiDataTipe($tipeEksternal);
    }
}
$warnaEksternal = ['#4298b4', '#e4ae3a', '#33a474', '#88619a', '#f25e62'];

// Rincian konversi IQ (WPT / CFIT) - disimpan oleh includes/wpt_scoring.php
// ke attempt.iq_score / iq_klasifikasi dan attempt.detail_skor['wpt'] atau ['cfit'].
$iqScore = $attempt['iq_score'] ?? null;
$iqKlasifikasi = $attempt['iq_klasifikasi'] ?? null;
$rincianWpt = null;
$jenisIq = 'WPT'; // 'WPT' (kognitif/logika) atau 'CFIT' (grafis)
if ($attempt['jenis'] === 'logika' && !empty($attempt['detail_skor'])) {
    $tmpDetail = json_decode($attempt['detail_skor'], true);
    if (is_array($tmpDetail)) {
        if (isset($tmpDetail['wpt']) && is_array($tmpDetail['wpt'])) {
            $rincianWpt = $tmpDetail['wpt'];
            $jenisIq = 'WPT';
        } elseif (isset($tmpDetail['cfit']) && is_array($tmpDetail['cfit'])) {
            $rincianWpt = $tmpDetail['cfit'];
            $jenisIq = 'CFIT';
        }
    }
}
// Judul / tipe tes menentukan label (lebih akurat daripada key di detail_skor)
$teksPenentu = strtolower(($attempt['tipe_psikotes'] ?? '') . ' ' . ($attempt['judul_tes'] ?? ''));
if (strpos($teksPenentu, 'cfit') !== false) {
    $jenisIq = 'CFIT';
} elseif (strpos($teksPenentu, 'wpt') !== false) {
    $jenisIq = 'WPT';
}
$jenisIqLabel = $jenisIq === 'CFIT' ? 'CFIT - Grafis' : 'WPT - Logika';

// Counter urutan animasi kartu (dipakai sebagai --d di setiap kartu)
$ai = 0;

$pageTitle = 'Detail Hasil';
$pageDesc = e($attempt['nama_peserta']) . ' — ' . e($attempt['judul_tes']);
$activeMenu = 'hasil';
include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    .rumus-table th, .rumus-table td { text-align:center; font-size:13.5px; }
    .rumus-table th.dim-label, .rumus-table td.dim-label { text-align:left; font-weight:600; }
    .badge-huruf { display:inline-flex; width:24px; height:24px; border-radius:6px; background:#1e293b; color:#fff;
                   align-items:center; justify-content:center; font-weight:700; font-size:12.5px; }
    .pilihan-row + .pilihan-row {
        margin-top: 6px;
    }
    .badge-tipe-pilihan {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        padding: 2px 6px;
        border-radius: 5px;
        background: #eef2ff;
        color: #4338ca;
        margin-right: 6px;
    }
    .papi-table td.dim-teks { text-align:left; font-size:13px; color:#334155; }
    .papi-table td.dim-skor { font-weight:700; }

    /* Hasil tes eksternal (16Personalities) */
    .tipe16-badge { display:inline-block; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe;
                    font-weight:800; font-size:20px; letter-spacing:.5px; padding:4px 16px; border-radius:10px; }
    .p16-row { margin-bottom:12px; }
    .p16-row .p16-head { display:flex; justify-content:space-between; font-size:13.5px; font-weight:600; color:#334155; margin-bottom:4px; }
    .p16-bar { height:10px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
    .p16-bar > span { display:block; height:100%; border-radius:999px; animation: barGrow .8s cubic-bezier(.22,.9,.36,1) .4s backwards; }
    @keyframes barGrow { from { width:0 !important; } }
    .mbti-desk { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 18px;
                 font-size:14.5px; line-height:1.65; color:#1e293b; text-align:justify; }

    /* ===================== DISC (header hijau soft, badan polos bergaris) ===================== */
    .disc-table { width:100%; border-collapse:collapse; table-layout:fixed; }
    .disc-table th { background:#dcfce7; color:#166534; font-weight:800; font-size:14px;
                     padding:9px 12px; text-align:left; border:1px solid #86efac; }
    .disc-table td { background:#ffffff; color:#334155; font-size:14px; padding:7px 12px;
                     border:1px solid #cbd5e1; vertical-align:top; }
    .disc-table td.disc-kom { font-size:12.5px; color:#64748b; font-weight:600; }
    .disc-table td.disc-nama { color:#0f172a; font-weight:800; text-transform:uppercase; letter-spacing:.4px; }
    .disc-box { background:#ffffff; border:1px solid #86efac; border-radius:0 0 6px 6px; padding:12px 16px;
                text-align:justify; font-size:14.5px; line-height:1.55; color:#1e293b; }
    .disc-label { background:#dcfce7; color:#166534; font-weight:800; font-size:14px;
                  margin:16px 0 0; padding:7px 12px; border:1px solid #86efac; border-bottom:0;
                  border-radius:6px 6px 0 0; }

    /* ===================== ANIMASI ===================== */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes rowIn {
        from { opacity: 0; transform: translateX(-12px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    @keyframes alertDrop {
        from { opacity: 0; transform: translateY(-14px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes popIn {
        0%   { opacity: 0; transform: scale(0.7); }
        60%  { opacity: 1; transform: scale(1.08); }
        100% { opacity: 1; transform: scale(1); }
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    /* Kartu muncul bergantian
       (fill-mode "backwards" supaya efek hover tetap jalan setelah animasi selesai) */
    .card-modern {
        animation: fadeUp 0.55s cubic-bezier(.22,.9,.36,1) backwards;
        animation-delay: calc(0.05s + min(var(--d, 0), 8) * 80ms);
        transition: box-shadow 0.25s ease, transform 0.25s ease;
    }
    .card-modern:hover { box-shadow: 0 10px 25px -8px rgba(15, 23, 42, 0.08); }

    /* Alert turun dari atas */
    .alert { animation: alertDrop 0.45s cubic-bezier(.22,.9,.36,1) backwards; }

    /* Link kembali: panah bergeser saat hover */
    .link-kembali { display: inline-block; transition: transform 0.2s ease, opacity 0.2s ease; }
    .link-kembali:hover { transform: translateX(-4px); }

    /* Angka/profil hasil utama "pop" */
    .stat-value { animation: popIn 0.6s cubic-bezier(.22,.9,.36,1) 0.35s backwards; }

    /* Baris tabel rincian masuk satu per satu */
    .rumus-table tbody tr,
    .papi-table tbody tr {
        animation: rowIn 0.4s ease backwards;
        animation-delay: calc(0.4s + min(var(--i, 0), 20) * 35ms);
        transition: background-color 0.15s ease;
    }
    .rumus-table tbody tr:hover,
    .papi-table tbody tr:hover { background-color: #f8fafc; }
    .badge-huruf { transition: transform 0.2s ease, background-color 0.2s ease; }
    tr:hover .badge-huruf { transform: scale(1.15) rotate(-6deg); background: #4f46e5; }

    /* Jawaban per soal masuk bergantian + hover */
    .jawaban-item {
        border: 1px solid var(--border);
        border-radius: 12px;
        animation: rowIn 0.4s ease backwards;
        animation-delay: calc(0.3s + min(var(--i, 0), 12) * 50ms);
        transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }
    .jawaban-item:hover {
        border-color: #c7d2fe;
        box-shadow: 0 6px 16px -8px rgba(79, 70, 229, 0.25);
        transform: translateX(3px);
    }
    .pilihan-row { transition: transform 0.15s ease; }
    .pilihan-row:hover { transform: translateX(4px); }
    .jawaban-item img { transition: transform 0.25s ease; }
    .jawaban-item img:hover { transform: scale(1.05); }

    /* Tombol: efek angkat & tekan */
    .btn { transition: transform 0.15s ease, box-shadow 0.2s ease, background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease; }
    .btn:hover { transform: translateY(-1px); }
    .btn:active { transform: scale(0.95); }
    .btn i.bi-arrow-repeat { display: inline-block; transition: transform 0.4s ease; }
    .btn:hover i.bi-arrow-repeat { transform: rotate(180deg); }

    /* Input fokus lebih halus */
    .form-control { transition: border-color 0.2s ease, box-shadow 0.25s ease; }

    /* Hormati pengguna yang mematikan animasi di sistemnya */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
    }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <a href="hasil.php" class="link-kembali fw-semibold" style="font-size:13px; color:var(--brand);">&larr; Kembali ke Hasil</a>
    <?php if ($attempt['status'] === 'selesai'): ?>
    <div class="d-flex gap-2">
        <a href="hasil_export_pdf.php?id=<?= (int)$attempt['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Download PDF">
            <i class="bi bi-file-earmark-pdf"></i>
        </a>
        <a href="hasil_export_excel.php?id=<?= (int)$attempt['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Download Excel">
            <i class="bi bi-file-earmark-excel"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mt-3 mb-0" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= e($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mt-3 mb-0" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mt-1 mb-1">
    <div class="col-md-8">
        <div class="card-modern" style="--d: <?= $ai++ ?>">
            <h5 class="fw-bold mb-1"><?= e($attempt['nama_peserta']) ?></h5>
            <p class="text-muted mb-0" style="font-size:13.5px;"><?= e($attempt['email']) ?></p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-modern text-center" style="--d: <?= $ai++ ?>">
            <div class="stat-value" style="font-size:32px;">
                <?php if ($attempt['jenis'] === 'eksternal'): ?>
                    <?= $attempt['profil_hasil'] !== null && $attempt['profil_hasil'] !== ''
                        ? e($attempt['profil_hasil'])
                        : '<span class="text-muted" style="font-size:20px;">Belum diisi</span>' ?>
                <?php elseif ($attempt['jenis'] === 'logika' && $iqScore !== null): ?>
                    IQ <?= (int)$iqScore ?>
                <?php elseif ($attempt['tipe_psikotes'] === 'disc' && $profilDisc): ?>
                    <?= e($profilDisc) ?>
                <?php else: ?>
                    <?= e($attempt['profil_hasil'] ?? $attempt['total_skor']) ?>
                <?php endif; ?>
            </div>
            <div class="stat-label">
                <?php if ($attempt['jenis'] === 'logika' && $iqScore !== null): ?>
                    <?= e($iqKlasifikasi ?? '') ?> (<?= (int)$attempt['total_benar'] ?> benar)
                <?php elseif ($attempt['jenis'] === 'logika'): ?>
                    Total Skor (<?= (int)$attempt['total_benar'] ?> benar)
                <?php elseif ($attempt['jenis'] === 'eksternal'): ?>
                    Tipe Kepribadian (diisi peserta)
                <?php elseif ($attempt['tipe_psikotes'] === 'disc' && $profilDisc): ?>
                    Profil Kepribadian (Core Private Self)
                <?php else: ?>
                    Profil Kepribadian
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($attempt['tipe_psikotes'] === 'papikostik' && !$rincianPapikostik && $attempt['status'] === 'selesai'): ?>
<div class="card-modern mb-3" style="--d: <?= $ai++ ?>">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="text-muted small">
            <i class="bi bi-info-circle me-1"></i>
            Rincian skor per dimensi belum tersedia untuk attempt ini (kemungkinan diselesaikan sebelum mesin skor
            Papikostik ini aktif). Klik tombol di samping untuk menghitungnya dari jawaban yang sudah tersimpan.
        </div>
        <form method="POST" action="hasil_detail.php?id=<?= $attempt['id'] ?>">
            <input type="hidden" name="aksi" value="hitung_ulang_papikostik">
            <button type="submit" class="btn btn-primary btn-sm fw-semibold text-nowrap">
                <i class="bi bi-arrow-repeat me-1"></i> Hitung Skor Papikostik
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($rincianWpt): ?>
<div class="card-modern mb-3" style="--d: <?= $ai++ ?>">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-bold mb-0"><i class="bi bi-calculator me-1 text-primary"></i> Rincian Konversi IQ (<?= e($jenisIqLabel) ?>)</h6>
    </div>

    <p class="text-muted small mb-3">
        Skor mentah adalah jumlah jawaban benar. Ke skor mentah ditambahkan koreksi usia,
        lalu hasilnya dicocokkan ke tabel konversi <?= e($jenisIq) ?> untuk mendapat IQ.
    </p>

    <div class="table-responsive mb-3">
        <table class="table table-bordered rumus-table mb-0">
            <thead class="table-light">
                <tr>
                    <th>Skor Mentah (Benar)</th>
                    <th>Usia</th>
                    <th>Koreksi Usia</th>
                    <th>Skor Setelah Koreksi</th>
                    <th>IQ</th>
                    <th>Klasifikasi</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= (int)($rincianWpt['skor_mentah'] ?? 0) ?></td>
                    <td><?= isset($rincianWpt['usia']) && $rincianWpt['usia'] !== null ? (int)$rincianWpt['usia'] . ' th' : '<span class="text-muted">tidak diketahui</span>' ?></td>
                    <td>+<?= (int)($rincianWpt['koreksi_usia'] ?? 0) ?></td>
                    <td><?= (int)($rincianWpt['skor_koreksi'] ?? 0) ?></td>
                    <td class="fw-bold"><?= (int)($rincianWpt['iq'] ?? 0) ?></td>
                    <td class="fw-semibold"><?= e($rincianWpt['klasifikasi'] ?? '-') ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if (!isset($rincianWpt['usia']) || $rincianWpt['usia'] === null): ?>
        <div class="alert alert-warning border-0 small mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Usia peserta tidak tercatat, jadi koreksi usia dianggap 0 (setara usia 16–29 tahun).
        </div>
    <?php elseif ((int)($rincianWpt['skor_koreksi'] ?? 0) > 44): ?>
        <div class="alert alert-light border small mb-0" style="background:#f8fafc;">
            Tabel konversi <?= e($jenisIq) ?> hanya mencakup skor sampai 44, jadi IQ dibatasi pada nilai tertinggi tabel.
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($rincianDisc): ?>
<?php
    // Data dari sheet "Def" (sama seperti HLOOKUP di Excel): index pola -> sifat, deskripsi, job match
    $defMask   = discDataPola(isset($rincianDisc['index_most'])    ? (int)$rincianDisc['index_most']    : null);
    $defCore   = discDataPola(isset($rincianDisc['index_least'])   ? (int)$rincianDisc['index_least']   : null);
    $defMirror = discDataPola(isset($rincianDisc['index_selisih']) ? (int)$rincianDisc['index_selisih'] : null);

    // 3 kolom tabel DISC: Mask, Core (profil akhir), Mirror
    $kolomDisc = [
        ['judul' => 'Mask Public Self',                   'kom' => DISC_KOMBINASI_40[$rincianDisc['index_most']    ?? 0] ?? '-', 'def' => $defMask],
        ['judul' => 'Core Private Self (Profil Akhir)',   'kom' => DISC_KOMBINASI_40[$rincianDisc['index_least']   ?? 0] ?? '-', 'def' => $defCore],
        ['judul' => 'Mirror Perceived Self',              'kom' => DISC_KOMBINASI_40[$rincianDisc['index_selisih'] ?? 0] ?? '-', 'def' => $defMirror],
    ];
    $maxSifat = 0;
    foreach ($kolomDisc as $k) {
        $maxSifat = max($maxSifat, count($k['def']['sifat'] ?? []));
    }
?>
<div class="card-modern mb-3" style="--d: <?= $ai++ ?>">
    <div class="d-flex align-items-center gap-3 mb-3">
        <form method="POST" action="hasil_detail.php?id=<?= (int)$attempt['id'] ?>" class="m-0">
            <input type="hidden" name="aksi" value="hitung_ulang_disc">
            <button type="submit" class="btn btn-link btn-sm p-0 small text-decoration-none" style="color:var(--brand);">
                <i class="bi bi-arrow-repeat me-1"></i>Hitung ulang
            </button>
        </form>
        <a href="panduan_rumus.php" class="small" style="color:var(--brand);">Baca cara kerja rumus ini &rarr;</a>
    </div>

    <div class="table-responsive mb-3">
        <table class="disc-table">
            <thead>
                <tr>
                    <?php foreach ($kolomDisc as $k): ?>
                        <th><?= e($k['judul']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach ($kolomDisc as $k): ?>
                        <td class="disc-kom">Kombinasi: <?= e($k['kom']) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ($kolomDisc as $k): ?>
                        <td class="disc-nama"><?= e($k['def']['nama'] ?? '#N/A') ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php for ($r = 0; $r < $maxSifat; $r++): ?>
                <tr>
                    <?php foreach ($kolomDisc as $k): ?>
                        <td><?= e($k['def']['sifat'][$r] ?? '') ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <div class="disc-label">Deskripsi Kepribadian</div>
    <div class="disc-box mb-2">
        <?= $defCore ? e($defCore['deskripsi']) : '<span class="text-muted">Deskripsi tidak tersedia (pola tidak ditemukan).</span>' ?>
    </div>

    <div class="disc-label">Job Match :</div>
    <div class="disc-box">
        <?= $defCore ? e($defCore['job']) : '<span class="text-muted">Job match tidak tersedia (pola tidak ditemukan).</span>' ?>
    </div>

    <div class="text-muted small mt-3">
        <i class="bi bi-info-circle me-1"></i>
        Profil akhir, deskripsi, dan job match di atas diambil dari pola grafik <strong>Core Private Self</strong>;
        Mask dan Mirror hanya menampilkan nama pola &amp; sifatnya.
    </div>
</div>
<?php endif; ?>

<?php if ($rincianPapikostik): ?>
<div class="card-modern mb-3" style="--d: <?= $ai++ ?>">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h6 class="fw-bold mb-0"><i class="bi bi-calculator me-1 text-primary"></i> Rincian Skor Papikostik (PAPI Kostick)</h6>
    <div class="d-flex align-items-center gap-3">
        <form method="POST" action="hasil_detail.php?id=<?= $attempt['id'] ?>" class="m-0">
            <input type="hidden" name="aksi" value="hitung_ulang_papikostik">
            <button type="submit" class="btn btn-link btn-sm p-0 small text-decoration-none" style="color:var(--brand);">
                <i class="bi bi-arrow-repeat me-1"></i>Hitung ulang
            </button>
        </form>
        <a href="panduan_papikostik.php" class="small text-decoration-none" style="color:var(--brand);">
            <i class="bi bi-book me-1"></i>Panduan rumus
        </a>
    </div>
</div>

    <div class="table-responsive mb-3">
        <table class="table table-bordered papi-table mb-0">
            <thead class="table-light">
                <tr>
                    <th class="dim-label" style="width:70px;">Dim.</th>
                    <th style="text-align:left;">Nama Dimensi</th>
                    <th style="width:70px;">Skor</th>
                    <th style="text-align:left;">Interpretasi</th>
                </tr>
            </thead>
            <tbody>
                <?php $pi = 0; foreach (PAPIKOSTIK_DIMENSI as $h): ?>
                <tr style="--i: <?= $pi++ ?>">
                    <td class="dim-label"><span class="badge-huruf"><?= e($h) ?></span></td>
                    <td class="dim-teks"><?= e(PAPIKOSTIK_LABEL[$h] ?? $h) ?></td>
                    <td class="dim-skor"><?= (int)($rincianPapikostik['skor'][$h] ?? 0) ?></td>
                    <td class="dim-teks"><?= e($rincianPapikostik['interpretasi'][$h] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="alert alert-light border small mb-0" style="background:#f8fafc;">
        3 dimensi dengan skor tertinggi:
        <strong><?= e(implode(', ', $rincianPapikostik['dominan'] ?? [])) ?></strong>.
        Interpretasi di atas mengikuti rentang skor pada panduan skoring Papikostik internal.
    </div>
</div>
<?php endif; ?>

<?php if ($attempt['jenis'] === 'eksternal'): ?>

<!-- Hasil yang diisi peserta (tipe + 5 persentase) -->
<div class="card-modern mb-3" style="--d: <?= $ai++ ?>">
    <h6 class="fw-bold mb-3"><i class="bi bi-person-check me-1 text-primary"></i> Hasil yang Diisi Peserta</h6>
    <?php if ($tipeEksternal): ?>
        <div class="mb-3">
            <span class="text-muted small me-2">Tipe kepribadian</span>
            <span class="tipe16-badge"><?= e(strtoupper($tipeEksternal)) ?></span>
        </div>

        <?php if (!empty($persenEksternal)): ?>
            <?php foreach ($persenEksternal as $i => $p): $p = max(0, min(100, (int)$p)); ?>
            <div class="p16-row">
                <div class="p16-head">
                    <span>
                        <span class="text-muted fw-normal"><?= e($NAMA_SIFAT16[$i] ?? 'Sifat ' . ($i + 1)) ?>:</span>
                        <?= e($labelEksternal[$i] ?? '-') ?>
                    </span>
                    <span><?= $p ?>%</span>
                </div>
                <div class="p16-bar"><span style="width:<?= $p ?>%; background:<?= e($warnaEksternal[$i] ?? '#2563eb') ?>;"></span></div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-light border small mb-0" style="background:#f8fafc;">
                Persentase sifat tidak tersimpan untuk attempt ini (kemungkinan diselesaikan sebelum form persentase ada).
            </div>
        <?php endif; ?>

        <div class="text-muted small mt-3">
            <i class="bi bi-info-circle me-1"></i>
            Data ini diisi sendiri oleh peserta. Cocokkan dengan halaman hasil pada link di bawah untuk memastikan kebenarannya.
        </div>
    <?php else: ?>
        <div class="alert alert-warning border-0 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Peserta belum mengisi tipe dan persentase (attempt mungkin ditandai selesai otomatis karena waktu habis).
        </div>
    <?php endif; ?>
</div>

<!-- Deskripsi tipe kepribadian (dari dokumen Word: includes/mbti_data.php) -->
<?php if ($tipeEksternal): ?>
<div class="card-modern mb-3" style="--d: <?= $ai++ ?>">
    <h6 class="fw-bold mb-3"><i class="bi bi-file-text me-1 text-primary"></i> Deskripsi Tipe Kepribadian</h6>
    <?php if ($deskMbti): ?>
        <div class="mb-3">
            <span class="tipe16-badge"><?= e(strtoupper($tipeEksternal)) ?></span>
            <span class="fw-semibold ms-2"><?= e($deskMbti['nama']) ?></span>
        </div>
        <div class="mbti-desk"><?= e($deskMbti['deskripsi']) ?></div>
        <div class="text-muted small mt-3">
            <i class="bi bi-info-circle me-1"></i>
            Deskripsi mengikuti 4 huruf pertama tipe (mis. ENFJ). Akhiran -A / -T tidak mengubah deskripsi.
        </div>
    <?php else: ?>
        <div class="alert alert-warning border-0 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Deskripsi untuk tipe <strong><?= e($tipeEksternal) ?></strong> tidak ditemukan di data.
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Link hasil pribadi -->
<div class="card-modern mb-3" style="--d: <?= $ai++ ?>">
    <h6 class="fw-bold mb-3"><i class="bi bi-link-45deg me-1 text-primary"></i> Link Hasil Pribadi 16Personalities</h6>
    <?php if ($linkEksternal): ?>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= e($linkEksternal) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm fw-semibold">
                <i class="bi bi-box-arrow-up-right me-1"></i> Buka Hasil Peserta
            </a>
            <code class="small text-break"><?= e($linkEksternal) ?></code>
        </div>
        <div class="text-muted small mt-2">
            Buka link ini dan cocokkan tipe serta persentase dengan yang diisi peserta di atas.
        </div>
    <?php else: ?>
        <div class="alert alert-warning border-0 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Peserta belum menempelkan link hasil (attempt mungkin ditandai selesai otomatis karena waktu habis,
            atau diselesaikan sebelum fitur link ada).
        </div>
    <?php endif; ?>
</div>

<?php else: ?>

<div class="card-modern" style="--d: <?= $ai++ ?>">
    <h6 class="fw-bold mb-3">Jawaban (<?= count($jawabanPerSoal) ?> soal)</h6>
    <?php if (empty($jawabanPerSoal)): ?>
        <div class="text-center py-4 text-muted small">
            <i class="bi bi-inbox fs-2 opacity-50 d-block mb-2"></i>
            Belum ada jawaban tercatat untuk attempt ini.
        </div>
    <?php else: ?>
        <?php $idx = 0; foreach ($jawabanPerSoal as $j): $idx++; ?>
        <div class="jawaban-item mb-3 p-3" style="--i: <?= $idx - 1 ?>">
            <div class="fw-semibold mb-2">#<?= $idx ?>. <?= e($j['pertanyaan']) ?></div>
            <?php if ($j['gambar_soal']): ?>
                <img src="../uploads/soal/<?= e($j['gambar_soal']) ?>" style="max-width:160px; border-radius:8px; margin-bottom:8px;">
            <?php endif; ?>

            <?php foreach ($j['pilihan'] as $p): ?>
                <div class="pilihan-row" style="font-size:13.5px; padding:8px 12px; border-radius:8px; background:<?= $p['is_benar'] ? '#ecfdf5' : ($attempt['jenis'] === 'logika' ? '#fef2f2' : '#f8f8fb') ?>;">
                    <?php if ($p['tipe_pilihan'] !== 'single'): ?>
                        <span class="badge-tipe-pilihan"><?= e($p['tipe_pilihan']) ?></span>
                    <?php endif; ?>
                    Jawaban dipilih: <strong><?= e($p['label']) ?>. <?= e($p['teks']) ?></strong>
                    <?php if ($attempt['jenis'] === 'logika'): ?>
                        <?= $p['is_benar'] ? '<span class="text-success">(Benar)</span>' : '<span class="text-danger">(Salah)</span>' ?>
                    <?php else: ?>
                        <span class="text-muted">(nilai: <?= (int)$p['nilai'] ?>)</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>