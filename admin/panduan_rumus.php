<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/disc_scoring.php';
require_once __DIR__ . '/../includes/disc_data.php';

requireLogin();

$pageTitle = 'Panduan & Cara Kerja Rumus Penilaian DISC';
$pageDesc = 'Dari jawaban Most/Least sampai gambaran karakter, deskripsi, dan job match.';
$activeMenu = 'hasil';

/**
 * Syarat 40 aturan dalam bentuk data, HANYA untuk ditampilkan di panduan.
 * Sudah dicocokkan satu-satu dengan discKlasifikasiPola() di disc_scoring.php.
 * Penentu hasil sebenarnya tetap fungsi discKlasifikasiPola(); kalau aturan di
 * sana diubah, tabel ini juga harus diperbarui.
 *   pos  = huruf yang harus positif (> 0)
 *   neg  = huruf yang harus nol atau negatif (<= 0)
 *   urut = syarat urutan [A, B] artinya nilai A >= nilai B
 */
const DISC_ATURAN_SPEC = [
    1 => ['pos' => 'C', 'neg' => 'DIS', 'urut' => []],
    2 => ['pos' => 'D', 'neg' => 'ISC', 'urut' => []],
    3 => ['pos' => 'DC', 'neg' => 'IS', 'urut' => [['C','D']]],
    4 => ['pos' => 'DI', 'neg' => 'SC', 'urut' => [['I','D']]],
    5 => ['pos' => 'DIC', 'neg' => 'S', 'urut' => [['I','D'],['D','C']]],
    6 => ['pos' => 'DIS', 'neg' => 'C', 'urut' => [['I','D'],['D','S']]],
    7 => ['pos' => 'DIS', 'neg' => 'C', 'urut' => [['I','S'],['S','D']]],
    8 => ['pos' => 'DSC', 'neg' => 'I', 'urut' => [['S','D'],['D','C']]],
    9 => ['pos' => 'DI', 'neg' => 'SC', 'urut' => [['D','I']]],
    10 => ['pos' => 'DIS', 'neg' => 'C', 'urut' => [['D','I'],['I','S']]],
    11 => ['pos' => 'DS', 'neg' => 'IC', 'urut' => [['D','S']]],
    12 => ['pos' => 'ISC', 'neg' => 'D', 'urut' => [['C','I'],['I','S']]],
    13 => ['pos' => 'ISC', 'neg' => 'D', 'urut' => [['C','S'],['S','I']]],
    14 => ['pos' => 'ISC', 'neg' => 'D', 'urut' => [['I','C'],['I','S']]],
    15 => ['pos' => 'S', 'neg' => 'DIC', 'urut' => []],
    16 => ['pos' => 'SC', 'neg' => 'DI', 'urut' => [['C','S']]],
    17 => ['pos' => 'SC', 'neg' => 'DI', 'urut' => [['S','C']]],
    18 => ['pos' => 'DC', 'neg' => 'IS', 'urut' => [['D','C']]],
    19 => ['pos' => 'DIC', 'neg' => 'S', 'urut' => [['D','I'],['I','C']]],
    20 => ['pos' => 'DIS', 'neg' => 'C', 'urut' => [['D','S'],['S','I']]],
    21 => ['pos' => 'DSC', 'neg' => 'I', 'urut' => [['D','S'],['S','C']]],
    22 => ['pos' => 'DIC', 'neg' => 'S', 'urut' => [['D','C'],['C','I']]],
    23 => ['pos' => 'DSC', 'neg' => 'I', 'urut' => [['D','C'],['C','S']]],
    24 => ['pos' => 'I', 'neg' => 'DSC', 'urut' => []],
    25 => ['pos' => 'IS', 'neg' => 'DC', 'urut' => [['I','S']]],
    26 => ['pos' => 'IC', 'neg' => 'DS', 'urut' => [['I','C']]],
    27 => ['pos' => 'DIC', 'neg' => 'S', 'urut' => [['I','C'],['C','D']]],
    28 => ['pos' => 'ISC', 'neg' => 'D', 'urut' => [['I','C'],['C','S']]],
    29 => ['pos' => 'DS', 'neg' => 'IC', 'urut' => [['S','D']]],
    30 => ['pos' => 'IS', 'neg' => 'DC', 'urut' => [['S','I']]],
    31 => ['pos' => 'DIS', 'neg' => 'C', 'urut' => [['S','D'],['D','I']]],
    32 => ['pos' => 'DIS', 'neg' => 'C', 'urut' => [['S','I'],['I','D']]],
    33 => ['pos' => 'ISC', 'neg' => 'D', 'urut' => [['S','I'],['I','C']]],
    34 => ['pos' => 'DSC', 'neg' => 'I', 'urut' => [['S','C'],['C','D']]],
    35 => ['pos' => 'ISC', 'neg' => 'D', 'urut' => [['S','C'],['C','I']]],
    36 => ['pos' => 'IC', 'neg' => 'DS', 'urut' => [['C','I']]],
    37 => ['pos' => 'DIC', 'neg' => 'S', 'urut' => [['C','D'],['D','I']]],
    38 => ['pos' => 'DSC', 'neg' => 'I', 'urut' => [['C','D'],['D','S']]],
    39 => ['pos' => 'DIC', 'neg' => 'S', 'urut' => [['C','I'],['I','D']]],
    40 => ['pos' => 'DSC', 'neg' => 'I', 'urut' => [['C','S'],['S','D']]],
];

