<?php
/**
 * Simpan sebagai: includes/cfit_scoring.php
 *
 * Konversi skor CFIT Skala 3A/3B (gabungan bagian 1-4) -> IQ, berdasarkan
 * file CFIT_Skala_3A_dan_3B.xls (sheet "CFIT 3A&3B (16+)").
 *
 * Catatan dari file norma tersebut:
 *  - IQ dicari langsung dari skor mentah (jumlah benar bagian 1-4, maks 50)
 *    lewat VLOOKUP tabel RS -> IQ. TIDAK ada koreksi usia; kolom usia di
 *    Excel hanya ditampilkan. Jadi usia di sini dicatat sebagai informasi.
 *  - Norma ini untuk usia 16 tahun ke atas.
 *  - Mandiri: tidak butuh wpt_scoring.php.
 */

const CFIT_IQ_TABLE = [
     0 =>  38,  1 =>  40,  2 =>  43,  3 =>  45,  4 =>  47,  5 =>  48,
     6 =>  52,  7 =>  55,  8 =>  57,  9 =>  60, 10 =>  63, 11 =>  67,
    12 =>  70, 13 =>  72, 14 =>  75, 15 =>  78, 16 =>  81, 17 =>  85,
    18 =>  88, 19 =>  91, 20 =>  94, 21 =>  96, 22 => 100, 23 => 103,
    24 => 106, 25 => 109, 26 => 113, 27 => 116, 28 => 119, 29 => 121,
    30 => 124, 31 => 128, 32 => 131, 33 => 133, 34 => 137, 35 => 140,
    36 => 142, 37 => 145, 38 => 149, 39 => 152, 40 => 155, 41 => 157,
    42 => 161, 43 => 165, 44 => 167, 45 => 169, 46 => 173, 47 => 176,
    48 => 179, 49 => 183, 50 => 183,
];

/** Klasifikasi sesuai rumus di file Excel (kolom D70). */
function cfitKlasifikasi(int $iq): string {
    if ($iq <= 69)  return 'Mentally Retardation';
    if ($iq <= 79)  return 'Borderline Defective';
    if ($iq <= 89)  return 'Low Average';
    if ($iq <= 109) return 'Average';
    if ($iq <= 119) return 'High Average';
    if ($iq <= 139) return 'Superior';
    if ($iq <= 169) return 'Very Superior';
    return 'Genius';
}

/** Usia (tahun) dari users.tanggal_lahir atau users.usia; null kalau tidak ada. */
function cfitUsiaPeserta(array $user): ?int {
    if (!empty($user['tanggal_lahir']) && $user['tanggal_lahir'] !== '0000-00-00') {
        try {
            return (new DateTime($user['tanggal_lahir']))->diff(new DateTime('today'))->y;
        } catch (Exception $e) { /* abaikan */ }
    }
    if (isset($user['usia']) && (int)$user['usia'] > 0) return (int)$user['usia'];
    return null;
}

/**
 * @param int      $skorMentah jumlah benar gabungan bagian 1-4
 * @param int|null $usia       hanya dicatat; tidak memengaruhi IQ (sesuai norma)
 */
function cfitHitungIq(int $skorMentah, ?int $usia = null): array {
    $kunci = max(0, min(50, $skorMentah));
    $iq = CFIT_IQ_TABLE[$kunci];

    return [
        'skor_mentah'  => $skorMentah,
        'usia'         => $usia,
        'koreksi_usia' => 0,
        'skor_koreksi' => $skorMentah,
        'iq'           => $iq,
        'klasifikasi'  => cfitKlasifikasi($iq),
    ];
}

/**
 * Hitung + simpan IQ CFIT. Panggil setelah SETIAP bagian CFIT selesai
 * (aman dipanggil untuk tes apa pun): baru menghitung kalau tes ini
 * ber-skala_iq 'cfit', punya grup, dan semua bagian dalam grup sudah
 * selesai oleh peserta yang sama. Hasil disimpan di attempt yang baru
 * selesai (bagian terakhir): attempt.iq_score, iq_klasifikasi, dan
 * detail_skor['cfit'] (skor gabungan + rincian per bagian). total_benar
 * tiap attempt TIDAK diubah, jadi aman dihitung ulang berkali-kali.
 *
 * Return array hasil, atau null kalau belum waktunya / bukan CFIT.
 */
function cfitSimpanIq(PDO $db, int $attemptId): ?array {
    $stmt = $db->prepare("
        SELECT a.id, a.user_id, a.detail_skor, t.skala_iq, t.grup_id
        FROM attempt a JOIN tes t ON t.id = a.tes_id
        WHERE a.id = ?
    ");
    $stmt->execute([$attemptId]);
    $a = $stmt->fetch();
    if (!$a || $a['skala_iq'] !== 'cfit' || empty($a['grup_id'])) return null;

    // Semua bagian dalam grup + skor attempt selesai terakhir milik peserta ini
    $stmt = $db->prepare("
        SELECT t.id AS tes_id, t.judul,
               (SELECT x.total_benar FROM attempt x
                 WHERE x.tes_id = t.id AND x.user_id = ? AND x.status = 'selesai'
                 ORDER BY x.id DESC LIMIT 1) AS benar
        FROM tes t
        WHERE t.grup_id = ?
        ORDER BY t.urutan_dalam_grup ASC, t.id ASC
    ");
    $stmt->execute([$a['user_id'], $a['grup_id']]);
    $bagian = $stmt->fetchAll();
    if (!$bagian) return null;

    $total = 0;
    $rincian = [];
    foreach ($bagian as $b) {
        if ($b['benar'] === null) return null;   // masih ada bagian yang belum selesai
        $total += (int)$b['benar'];
        $rincian[] = ['judul' => $b['judul'], 'benar' => (int)$b['benar']];
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$a['user_id']]);
    $user = $stmt->fetch() ?: [];

    $hasil = cfitHitungIq($total, cfitUsiaPeserta($user));
    $hasil['bagian'] = $rincian;

    // Gabungkan ke detail_skor (JSON) tanpa menimpa isi lama
    $detail = json_decode((string)$a['detail_skor'], true);
    if (!is_array($detail)) $detail = [];
    $detail['cfit'] = $hasil;

    $stmt = $db->prepare("UPDATE attempt SET iq_score = ?, iq_klasifikasi = ?, detail_skor = ? WHERE id = ?");
    $stmt->execute([$hasil['iq'], $hasil['klasifikasi'], json_encode($detail, JSON_UNESCAPED_UNICODE), $attemptId]);

    return $hasil;
}