<?php
/**
 * SKRIP SEKALI JALAN: isi opsi.dimensi (huruf MOST) dan opsi.dimensi_least
 * (huruf LEAST) untuk tes DISC, langsung dari sheet "Input" di DISC Test.xls.
 *
 * Taruh di folder admin/ (sejajar soal.php), buka lewat browser sebagai admin.
 * Alurnya: tampil dulu PRATINJAU (tidak mengubah apa pun) -> cek -> klik
 * "Terapkan". Opsi dicocokkan per nomor soal (urutan) + label A-D, lalu teksnya
 * dibandingkan dengan teks di Excel; yang teksnya TIDAK cocok dilewati.
 * Setelah selesai dan hasilnya benar, hapus file ini.
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();

// [no soal, opsi 1-4 (A-D), teks Excel, huruf MOST, huruf LEAST]  ('*' = tidak dihitung)
$MAPPING = [
        // [no soal, opsi 1-4 (A-D), teks Excel, huruf MOST, huruf LEAST]  ('*' = tidak dihitung)
        [1, 1, 'Gampangan, Mudah setuju', 'S', 'S'],
        [1, 2, 'Percaya, Mudah percaya pada orang', 'I', 'I'],
        [1, 3, 'Petualang, Mengambil resiko', '*', 'D'],
        [1, 4, 'Toleran, Menghormati', 'C', 'C'],
        [2, 1, 'Lembut suara, Pendiam', 'C', '*'],
        [2, 2, 'Optimistik, Visioner', 'D', 'D'],
        [2, 3, 'Pusat Perhatian, Suka gaul', '*', 'I'],
        [2, 4, 'Pendamai, Membawa Harmoni', 'S', 'S'],
        [3, 1, 'Menyemangati orang', 'I', 'I'],
        [3, 2, 'Berusaha sempurna', '*', 'C'],
        [3, 3, 'Bagian dari kelompok', '*', 'S'],
        [3, 4, 'Ingin membuat tujuan', 'D', '*'],
        [4, 1, 'Menjadi frustrasi', 'C', 'C'],
        [4, 2, 'Menyimpan perasaan saya', 'S', 'S'],
        [4, 3, 'Menceritakan sisi saya', '*', 'I'],
        [4, 4, 'Siap beroposisi', 'D', 'D'],
        [5, 1, 'Hidup, Suka bicara', 'I', '*'],
        [5, 2, 'Gerak cepat, Tekun', 'D', 'D'],
        [5, 3, 'Usaha menjaga keseimbangan', 'S', 'S'],
        [5, 4, 'Usaha mengikuti aturan', '*', 'C'],
        [6, 1, 'Kelola waktu secara efisien', 'C', '*'],
        [6, 2, 'Sering terburu-buru, Merasa tertekan', 'D', 'D'],
        [6, 3, 'Masalah sosial itu penting', 'I', 'I'],
        [6, 4, 'Suka selesaikan apa yang saya mulai', 'S', 'S'],
        [7, 1, 'Tolak perubahan mendadak', 'S', '*'],
        [7, 2, 'Cenderung janji berlebihan', 'I', 'I'],
        [7, 3, 'Tarik diri di tengah tekanan', '*', 'C'],
        [7, 4, 'Tidak takut bertempur', '*', 'D'],
        [8, 1, 'Penyemangat yang baik', 'I', 'I'],
        [8, 2, 'Pendengar yang baik', 'S', 'S'],
        [8, 3, 'Penganalisa yang baik', 'C', 'C'],
        [8, 4, 'Delegator yang baik', 'D', 'D'],
        [9, 1, 'Hasil adalah penting', 'D', 'D'],
        [9, 2, 'Lakukan dengan benar, Akurasi penting', 'C', 'C'],
        [9, 3, 'Dibuat menyenangkan', '*', 'I'],
        [9, 4, 'Mari kerjakan bersama', '*', 'S'],
        [10, 1, 'Akan berjalan terus tanpa kontrol diri', '*', 'C'],
        [10, 2, 'Akan membeli sesuai dorongan hati', 'D', 'D'],
        [10, 3, 'Akan menunggu, Tanpa tekanan', 'S', 'S'],
        [10, 4, 'Akan mengusahakan  yang kuinginkan', 'I', '*'],
        [11, 1, 'Ramah, Mudah bergabung', 'S', '*'],
        [11, 2, 'Unik, Bosan rutinitas', '*', 'I'],
        [11, 3, 'Aktif mengubah sesuatu', 'D', 'D'],
        [11, 4, 'Ingin hal-hal yang pasti', 'C', 'C'],
        [12, 1, 'Non-konfrontasi, Menyerah', '*', 'S'],
        [12, 2, 'Dipenuhi hal detail', 'C', '*'],
        [12, 3, 'Perubahan pada menit terakhir', 'I', 'I'],
        [12, 4, 'Menuntut, Kasar', 'D', 'D'],
        [13, 1, 'Ingin kemajuan', 'D', 'D'],
        [13, 2, 'Puas dengan segalanya', 'S', '*'],
        [13, 3, 'Terbuka memperlihatkan perasaan', 'I', '*'],
        [13, 4, 'Rendah hati, Sederhana', '*', 'C'],
        [14, 1, 'Tenang, Pendiam', 'C', 'C'],
        [14, 2, 'Bahagia, Tanpa beban', 'I', 'I'],
        [14, 3, 'Menyenangkan, Baik hati', 'S', '*'],
        [14, 4, 'Tak gentar, Berani', 'D', 'D'],
        [15, 1, 'Menggunakan waktu berkualitas dgn teman', 'S', 'S'],
        [15, 2, 'Rencanakan masa depan, Bersiap', 'C', '*'],
        [15, 3, 'Bepergian demi petualangan baru', 'I', 'I'],
        [15, 4, 'Menerima ganjaran atas tujuan yg dicapai', 'D', 'D'],
        [16, 1, 'Aturan perlu dipertanyakan', '*', 'D'],
        [16, 2, 'Aturan membuat adil', 'C', '*'],
        [16, 3, 'Aturan membuat bosan', 'I', 'I'],
        [16, 4, 'Aturan membuat aman', 'S', 'S'],
        [17, 1, 'Pendidikan, Kebudayaan', '*', 'C'],
        [17, 2, 'Prestasi, Ganjaran', 'D', 'D'],
        [17, 3, 'Keselamatan, keamanan', 'S', 'S'],
        [17, 4, 'Sosial, Perkumpulan kelompok', 'I', '*'],
        [18, 1, 'Memimpin, Pendekatan langsung', 'D', 'D'],
        [18, 2, 'Suka bergaul, Antusias', '*', 'I'],
        [18, 3, 'Dapat diramal, Konsisten', '*', 'S'],
        [18, 4, 'Waspada, Hati-hati', 'C', '*'],
        [19, 1, 'Tidak mudah dikalahkan', 'D', 'D'],
        [19, 2, 'Kerjakan sesuai perintah, Ikut pimpinan', 'S', '*'],
        [19, 3, 'Mudah terangsang, Riang', 'I', 'I'],
        [19, 4, 'Ingin segalanya teratur, Rapi', '*', 'C'],
        [20, 1, 'Saya akan pimpin mereka', 'D', '*'],
        [20, 2, 'Saya akan melaksanakan', 'S', 'S'],
        [20, 3, 'Saya akan meyakinkan mereka', 'I', 'I'],
        [20, 4, 'Saya dapatkan fakta', 'C', '*'],
        [21, 1, 'Memikirkan orang dahulu', 'S', 'S'],
        [21, 2, 'Kompetitif, Suka tantangan', 'D', 'D'],
        [21, 3, 'Optimis, Positif', 'I', 'I'],
        [21, 4, 'Pemikir logis, Sistematik', '*', 'C'],
        [22, 1, 'Menyenangkan orang, Mudah setuju', 'S', 'S'],
        [22, 2, 'Tertawa lepas, Hidup', '*', 'I'],
        [22, 3, 'Berani, Tak gentar', 'D', 'D'],
        [22, 4, 'Tenang, Pendiam', 'C', 'C'],
        [23, 1, 'Ingin otoritas lebih', '*', 'D'],
        [23, 2, 'Ingin kesempatan baru', 'I', '*'],
        [23, 3, 'Menghindari konflik', 'S', 'S'],
        [23, 4, 'Ingin petunjuk yang jelas', '*', 'C'],
        [24, 1, 'Dapat diandalkan, Dapata dipercaya', '*', 'S'],
        [24, 2, 'Kreatif, Unik', 'I', 'I'],
        [24, 3, 'Garis dasar, Orientasi hasil', 'D', '*'],
        [24, 4, 'Jalankan standar yang tinggi, Akurat', 'C', '*'],
];

function normTeks(string $t): string {
    $t = mb_strtolower($t);
    $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
    return trim($t);
}

$ada = $db->query("SHOW COLUMNS FROM opsi LIKE 'dimensi_least'")->fetch();
if (!$ada) {
    exit('Kolom opsi.dimensi_least belum ada. Jalankan dulu: ALTER TABLE opsi ADD COLUMN dimensi_least VARCHAR(5) NULL AFTER dimensi;');
}

$tesList = $db->query("SELECT id, judul FROM tes WHERE tipe_psikotes = 'disc' ORDER BY id")->fetchAll();
$terapkan = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'terapkan');
$tesDipilih = (int)($_REQUEST['tes_id'] ?? ($tesList[0]['id'] ?? 0));

$hasil = [];       // baris untuk ditampilkan
$update = [];      // [opsi_id, dimensi, dimensi_least]
$peringatan = [];

if ($tesDipilih) {
    $stmt = $db->prepare("SELECT id, urutan FROM soal WHERE tes_id = ? ORDER BY urutan ASC, id ASC");
    $stmt->execute([$tesDipilih]);
    $soalList = $stmt->fetchAll();
    if (count($soalList) !== 24) {
        $peringatan[] = 'Jumlah soal di tes ini ' . count($soalList) . ', Excel punya 24 soal.';
    }

    $stOpsi = $db->prepare("SELECT id, label, teks, dimensi, dimensi_least FROM opsi WHERE soal_id = ?");
    $opsiPerSoal = [];
    foreach ($soalList as $idx => $s) {
        $stOpsi->execute([$s['id']]);
        foreach ($stOpsi->fetchAll() as $o) {
            $opsiPerSoal[$idx + 1][strtoupper($o['label'])] = $o;
        }
    }

    foreach ($MAPPING as [$no, $nomorOpsi, $teksExcel, $most, $least]) {
        $label = chr(64 + $nomorOpsi);
        $o = $opsiPerSoal[$no][$label] ?? null;
        $baris = [
            'no' => $no, 'label' => $label, 'teks_excel' => $teksExcel,
            'teks_db' => $o['teks'] ?? null,
            'most_baru' => $most === '*' ? null : $most,
            'least_baru' => $least === '*' ? null : $least,
            'most_lama' => $o['dimensi'] ?? null,
            'least_lama' => $o['dimensi_least'] ?? null,
            'cocok' => false,
        ];
        if ($o) {
            $baris['cocok'] = normTeks($o['teks']) === normTeks($teksExcel);
            if ($baris['cocok']) {
                $update[] = [(int)$o['id'], $baris['most_baru'], $baris['least_baru']];
            }
        }
        $hasil[] = $baris;
    }

    if ($terapkan) {
        $db->beginTransaction();
        $up = $db->prepare("UPDATE opsi SET dimensi = ?, dimensi_least = ? WHERE id = ?");
        foreach ($update as [$id, $m, $l]) {
            $up->execute([$m, $l, $id]);
        }
        $db->commit();
    }
}

$jmlCocok = count($update);
$jmlTotal = count($hasil);
$pageTitle = 'Isi Dimensi DISC (Most/Least)';
$activeMenu = 'tes';
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="card-modern p-3 mb-3">
    <form method="GET" class="d-flex gap-2 align-items-center mb-2">
        <label class="small fw-semibold">Tes DISC:</label>
        <select name="tes_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <?php foreach ($tesList as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tesDipilih ? 'selected' : '' ?>><?= e($t['judul']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php foreach ($peringatan as $p): ?><div class="alert alert-warning small py-2"><?= e($p) ?></div><?php endforeach; ?>

    <?php if ($terapkan): ?>
        <div class="alert alert-success small">Selesai: <?= $jmlCocok ?> dari <?= $jmlTotal ?> opsi diperbarui.</div>
    <?php else: ?>
        <div class="alert alert-info small">
            PRATINJAU - belum ada yang diubah. <?= $jmlCocok ?> dari <?= $jmlTotal ?> opsi teksnya cocok dan akan diisi.
            Baris merah (teks tidak cocok) dilewati; cek urutan soal / teks opsinya.
        </div>
        <?php if ($jmlCocok > 0): ?>
        <form method="POST" onsubmit="return confirm('Terapkan perubahan dimensi ke <?= $jmlCocok ?> opsi?');">
            <input type="hidden" name="aksi" value="terapkan">
            <input type="hidden" name="tes_id" value="<?= $tesDipilih ?>">
            <button class="btn btn-brand fw-semibold">Terapkan ke <?= $jmlCocok ?> opsi</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card-modern p-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle small">
            <thead class="table-light">
                <tr><th>No</th><th>Opsi</th><th>Teks (Excel)</th><th>Teks (DB)</th>
                    <th>Most lama &rarr; baru</th><th>Least lama &rarr; baru</th></tr>
            </thead>
            <tbody>
            <?php foreach ($hasil as $r): ?>
                <tr class="<?= $r['cocok'] ? '' : 'table-danger' ?>">
                    <td><?= (int)$r['no'] ?></td>
                    <td><?= e($r['label']) ?></td>
                    <td><?= e($r['teks_excel']) ?></td>
                    <td><?= e($r['teks_db'] ?? '(tidak ada)') ?></td>
                    <td><?= e($r['most_lama'] ?? '-') ?> &rarr; <strong><?= e($r['most_baru'] ?? '*') ?></strong></td>
                    <td><?= e($r['least_lama'] ?? '-') ?> &rarr; <strong><?= e($r['least_baru'] ?? '*') ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>