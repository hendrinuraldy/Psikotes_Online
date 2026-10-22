<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/papikostik_scoring.php';
requireRole('admin');

$pageTitle = 'Panduan Rumus Papikostik';
$pageDesc = 'Cara skor mentah diubah menjadi interpretasi per dimensi';
$activeMenu = 'hasil';
include __DIR__ . '/../includes/layout_top.php';

// Kelompok dimensi mengikuti pengelompokan umum PAPI Kostick (hanya untuk
// memudahkan baca; tidak memengaruhi perhitungan skor).
$kelompok = [
    'Arah Kerja (Work Direction)'      => ['N', 'G', 'A'],
    'Kepemimpinan (Leadership)'        => ['L', 'P', 'I'],
    'Aktivitas Kerja (Activity)'       => ['T', 'V'],
    'Hubungan Sosial (Social Nature)'  => ['S', 'B', 'O', 'X'],
    'Gaya Kerja (Work Style)'          => ['C', 'D', 'R'],
    'Sifat Temperamen (Temperament)'   => ['Z', 'E', 'K'],
    'Ketaatan (Followership)'          => ['F', 'W'],
];
?>

<style>
    .rumus-table th, .rumus-table td { font-size:13.5px; vertical-align:top; }
    .badge-huruf { display:inline-flex; width:24px; height:24px; border-radius:6px; background:#1e293b; color:#fff;
                   align-items:center; justify-content:center; font-weight:700; font-size:12.5px; }
    .langkah { display:flex; gap:12px; margin-bottom:14px; }
    .langkah .no { flex:0 0 28px; height:28px; border-radius:50%; background:#eef2ff; color:#4338ca;
                   font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center; }
    .langkah .isi { font-size:14px; line-height:1.6; }
    .kode { background:#f1f5f9; border-radius:8px; padding:10px 14px; font-family:ui-monospace,Menlo,Consolas,monospace;
            font-size:12.5px; overflow-x:auto; white-space:pre; margin:8px 0 0; }
    details.dim-detail { border:1px solid var(--border); border-radius:10px; margin-bottom:8px; }
    details.dim-detail summary { cursor:pointer; padding:10px 14px; font-size:14px; font-weight:600; list-style:none; }
    details.dim-detail summary::-webkit-details-marker { display:none; }
    details.dim-detail[open] summary { border-bottom:1px solid var(--border); background:#f8fafc; border-radius:10px 10px 0 0; }
    details.dim-detail .table { margin:0; }
    .rentang-col { width:90px; white-space:nowrap; font-weight:700; text-align:center; }
    summary:focus-visible { outline:2px solid var(--brand); outline-offset:2px; }
</style>

<div class="mb-3">
    <a href="hasil.php" class="fw-semibold" style="font-size:13px; color:var(--brand);">&larr; Kembali ke Hasil</a>
</div>

<div class="card-modern mb-3">
    <h5 class="fw-bold mb-2">Panduan Rumus Penilaian Papikostik</h5>
    <p class="text-muted mb-0" style="font-size:14px;">
        Halaman ini menjelaskan bagaimana jawaban peserta diubah menjadi skor per dimensi, teks interpretasi,
        dan profil singkat (3 dimensi tertinggi). Tidak seperti DISC, Papikostik di sistem ini
        <strong>tidak memakai Most/Least, tabel plot, atau pola 40 kombinasi</strong>. Rumusnya hanya
        hitung-tally lalu cocokkan ke rentang.
    </p>
</div>

<div class="card-modern mb-3">
    <h6 class="fw-bold mb-3">Alur perhitungan</h6>

    <div class="langkah">
        <div class="no">1</div>
        <div class="isi">
            <strong>Peserta memilih satu opsi per soal.</strong>
            Setiap opsi (A–E) sudah dipetakan ke satu huruf dimensi lewat kolom <code>opsi.dimensi</code>
            (diatur admin di halaman Soal).
        </div>
    </div>

    <div class="langkah">
        <div class="no">2</div>
        <div class="isi">
            <strong>Skor mentah = jumlah opsi bergantung huruf dimensi yang dipilih.</strong>
            Tiap kali peserta memilih opsi bertanda huruf <em>N</em>, skor dimensi N bertambah 1. Ada 20 dimensi,
            jadi ada 20 skor terpisah. Opsi tanpa dimensi diabaikan.
            <div class="kode">skor['N'] = jumlah jawaban peserta yang opsinya berdimensi N</div>
        </div>
    </div>

    <div class="langkah">
        <div class="no">3</div>
        <div class="isi">
            <strong>Skor dibatasi ke skala 0–9.</strong>
            Tabel interpretasi hanya mendefinisikan skor 0 sampai 9. Skor di atas 9 diperlakukan sebagai 9
            (angka mentah di kolom Skor tetap apa adanya, hanya pencarian interpretasinya yang dibatasi).
        </div>
    </div>

    <div class="langkah">
        <div class="no">4</div>
        <div class="isi">
            <strong>Skor dicocokkan ke rentang per dimensi.</strong>
            Setiap dimensi punya rentang sendiri (misalnya dimensi A: 0–4, 5–7, 8–9) dan tiap rentang punya
            teks interpretasi. Titik potongnya <em>berbeda-beda</em> untuk tiap dimensi, jadi skor 5 di dimensi
            <em>A</em> tidak berarti sama dengan skor 5 di dimensi <em>N</em>. Seluruh rentang lengkap ada di bagian bawah.
        </div>
    </div>

    <div class="langkah mb-0">
        <div class="no">5</div>
        <div class="isi">
            <strong>Tiga dimensi tertinggi menjadi profil ringkas.</strong>
            Dimensi diurutkan dari skor terbesar ke terkecil, lalu 3 teratas digabung dengan tanda hubung
            (contoh: <code>L-A-G</code>). Ini yang tampil sebagai "Profil Kepribadian" di halaman detail.
            Jika skor seri, dimensi yang lebih awal di urutan
            <code><?= e(implode(' ', PAPIKOSTIK_DIMENSI)) ?></code> didahulukan.
        </div>
    </div>
</div>

<div class="card-modern mb-3">
    <h6 class="fw-bold mb-2">Contoh singkat</h6>
    <p class="text-muted small mb-2">
        Misalnya dari seluruh jawaban seorang peserta, opsi berdimensi <strong>A</strong> terpilih 6 kali,
        <strong>N</strong> 2 kali, dan <strong>L</strong> 8 kali.
    </p>
    <div class="table-responsive">
        <table class="table table-bordered rumus-table mb-2">
            <thead class="table-light">
                <tr><th>Dimensi</th><th style="text-align:center;">Skor</th><th>Rentang yang cocok</th><th>Hasil</th></tr>
            </thead>
            <tbody>
                <?php
                $contoh = ['A' => 6, 'N' => 2, 'L' => 8];
                foreach ($contoh as $d => $s):
                    $rentang = '-';
                    foreach (PAPIKOSTIK_RENTANG[$d] as [$min, $max, $teks]) {
                        if ($s >= $min && $s <= $max) { $rentang = $min === $max ? (string)$min : ($min . '–' . $max); break; }
                    }
                ?>
                <tr>
                    <td><span class="badge-huruf"><?= e($d) ?></span> <?= e(PAPIKOSTIK_LABEL[$d]) ?></td>
                    <td style="text-align:center;" class="fw-bold"><?= (int)$s ?></td>
                    <td>Skor <?= (int)$s ?> masuk rentang <strong><?= e($rentang) ?></strong></td>
                    <td><?= e(papikostikInterpretasi($d, $s)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="small text-muted">Profil ringkas dari contoh ini: <strong>L-A-N</strong> (bila dimensi lain bernilai lebih rendah).</div>
</div>

<div class="card-modern mb-3">
    <h6 class="fw-bold mb-3">Daftar 20 dimensi</h6>
    <div class="table-responsive">
        <table class="table table-bordered rumus-table mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:70px; text-align:center;">Huruf</th>
                    <th>Nama Dimensi</th>
                    <th style="width:220px;">Kelompok</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($kelompok as $namaKel => $huruf): foreach ($huruf as $h): ?>
                <tr>
                    <td style="text-align:center;"><span class="badge-huruf"><?= e($h) ?></span></td>
                    <td><?= e(PAPIKOSTIK_LABEL[$h] ?? $h) ?></td>
                    <td class="text-muted"><?= e($namaKel) ?></td>
                </tr>
                <?php endforeach; endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-modern mb-3">
    <h6 class="fw-bold mb-1">Rentang interpretasi lengkap per dimensi</h6>
    <br>

    <?php foreach (PAPIKOSTIK_DIMENSI as $h): ?>
    <details class="dim-detail">
        <summary>
            <span class="badge-huruf me-2"><?= e($h) ?></span><?= e(PAPIKOSTIK_LABEL[$h] ?? $h) ?>
        </summary>
        <table class="table table-sm table-bordered rumus-table">
            <thead class="table-light">
                <tr><th class="rentang-col">Skor</th><th>Interpretasi</th></tr>
            </thead>
            <tbody>
                <?php foreach (PAPIKOSTIK_RENTANG[$h] as [$min, $max, $teks]): ?>
                <tr>
                    <td class="rentang-col"><?= $min === $max ? (int)$min : (int)$min . '–' . (int)$max ?></td>
                    <td><?= e($teks) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endforeach; ?>
</div>

<div class="card-modern mb-3">
    <h6 class="fw-bold mb-3">Hal yang perlu diperhatikan admin</h6>
    <ul class="mb-0" style="font-size:14px; line-height:1.7;">
        <li>
            <strong>Tabel rentang sama persis dengan file Excel resmi</strong> (sheet URAIAN PAPIKOSTIK,
            Papikostic Advance Master). Bagian ini aman dijadikan acuan.
        </li>
        <li>
            <strong>Cara menghitung skor mentah sama dengan Excel resmi, asalkan bank soal sama.</strong>
            Di Excel (sheet DATA, baris 95-114), skor tiap dimensi adalah jumlah pernyataan berhuruf itu yang dipilih
            (rumus COUNTIF). Tiap huruf punya tepat 9 pernyataan di 90 soal resmi, sehingga skalanya 0-9.
            Sistem ini menghitung dengan cara yang sama. Skor bisa berbeda dari Excel hanya jika bank soal atau
            pemetaan huruf per opsi di sistem tidak sama dengan 90 soal resmi.
        </li>
        <li>
            <strong>Jumlah soal per dimensi memengaruhi skor.</strong> Skala interpretasi hanya 0–9. Jika sebuah
            dimensi punya lebih dari 9 opsi di bank soal, skornya mudah menyentuh batas atas. Jika terlalu sedikit,
            skornya sulit naik. Usahakan tiap dimensi punya jumlah opsi yang seimbang.
        </li>
        <li>
            <strong>Tidak ada koreksi usia</strong> pada Papikostik (koreksi usia hanya untuk WPT).
        </li>
        <li>
            <strong>Bank soal diubah setelah ada peserta yang selesai?</strong> Buka detail hasil peserta itu,
            lalu klik <em>Hitung ulang</em> pada kartu Rincian Skor Papikostik agar skor mengikuti pemetaan terbaru.
        </li>
        <li>
            Hasil ini adalah alat bantu, bukan diagnosis. Interpretasi sebaiknya dibaca bersama hasil tes lain dan wawancara.
        </li>
    </ul>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>