function panduanAngka($x): string
{
    $s = rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

/** Daftar alasan sebuah aturan TIDAK cocok untuk nilai plot $v (kunci D,I,S,C). */
function panduanAlasanTolak(array $sp, array $v): array
{
    $alasan = [];
    foreach (str_split($sp['pos']) as $h) {
        if ($v[$h] <= 0) {
            $alasan[] = $h . ' harus positif, padahal ' . $h . ' = ' . panduanAngka($v[$h]);
        }
    }
    foreach (str_split($sp['neg']) as $h) {
        if ($v[$h] > 0) {
            $alasan[] = $h . ' harus 0 atau negatif, padahal ' . $h . ' = ' . panduanAngka($v[$h]);
        }
    }
    foreach ($sp['urut'] as $u) {
        if ($v[$u[0]] < $v[$u[1]]) {
            $alasan[] = $u[0] . ' harus ≥ ' . $u[1] . ', padahal ' . $u[0] . ' = ' . panduanAngka($v[$u[0]])
                      . ' dan ' . $u[1] . ' = ' . panduanAngka($v[$u[1]]);
        }
    }
    return $alasan;
}

// Kelompokkan 40 aturan menurut huruf yang harus positif
$kelompokAturan = [];
foreach (DISC_ATURAN_SPEC as $no => $sp) {
    $kelompokAturan[$sp['pos']][$no] = $sp;
}
$judulKelompok = [];
foreach (array_keys($kelompokAturan) as $k) {
    $judulKelompok[$k] = implode(' dan ', str_split($k));
}
// urutkan: jumlah huruf lalu abjad DISC
uksort($kelompokAturan, function ($a, $b) {
    $o = ['D' => 0, 'I' => 1, 'S' => 2, 'C' => 3];
    if (strlen($a) !== strlen($b)) return strlen($a) <=> strlen($b);
    $ka = implode('', array_map(fn($h) => $o[$h], str_split($a)));
    $kb = implode('', array_map(fn($h) => $o[$h], str_split($b)));
    return $ka <=> $kb;
});

// Contoh hitungan: dihitung langsung dari tabel di disc_scoring.php
$contohMost  = ['D' => 10, 'I' => 6, 'S' => 4, 'C' => 4];   // total 24
$contohLeast = ['D' => 3,  'I' => 5, 'S' => 6, 'C' => 10];  // total 24
$contohRows = [];
$plotSel = [];
$plotMostC = [];
$plotLeastC = [];
foreach (['D', 'I', 'S', 'C'] as $h) {
    $sel = $contohMost[$h] - $contohLeast[$h];
    $pm = discLookupTabel(DISC_TABEL_MOST, $contohMost[$h])[$h];
    $pl = discLookupTabel(DISC_TABEL_LEAST, $contohLeast[$h])[$h];
    $ps = discLookupTabel(DISC_TABEL_SELISIH, $sel)[$h];
    $plotSel[$h] = $ps;
    $plotMostC[$h] = $pm;
    $plotLeastC[$h] = $pl;
    $contohRows[$h] = [$contohMost[$h], $contohLeast[$h], $sel, $pm, $pl, $ps];
}
$contohIndex = discKlasifikasiPola($plotSel['D'], $plotSel['I'], $plotSel['S'], $plotSel['C']);
$contohIndexMost = discKlasifikasiPola($plotMostC['D'], $plotMostC['I'], $plotMostC['S'], $plotMostC['C']);
$contohIndexLeast = discKlasifikasiPola($plotLeastC['D'], $plotLeastC['I'], $plotLeastC['S'], $plotLeastC['C']);

// Data Gambaran Karakter / Deskripsi / Job Match untuk contoh (dari disc_data.php = sheet "Def")
$contohDefMask   = discDataPola($contohIndexMost);
$contohDefCore   = discDataPola($contohIndexLeast);
$contohDefMirror = discDataPola($contohIndex);

include __DIR__ . '/../includes/layout_top.php';
?>

<style>
    .panduan { max-width: 900px; margin: 0 auto; }
    .panduan-box { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:28px; }
    .panduan section { margin-top:32px; }
    .panduan h6 { font-size:1rem; font-weight:700; color:#0f172a; margin-bottom:10px; }
    .panduan p, .panduan li { font-size:0.9rem; line-height:1.7; color:#475569; }
    .panduan strong { color:#0f172a; font-weight:600; }
    .graph-badge { font-weight:700; padding:3px 10px; border-radius:8px; font-size:0.8rem; white-space:nowrap; }
    .graph-1 { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
    .graph-2 { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    .graph-3 { background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; }
    .code-inline { background:#f1f5f9; color:#0f172a; padding:1px 6px; border-radius:4px;
                   font-family:ui-monospace,Menlo,Consolas,monospace; font-size:0.85rem; }
    .alur { display:grid; grid-template-columns:repeat(auto-fit,minmax(135px,1fr)); gap:10px; padding:0; list-style:none; margin:0; }
    .alur li { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; font-size:0.82rem; line-height:1.45; }
    .alur li b { display:block; color:#4338ca; margin-bottom:2px; font-size:0.8rem; }
    .alur li.baru { background:#fdf2f8; border-color:#fbcfe8; }
    .alur li.baru b { color:#be185d; }
    .catatan { background:#f8fafc; border-left:3px solid #94a3b8; padding:10px 14px; border-radius:0 8px 8px 0;
               font-size:0.85rem; color:#475569; margin-top:12px; }
    .panduan table th, .panduan table td { font-size:0.85rem; }
    .panduan table td.c, .panduan table th.c { text-align:center; }
    .chip { display:inline-block; padding:1px 8px; border-radius:6px; font-size:0.78rem; font-weight:600; margin:1px 2px 1px 0; }
    .chip-pos { background:#dcfce7; color:#166534; }
    .chip-neg { background:#f1f5f9; color:#475569; }
    .chip-urut { background:#eef2ff; color:#4338ca; }
    tr.grup td { background:#f8fafc; font-weight:700; color:#0f172a; font-size:0.85rem; }
    tr.cocok td { background:#ecfdf5; }
    .status-tolak { color:#b91c1c; font-weight:600; }
    .status-cocok { color:#15803d; font-weight:700; }

    /* Pratinjau kotak pink / oranye seperti di halaman hasil */
    .disc-pink { background:#ff99cc; border:3px double #800080; border-radius:6px; padding:14px 18px; }
    .disc-pink .disc-judul { color:#e00000; font-weight:800; text-decoration:underline; font-size:15px; margin-bottom:2px; }
    .disc-pola-nama { color:#000; font-weight:800; font-size:12.5px; letter-spacing:.5px; margin-bottom:4px; text-transform:uppercase; }
    .disc-sifat { color:#800080; font-variant:small-caps; font-weight:600; font-size:14px; line-height:1.5; }
    .disc-box { background:#ffcc99; border:3px double #333; padding:10px 14px; text-align:justify; font-size:13.5px; line-height:1.5; color:#111; }
    .disc-label { color:#e00000; font-weight:800; font-size:13.5px; margin:12px 0 4px 4px; }
</style>

<div class="mb-3">
    <button onclick="history.back()" class="btn btn-link text-decoration-none p-0 fw-semibold text-primary">
        <i class="bi bi-arrow-left me-1"></i> Kembali
    </button>
</div>

<div class="panduan">
<div class="panduan-box">

    <div class="d-flex align-items-center gap-3 border-bottom pb-3">
        <div class="bg-primary bg-opacity-10 p-3 rounded-3 text-primary"><i class="bi bi-calculator fs-2"></i></div>
        <div>
            <h5 class="fw-bold mb-1">Cara Kerja Rumus Penilaian DISC</h5>
            <div class="text-muted small">Tes forced-choice: peserta memilih Most dan Least di setiap soal</div>
        </div>
    </div>

    <section style="margin-top:24px;">
        <h6>Gambaran singkat</h6>
        <ol class="alur">
            <li><b>1. Jawaban</b>Peserta pilih 1 Most dan 1 Least per soal</li>
            <li><b>2. Tally</b>Hitung berapa kali tiap huruf D, I, S, C terpilih</li>
            <li><b>3. Nilai plot</b>Tally diubah lewat tabel konversi</li>
            <li><b>4. 40 aturan</b>Nilai plot dicocokkan ke satu aturan</li>
            <li><b>5. Pola</b>Aturan itu menentukan nama pola</li>
            <li class="baru"><b>6. Gambaran karakter</b>Pola dipetakan ke daftar sifat, deskripsi, dan job match</li>
        </ol>
    </section>

    <section>
        <h6>1. Konsep dasar</h6>
        <p>
            Tes ini terdiri dari 24 soal. Tiap soal punya 4 pernyataan (A, B, C, D). Peserta memilih:
        </p>
        <ul>
            <li><span class="graph-badge graph-1">Most</span> pernyataan yang paling menggambarkan dirinya.</li>
            <li><span class="graph-badge graph-2">Least</span> pernyataan yang paling tidak menggambarkan dirinya.</li>
        </ul>
        <div class="catatan">
            Setiap pernyataan punya huruf dimensi tetap (<span class="code-inline">D</span>, <span class="code-inline">I</span>,
            <span class="code-inline">S</span>, atau <span class="code-inline">C</span>). Huruf itu tidak berubah, baik
            pernyataan dipilih sebagai Most maupun Least. Yang berbeda hanya jenis pilihannya.
        </div>
    </section>

    <section>
        <h6>2. Tiga grafik</h6>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Grafik</th><th>Rumus</th><th>Artinya</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="graph-badge graph-1">Graph I</span><div class="small text-muted mt-1">Mask Public Self</div></td>
                        <td>Jumlah tiap huruf yang dipilih sebagai Most</td>
                        <td>Gaya yang ditampilkan di depan orang lain, misalnya di tempat kerja.</td>
                    </tr>
                    <tr>
                        <td><span class="graph-badge graph-2">Graph II</span><div class="small text-muted mt-1">Core Private Self</div></td>
                        <td>Jumlah tiap huruf yang dipilih sebagai Least</td>
                        <td>Gaya asli yang muncul saat berada di bawah tekanan.</td>
                    </tr>
                    <tr>
                        <td><span class="graph-badge graph-3">Graph III</span><div class="small text-muted mt-1">Mirror Perceived Self</div></td>
                        <td><span class="code-inline">Graph I − Graph II</span> untuk tiap huruf</td>
                        <td>Gambaran kepribadian secara keseluruhan.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="catatan">
            <strong>Profil akhir peserta diambil dari Graph III (Mirror).</strong>
            Graph I dan II tetap dihitung dan ditampilkan sebagai kotak Mask dan Core pada halaman hasil.
        </div>
    </section>

    <section>
        <h6>3. Dari tally ke nilai plot</h6>
        <p>Tally mentah tidak langsung dipakai. Tiap tally dicari dulu di tabel konversi (hasil salinan dari file Excel sumber), dan hasilnya disebut nilai plot. Tiap grafik punya tabel sendiri.</p>
        <ul>
            <li>Tally Most (0–20) → nilai plot Graph I (Mask).</li>
            <li>Tally Least (0–20) → nilai plot Graph II (Core), memakai tabel yang berbeda dari Most.</li>
            <li>Selisih Most − Least (−22 sampai +22) → nilai plot Graph III (Mirror), memakai tabel ketiga.</li>
        </ul>
        <div class="catatan">
            Plot Mirror dicari dari <strong>selisih</strong> di tabel ketiga, bukan dihitung dari Plot Mask atau Plot Core.
            Tally sebenarnya bisa sampai 24, tapi tabel hanya sampai 20. Nilai di luar batas tabel diperlakukan
            sama dengan ujung tabel terdekat (20, atau −22 / +22 untuk selisih).
            Angka-angka nilai plot ini dipakai di belakang layar saja dan tidak lagi ditampilkan di halaman hasil.
        </div>
    </section>

    <section>
        <h6>4. Dari nilai plot ke salah satu dari 40 pola</h6>
        <p>Nilai plot D, I, S, C dicek dengan 40 aturan tetap, mulai dari aturan #1 sampai #40. Tiap aturan hanya menanyakan dua hal:</p>
        <ol>
            <li><strong>Huruf mana yang positif</strong> (nilainya di atas 0), dan huruf mana yang 0 atau negatif.</li>
            <li><strong>Urutan nilainya</strong>, misalnya "I harus ≥ D" (nilai I tidak boleh lebih kecil dari D).</li>
        </ol>
        <p>Aturan yang <strong>pertama cocok</strong> dipakai, lalu pengecekan berhenti. Tiap aturan punya label kombinasi baku
        (misalnya #4 berlabel <span class="code-inline">D / I-D</span>), bukan dibentuk dari huruf yang lolos filter.
        Pengecekan dijalankan tiga kali (Mask, Core, Mirror) dan hasilnya bisa berbeda-beda.</p>
        <div class="catatan">
            <strong>Cara cepat membaca tabel di bawah:</strong> lihat dulu huruf mana yang positif pada nilai plot,
            lalu cari kelompok yang sesuai. Di dalam satu kelompok, aturan hanya dibedakan oleh urutan nilai.
            Contoh: jika hanya D dan I yang positif, hasilnya #4 (jika I ≥ D) atau #9 (jika D ≥ I).
            Jika ada dua aturan yang cocok, yang nomornya lebih kecil menang.
        </div>

        <div class="table-responsive mt-3">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="c" style="width:52px;">No.</th>
                        <th>Label kombinasi</th>
                        <th>Nama pola (bawaan)</th>
                        <th>Syarat</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($kelompokAturan as $kunci => $daftar): ?>
                    <tr class="grup"><td colspan="4">Huruf yang positif: <?= e($judulKelompok[$kunci]) ?></td></tr>
                    <?php foreach ($daftar as $no => $sp): ?>
                    <tr>
                        <td class="c fw-bold">#<?= (int)$no ?></td>
                        <td><span class="code-inline"><?= e(DISC_KOMBINASI_40[$no]) ?></span></td>
                        <td><?= e(DISC_NAMA_POLA_40[$no] ?? '-') ?></td>
                        <td>
                            <?php foreach (str_split($sp['pos']) as $h): ?><span class="chip chip-pos"><?= e($h) ?> &gt; 0</span><?php endforeach; ?>
                            <?php foreach (str_split($sp['neg']) as $h): ?><span class="chip chip-neg"><?= e($h) ?> ≤ 0</span><?php endforeach; ?>
                            <?php foreach ($sp['urut'] as $u): ?><span class="chip chip-urut"><?= e($u[0]) ?> ≥ <?= e($u[1]) ?></span><?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="small text-muted">
            <span class="chip chip-pos">hijau</span> harus positif &nbsp;
            <span class="chip chip-neg">abu-abu</span> harus 0 atau negatif &nbsp;
            <span class="chip chip-urut">biru</span> syarat urutan nilai.
            Semua syarat pada satu baris harus terpenuhi sekaligus.
        </div>
        <div class="catatan">
            Jika tidak ada satu pun aturan yang cocok (misalnya semua huruf 0 atau negatif), pola tidak ditemukan.
            Pada halaman hasil, kolom Mask, Core, atau Mirror yang bersangkutan menampilkan "#N/A".
        </div>
    </section>

    <section>
        <h6>5. Dari aturan ke nama pola (judul profil)</h6>
        <p>Nama pola yang tampil sebagai judul besar di kartu kanan atas halaman hasil dicari dengan urutan berikut:</p>
        <ol>
            <li><strong>Exact match.</strong> Label kombinasi dicocokkan utuh ke kolom <span class="code-inline">kombinasi</span> di tabel <span class="code-inline">disc_pola</span>.</li>
            <li><strong>Fallback varian</strong>, hanya jika langkah 1 tidak ketemu. Label dipecah di tanda <span class="code-inline">/</span> (misalnya <span class="code-inline">D / I-D</span> menjadi <span class="code-inline">D</span> dan <span class="code-inline">I-D</span>), lalu dicocokkan per varian.</li>
            <li><strong>Nama bawaan</strong>, jika database tidak punya baris yang cocok. Sistem memakai daftar nama pola bawaan agar hasil tidak kosong.</li>
        </ol>
        <div class="catatan">
            Exact match didahulukan supaya label seperti <span class="code-inline">D / I-D</span> tidak salah
            tertangkap ke baris berlabel <span class="code-inline">D</span> saja.
        </div>
    </section>

    <section>
        <h6>6. Dari pola ke Gambaran Karakter, Deskripsi Kepribadian, dan Job Match</h6>
        <p>
            Bagian ini adalah isi kotak pink dan kotak oranye di halaman hasil. Nomor aturan (1–40) dari langkah 4
            dipakai sebagai kunci untuk mengambil data dari <span class="code-inline">includes/disc_data.php</span>,
            yaitu salinan sheet "Def" pada file Excel sumber. Untuk tiap nomor pola, data itu berisi nama pola,
            sekitar 11 sifat, satu paragraf deskripsi, dan daftar job match.
        </p>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Bagian di halaman hasil</th><th>Memakai pola dari</th><th>Isi yang diambil</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Mask Public Self</strong> (kotak pink, kiri)</td>
                        <td><span class="graph-badge graph-1">Graph I</span> (Most)</td>
                        <td>Nama pola dan daftar sifat</td>
                    </tr>
                    <tr>
                        <td><strong>Core Private Self</strong> (kotak pink, kanan)</td>
                        <td><span class="graph-badge graph-2">Graph II</span> (Least)</td>
                        <td>Nama pola dan daftar sifat</td>
                    </tr>
                    <tr>
                        <td><strong>Mirror Perceived Self</strong> (kotak pink, bawah)</td>
                        <td><span class="graph-badge graph-3">Graph III</span> (selisih)</td>
                        <td>Nama pola dan daftar sifat</td>
                    </tr>
                    <tr>
                        <td><strong>Deskripsi Kepribadian</strong> (kotak oranye)</td>
                        <td><span class="graph-badge graph-3">Graph III</span> saja</td>
                        <td>Satu paragraf deskripsi</td>
                    </tr>
                    <tr>
                        <td><strong>Job Match</strong> (kotak oranye)</td>
                        <td><span class="graph-badge graph-3">Graph III</span> saja</td>
                        <td>Daftar jabatan yang cocok</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="catatan">
            Susunan ini sama dengan sheet Result di Excel: kolom Mask dan Core memakai HLOOKUP ke tabel Def
            dengan nomor pola Graph I dan II, sedangkan Mirror, Deskripsi, dan Job Match memakai nomor pola Graph III.
            Beberapa nomor pola memang punya nama yang sama di file Excel sumber (misalnya #14 dan #28 sama-sama
            "RESPONSIVE &amp; THOUGHTFUL", atau #20 dan #21 sama-sama "DIRECTOR"). Isi sifat dan deskripsinya bisa
            identik, tetapi data diambil per nomor aturan, jadi bagian seperti Job Match tetap bisa berbeda.
        </div>
    </section>

    <section>
        <h6>Contoh perhitungan</h6>
        <p>Misalnya seorang peserta punya tally berikut dari 24 soal. Kolom plot diisi dari tabel konversi.</p>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="c">Huruf</th>
                        <th class="c">Tally Most</th>
                        <th class="c">Tally Least</th>
                        <th class="c">Selisih</th>
                        <th class="c">Plot Mask</th>
                        <th class="c">Plot Core</th>
                        <th class="c">Plot Mirror</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contohRows as $h => $r): ?>
                    <tr>
                        <td class="c fw-bold"><?= e($h) ?></td>
                        <td class="c"><?= (int)$r[0] ?></td>
                        <td class="c"><?= (int)$r[1] ?></td>
                        <td class="c"><?= $r[2] > 0 ? '+' . (int)$r[2] : (int)$r[2] ?></td>
                        <td class="c"><?= e(number_format($r[3], 1)) ?></td>
                        <td class="c"><?= e(number_format($r[4], 1)) ?></td>
                        <td class="c fw-semibold"><?= e(number_format($r[5], 1)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p>
            <strong>Kenapa Plot Mirror D = <?= e(panduanAngka($plotSel['D'])) ?>?</strong>
            Selisih D adalah <?= (int)$contohRows['D'][0] ?> − <?= (int)$contohRows['D'][1] ?> =
            <strong><?= $contohRows['D'][2] > 0 ? '+' : '' ?><?= (int)$contohRows['D'][2] ?></strong>. Angka itu dicari di tabel konversi
            Selisih (baris <?= (int)$contohRows['D'][2] ?>, kolom D) dan hasilnya <?= e(panduanAngka($plotSel['D'])) ?>.
            Huruf I, S, dan C dihitung dengan cara yang sama di kolom masing-masing.
        </p>

        <?php if ($contohIndex !== null): ?>
        <p class="mb-2">
            <strong>Kenapa hasilnya aturan #<?= (int)$contohIndex ?>?</strong>
            Nilai Mirror adalah
            D = <?= e(panduanAngka($plotSel['D'])) ?>, I = <?= e(panduanAngka($plotSel['I'])) ?>,
            S = <?= e(panduanAngka($plotSel['S'])) ?>, C = <?= e(panduanAngka($plotSel['C'])) ?>.
            Aturan dicek dari #1 sampai ada yang cocok:
        </p>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th class="c" style="width:52px;">Aturan</th><th style="width:130px;">Label</th><th>Hasil pengecekan</th></tr>
                </thead>
                <tbody>
                <?php for ($n = 1; $n <= $contohIndex; $n++):
                    $alasan = panduanAlasanTolak(DISC_ATURAN_SPEC[$n], $plotSel);
                    $cocok = ($n === $contohIndex); ?>
                    <tr class="<?= $cocok ? 'cocok' : '' ?>">
                        <td class="c fw-bold">#<?= $n ?></td>
                        <td><span class="code-inline"><?= e(DISC_KOMBINASI_40[$n]) ?></span></td>
                        <td>
                            <?php if ($cocok): ?>
                                <span class="status-cocok">Cocok.</span> Semua syarat terpenuhi, pengecekan berhenti di sini.
                            <?php else: ?>
                                <span class="status-tolak">Ditolak.</span> <?= e(implode('; ', $alasan)) ?>.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <p>
            Aturan #<?= (int)$contohIndex ?> berlabel <span class="code-inline"><?= e(DISC_KOMBINASI_40[$contohIndex]) ?></span>
            dengan nama pola bawaan <strong><?= e(DISC_NAMA_POLA_40[$contohIndex] ?? '-') ?></strong>.
        </p>

        <p class="mb-2">
            <strong>Lalu apa yang tampil di halaman hasil?</strong>
            Pengecekan 40 aturan yang sama dijalankan juga untuk nilai Mask
            (hasil: <?= $contohIndexMost !== null ? 'aturan #' . (int)$contohIndexMost : 'tidak ada yang cocok' ?>)
            dan nilai Core
            (hasil: <?= $contohIndexLeast !== null ? 'aturan #' . (int)$contohIndexLeast : 'tidak ada yang cocok' ?>).
            Ketiga nomor itu dipakai untuk mengambil data, dan tampilannya kira-kira seperti ini:
        </p>

        <div class="disc-pink mb-2">
            <div class="row g-3">
                <?php foreach ([['Mask Public Self', $contohDefMask], ['Core Private Self', $contohDefCore]] as [$jdl, $def]): ?>
                <div class="col-md-6">
                    <div class="disc-judul"><?= e($jdl) ?></div>
                    <?php if ($def): ?>
                        <div class="disc-pola-nama"><?= e($def['nama']) ?></div>
                        <?php foreach ($def['sifat'] as $s): ?><div class="disc-sifat"><?= e($s) ?></div><?php endforeach; ?>
                    <?php else: ?>
                        <div class="disc-pola-nama">#N/A</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3">
                <div class="disc-judul">Mirror Perceived Self</div>
                <?php if ($contohDefMirror): ?>
                    <div class="disc-pola-nama"><?= e($contohDefMirror['nama']) ?></div>
                    <?php foreach ($contohDefMirror['sifat'] as $s): ?><div class="disc-sifat"><?= e($s) ?></div><?php endforeach; ?>
                <?php else: ?>
                    <div class="disc-pola-nama">#N/A</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($contohDefMirror): ?>
        <div class="disc-label">Deskripsi Kepribadian</div>
        <div class="disc-box mb-1"><?= e($contohDefMirror['deskripsi']) ?></div>
        <div class="disc-label">Job Match :</div>
        <div class="disc-box"><?= e($contohDefMirror['job']) ?></div>
        <?php endif; ?>
        <?php else: ?>
        <p class="mb-0">Pada contoh ini tidak ada aturan yang cocok.</p>
        <?php endif; ?>
    </section>

    <div class="text-end pt-3 mt-4 border-top">
        <button onclick="history.back()" class="btn btn-primary rounded-3 px-4 fw-semibold">Saya Mengerti</button>
    </div>

</div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>