<?php
/**
 * Simpan sebagai: includes/wpt_scoring.php
 *
 * Konversi skor mentah WPT (jumlah jawaban benar) -> IQ, berdasarkan
 * file WPT_IQ_SCALE.xlsx (Wonderlic Personnel Test - Conversion Table).
 */

const WPT_IQ_TABLE = [
    1 => 59, 2 => 61, 3 => 64, 4 => 67, 5 => 69, 6 => 71, 7 => 73, 8 => 75,
    9 => 78, 10 => 80, 11 => 81, 12 => 83, 13 => 86, 14 => 88, 15 => 90, 16 => 93,
    17 => 95, 18 => 97, 19 => 98, 20 => 100, 21 => 102, 22 => 104, 23 => 106, 24 => 108,
    25 => 111, 26 => 113, 27 => 114, 28 => 116, 29 => 118, 30 => 120, 31 => 121, 32 => 123,
    33 => 125, 34 => 126, 35 => 128, 36 => 130, 37 => 132, 38 => 134, 39 => 136, 40 => 138,
    41 => 140, 42 => 142, 43 => 143, 44 => 146,
];

/** Koreksi usia: ditambahkan ke skor mentah SEBELUM dikonversi ke IQ. */
function wptKoreksiUsia(?int $usia): int {
    if ($usia === null || $usia < 30) return 0;   // 16-29 (dan usia tidak diketahui)
    if ($usia <= 39) return 1;
    if ($usia <= 49) return 2;
    if ($usia <= 54) return 3;
    if ($usia <= 59) return 4;
    return 5;                                       // 60+
}

/** Klasifikasi Wechsler (sesuai tabel; 119 dimasukkan ke High Average). */
function wptKlasifikasi(int $iq): string {
    if ($iq >= 130) return 'Very Superior';
    if ($iq >= 120) return 'Superior';
    if ($iq >= 110) return 'High Average';
    if ($iq >= 90)  return 'Average';
    if ($iq >= 80)  return 'Low Average';
    if ($iq >= 70)  return 'Borderline';
    return 'Extremely Low';
}

/**
 * @param int      $skorMentah jumlah soal benar
 * @param int|null $usia       usia peserta (tahun); null = tanpa koreksi
 */
function wptHitungIq(int $skorMentah, ?int $usia = null): array {
    $koreksi = wptKoreksiUsia($usia);
    $skorKoreksi = $skorMentah + $koreksi;

    // Tabel hanya mencakup skor 1-44: di luar itu dibatasi ke ujung tabel.
    $kunci = max(1, min(44, $skorKoreksi));
    $iq = WPT_IQ_TABLE[$kunci];

    return [
        'skor_mentah'  => $skorMentah,
        'usia'         => $usia,
        'koreksi_usia' => $koreksi,
        'skor_koreksi' => $skorKoreksi,
        'iq'           => $iq,
        'klasifikasi'  => wptKlasifikasi($iq),
    ];
}

/** Ambil usia dari users.tanggal_lahir atau users.usia (mana yang ada). */
function wptUsiaPeserta(array $user): ?int {
    if (!empty($user['tanggal_lahir']) && $user['tanggal_lahir'] !== '0000-00-00') {
        try {
            return (new DateTime($user['tanggal_lahir']))->diff(new DateTime('today'))->y;
        } catch (Exception $e) { /* abaikan */ }
    }
    if (isset($user['usia']) && (int)$user['usia'] > 0) return (int)$user['usia'];
    return null;
}

/**
 * Hitung + simpan IQ untuk satu attempt. Aman dipanggil untuk tes apa pun:
 * kalau tes.skala_iq bukan 'wpt', tidak melakukan apa-apa (return null).
 */
function wptSimpanIq(PDO $db, int $attemptId): ?array {
    $stmt = $db->prepare("
        SELECT a.id, a.total_benar, a.detail_skor, a.user_id, t.skala_iq
        FROM attempt a JOIN tes t ON t.id = a.tes_id
        WHERE a.id = ?
    ");
    $stmt->execute([$attemptId]);
    $a = $stmt->fetch();
    if (!$a || $a['skala_iq'] !== 'wpt') return null;

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$a['user_id']]);
    $user = $stmt->fetch() ?: [];

    $hasil = wptHitungIq((int)$a['total_benar'], wptUsiaPeserta($user));

    // Gabungkan ke detail_skor (JSON) tanpa menimpa isi lama
    $detail = json_decode((string)$a['detail_skor'], true);
    if (!is_array($detail)) $detail = [];
    $detail['wpt'] = $hasil;

    $stmt = $db->prepare("UPDATE attempt SET iq_score = ?, iq_klasifikasi = ?, detail_skor = ? WHERE id = ?");
    $stmt->execute([$hasil['iq'], $hasil['klasifikasi'], json_encode($detail, JSON_UNESCAPED_UNICODE), $attemptId]);

    return $hasil;
}