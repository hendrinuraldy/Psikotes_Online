<?php
/**
 * Mesin skor untuk Tes DISC format forced-choice (Most/Least).
 *
 * Rekonstruksi dari formula DISC.xls (sheet "Input", "Result", "Sheet3", "Def").
 *
 * ALUR (sama dengan Excel):
 * 1. TALLY MENTAH: hitung huruf D/I/S/C yang terkumpul dari pilihan "Most" dan
 *    dari pilihan "Least" (24 soal). Selisih = Most - Least.
 *
 *    PENTING (INI PERBAIKAN UTAMA): di sheet "Input" Excel, setiap opsi punya
 *    DUA huruf yang BERBEDA - satu kalau opsi itu dipilih sebagai MOST
 *    (kolom E/J/O) dan satu lagi kalau dipilih sebagai LEAST (kolom F/K/P).
 *    Tanda "*" artinya pilihan itu tidak dihitung. Contoh soal 1:
 *        opsi 3 -> Most = "*" (tidak dihitung), Least = "D".
 *    Di database: opsi.dimensi       = huruf kalau dipilih MOST
 *                 opsi.dimensi_least = huruf kalau dipilih LEAST
 *    Versi sebelumnya memakai opsi.dimensi untuk Least juga, sehingga tally
 *    Least (dan grafik Core Private Self) salah. Sekarang Least memakai
 *    opsi.dimensi_least.
 * 2. KONVERSI (VLOOKUP ke Sheet3): tally -> nilai plot, 3 tabel berbeda
 *    (Most -> Mask, Least -> Core, Selisih -> Mirror).
 * 3. KLASIFIKASI POLA: 40 aturan boolean (kolom BH:CU sheet Result); aturan
 *    PERTAMA yang cocok = pola (MATCH(1,...,0)).
 * 4. Profil akhir (headline, deskripsi, job match) = pola grafik SELISIH (Mirror).
 *
 * CATATAN:
 * - Tabel Least tally 17 & 18 kolom D bernilai POSITIF di Excel (+6.7, +7);
 *   kemungkinan salah ketik di Excel. Ditranskrip apa adanya.
 * - Tabel Selisih, selisih=6, kolom S kosong di Excel -> Excel membacanya 0.
 *   (Versi lama mengisi 4.35 - itu menyimpang dari Excel.)
 * - Aturan berantai di Excel seperti "BC>=BB>=BE" sebenarnya TIDAK menguji urutan:
 *   Excel menghitung (BC>=BB) menjadi TRUE/FALSE, lalu membandingkannya dengan
 *   angka, dan TRUE/FALSE selalu dianggap lebih besar dari angka -> hasilnya
 *   SELALU TRUE. Terdampak: pola 5, 6, 7, 10, 12, 13, 19, 20. Akibatnya pola
 *   19, 22, 27, 37, 39 praktis tidak pernah muncul di Excel (selalu "kalah" oleh
 *   pola 5). Konstanta DISC_MENIRU_RANTAI_EXCEL di bawah mengatur ini:
 *     true  = sama persis dengan hasil Excel (default, supaya cocok dengan cek manual)
 *     false = urutan benar-benar diuji (secara logika "lebih benar", tapi bisa
 *             berbeda dari Excel untuk pola-pola di atas)
 * - Tally/selisih di luar domain tabel di-clamp ke batas tabel.
 */

/** true = ikuti perilaku Excel apa adanya pada aturan berantai a>=b>=c. */
const DISC_MENIRU_RANTAI_EXCEL = true;

// ---------------------------------------------------------------------
// TABEL KONVERSI (persis dari sheet "Sheet3" file DISC.xls)
// ---------------------------------------------------------------------

/** Tally "Most" (0-20) -> nilai plot Graph 1 (Mask Public Self). */
const DISC_TABEL_MOST = [
    0  => ['D' => -6, 'I' => -7, 'S' => -5.7, 'C' => -6],
    1  => ['D' => -5.3, 'I' => -4.6, 'S' => -4.3, 'C' => -4.7],
    2  => ['D' => -4, 'I' => -2.5, 'S' => -3.5, 'C' => -3.5],
    3  => ['D' => -2.5, 'I' => -1.3, 'S' => -1.5, 'C' => -1.5],
    4  => ['D' => -1.7, 'I' => 1, 'S' => -0.7, 'C' => 0.5],
    5  => ['D' => -1.3, 'I' => 3, 'S' => 0.5, 'C' => 2],
    6  => ['D' => 0, 'I' => 3.5, 'S' => 1, 'C' => 3],
    7  => ['D' => 0.5, 'I' => 5.3, 'S' => 2.5, 'C' => 5.3],
    8  => ['D' => 1, 'I' => 5.7, 'S' => 3, 'C' => 5.7],
    9  => ['D' => 2, 'I' => 6, 'S' => 4, 'C' => 6],
    10 => ['D' => 3, 'I' => 6.5, 'S' => 4.6, 'C' => 6.3],
    11 => ['D' => 3.5, 'I' => 7, 'S' => 5, 'C' => 6.5],
    12 => ['D' => 4, 'I' => 7, 'S' => 5.7, 'C' => 6.7],
    13 => ['D' => 4.7, 'I' => 7, 'S' => 6, 'C' => 7],
    14 => ['D' => 5.3, 'I' => 7, 'S' => 6.5, 'C' => 7.3],
    15 => ['D' => 6.5, 'I' => 7, 'S' => 6.5, 'C' => 7.3],
    16 => ['D' => 7, 'I' => 7.5, 'S' => 7, 'C' => 7.3],
    17 => ['D' => 7, 'I' => 7.5, 'S' => 7, 'C' => 7.5],
    18 => ['D' => 7, 'I' => 7.5, 'S' => 7, 'C' => 8],
    19 => ['D' => 7.5, 'I' => 7.5, 'S' => 7.5, 'C' => 8],
    20 => ['D' => 7.5, 'I' => 8, 'S' => 7.5, 'C' => 8],
];

/** Tally "Least" (0-20) -> nilai plot Graph 2 (Core Private Self). */
const DISC_TABEL_LEAST = [
    0  => ['D' => 7.5, 'I' => 7, 'S' => 7.5, 'C' => 7.5],
    1  => ['D' => 6.5, 'I' => 6, 'S' => 7, 'C' => 7],
    2  => ['D' => 4.3, 'I' => 4, 'S' => 6, 'C' => 5.6],
    3  => ['D' => 2.5, 'I' => 2.5, 'S' => 4, 'C' => 4],
    4  => ['D' => 1.5, 'I' => 0.5, 'S' => 2.5, 'C' => 2.5],
    5  => ['D' => 0.5, 'I' => 0, 'S' => 1.5, 'C' => 1.5],
    6  => ['D' => 0, 'I' => -2, 'S' => 0.5, 'C' => 0.5],
    7  => ['D' => -1.3, 'I' => -3.5, 'S' => -1.3, 'C' => 0],
    8  => ['D' => -1.5, 'I' => -4.3, 'S' => -2, 'C' => -1.3],
    9  => ['D' => -2.5, 'I' => -5.3, 'S' => -3, 'C' => -2.5],
    10 => ['D' => -3, 'I' => -6, 'S' => -4.3, 'C' => -3.5],
    11 => ['D' => -3.5, 'I' => -6.5, 'S' => -5.3, 'C' => -5.3],
    12 => ['D' => -4.3, 'I' => -7, 'S' => -6, 'C' => -5.7],
    13 => ['D' => -5.3, 'I' => -7.2, 'S' => -6.5, 'C' => -6],
    14 => ['D' => -5.7, 'I' => -7.2, 'S' => -6.7, 'C' => -6.5],
    15 => ['D' => -6, 'I' => -7.2, 'S' => -6.7, 'C' => -7],
    16 => ['D' => -6.5, 'I' => -7.3, 'S' => -7, 'C' => -7.3],
    // 17 & 18: nilai D positif (+6.7, +7) APA ADANYA dari Excel - lihat catatan di kepala file.
    17 => ['D' => 6.7, 'I' => -7.3, 'S' => -7.2, 'C' => -7.5],
    18 => ['D' => 7, 'I' => -7.3, 'S' => -7.3, 'C' => -7.7],
    19 => ['D' => -7.3, 'I' => -7.5, 'S' => -7.5, 'C' => -7.9],
    20 => ['D' => -7.5, 'I' => -8, 'S' => -8, 'C' => -8],
];

/** Selisih Most-Least (-22..22) -> nilai plot Graph 3 (Mirror Perceived Self). */
const DISC_TABEL_SELISIH = [
    -22 => ['D' => -8, 'I' => -8, 'S' => -8, 'C' => -7.5],
    -21 => ['D' => -7.5, 'I' => -8, 'S' => -8, 'C' => -7.3],
    -20 => ['D' => -7, 'I' => -8, 'S' => -8, 'C' => -7.3],
    -19 => ['D' => -6.8, 'I' => -8, 'S' => -8, 'C' => -7],
    -18 => ['D' => -6.75, 'I' => -7, 'S' => -7.5, 'C' => -6.7],
    -17 => ['D' => -6.7, 'I' => -6.7, 'S' => -7.3, 'C' => -6.7],
    -16 => ['D' => -6.5, 'I' => -6.7, 'S' => -7.3, 'C' => -6.7],
    -15 => ['D' => -6.3, 'I' => -6.7, 'S' => -7, 'C' => -6.5],
    -14 => ['D' => -6.1, 'I' => -6.7, 'S' => -6.5, 'C' => -6.3],
    -13 => ['D' => -5.9, 'I' => -6.7, 'S' => -6.5, 'C' => -6],
    -12 => ['D' => -5.7, 'I' => -6.7, 'S' => -6.5, 'C' => -5.85],
    -11 => ['D' => -5.3, 'I' => -6.7, 'S' => -6.5, 'C' => -5.85],
    -10 => ['D' => -4.3, 'I' => -6.5, 'S' => -6, 'C' => -5.7],
    -9  => ['D' => -3.5, 'I' => -6, 'S' => -4.7, 'C' => -4.7],
    -8  => ['D' => -3.25, 'I' => -5.7, 'S' => -4.3, 'C' => -4.3],
    -7  => ['D' => -3, 'I' => -4.7, 'S' => -3.5, 'C' => -3.5],
    -6  => ['D' => -2.75, 'I' => -4.3, 'S' => -3, 'C' => -3],
    -5  => ['D' => -2.5, 'I' => -3.5, 'S' => -2, 'C' => -2.5],
    -4  => ['D' => -1.5, 'I' => -3, 'S' => -1.5, 'C' => -0.5],
    -3  => ['D' => -1, 'I' => -2, 'S' => -1, 'C' => 0],
    -2  => ['D' => -0.5, 'I' => -1.5, 'S' => -0.5, 'C' => 0.3],
    -1  => ['D' => -0.25, 'I' => 0, 'S' => 0, 'C' => 0.5],
    0   => ['D' => 0, 'I' => 0.5, 'S' => 1, 'C' => 1.5],
    1   => ['D' => 0.5, 'I' => 1, 'S' => 1.5, 'C' => 3],
    2   => ['D' => 0.7, 'I' => 1.5, 'S' => 2, 'C' => 4],
    3   => ['D' => 1, 'I' => 3, 'S' => 3, 'C' => 4.3],
    4   => ['D' => 1.3, 'I' => 4, 'S' => 3.5, 'C' => 5.5],
    5   => ['D' => 1.5, 'I' => 4.3, 'S' => 4, 'C' => 5.7],
    // selisih=6, kolom S KOSONG di Excel -> VLOOKUP Excel mengembalikan 0. Dipakai 0 (sebelumnya diisi 4.35 = salah).
    6   => ['D' => 2, 'I' => 5, 'S' => 0, 'C' => 6],
    7   => ['D' => 2.5, 'I' => 5.5, 'S' => 4.7, 'C' => 6.3],
    8   => ['D' => 3.5, 'I' => 6.5, 'S' => 5, 'C' => 6.5],
    9   => ['D' => 4, 'I' => 6.7, 'S' => 5.5, 'C' => 6.7],
    10  => ['D' => 4.7, 'I' => 7, 'S' => 6, 'C' => 7],
    11  => ['D' => 4.85, 'I' => 7.3, 'S' => 6.2, 'C' => 7.3],
    12  => ['D' => 5, 'I' => 7.3, 'S' => 6.3, 'C' => 7.3],
    13  => ['D' => 5.5, 'I' => 7.3, 'S' => 6.5, 'C' => 7.3],
    14  => ['D' => 6, 'I' => 7.3, 'S' => 6.7, 'C' => 7.3],
    15  => ['D' => 6.3, 'I' => 7.3, 'S' => 7, 'C' => 7.3],
    16  => ['D' => 6.5, 'I' => 7.3, 'S' => 7.3, 'C' => 7.3],
    17  => ['D' => 6.7, 'I' => 7.3, 'S' => 7.3, 'C' => 7.5],
    18  => ['D' => 7, 'I' => 7.5, 'S' => 7.3, 'C' => 8],
    19  => ['D' => 7.3, 'I' => 8, 'S' => 7.3, 'C' => 8],
    20  => ['D' => 7.3, 'I' => 8, 'S' => 7.5, 'C' => 8],
    21  => ['D' => 7.5, 'I' => 8, 'S' => 8, 'C' => 8],
    22  => ['D' => 8, 'I' => 8, 'S' => 8, 'C' => 8],
];

/**
 * 40 label kombinasi persis dari sheet "Def" (baris kombinasi), index 1-40.
 */
const DISC_KOMBINASI_40 = [
    1  => 'C',
    2  => 'D',
    3  => 'D / C-D',
    4  => 'D / I-D',
    5  => 'D / I-D-C',
    6  => 'D / I-D-S',
    7  => 'D / I-S-D',
    8  => 'D / S-D-C / S-C-D',
    9  => 'D-I',
    10 => 'D-I-S',
    11 => 'D-S',
    12 => 'I / C-I-S',
    13 => 'I / C-S-I',
    14 => 'I-S-C / I-C-S',
    15 => 'S',
    16 => 'S / C-S',
    17 => 'S-C',
    18 => 'D-C',
    19 => 'D-I-C',
    20 => 'D-S-I',
    21 => 'D-S-C',
    22 => 'D-C-I',
    23 => 'D-C-S',
    24 => 'I',
    25 => 'I-S',
    26 => 'I-C',
    27 => 'I-C-D',
    28 => 'I-C-S',
    29 => 'S-D',
    30 => 'S-I',
    31 => 'S-D-I',
    32 => 'S-I-D',
    33 => 'S-I-C',
    34 => 'S-C-D',
    35 => 'S-C-I',
    36 => 'C-I',
    37 => 'C-D-I',
    38 => 'C-D-S',
    39 => 'C-I-D',
    40 => 'C-S-D',
];

/** Nama pola persis dari sheet "Def" - fallback kalau disc_pola DB tidak punya baris yang cocok. */
const DISC_NAMA_POLA_40 = [
    1  => 'LOGICAL THINKER',
    2  => 'ESTABLISHER',
    3  => 'DESIGNER',
    4  => 'NEGOTIATOR',
    5  => 'CONFIDENT & DETERMINED',
    6  => 'REFORMER',
    7  => 'MOTIVATOR',
    8  => 'INQUIRER',
    9  => 'PENGAMBIL KEPUTUSAN',
    10 => 'DIRECTOR',
    11 => 'SELF-MOTIVATED',
    12 => 'MEDIATOR',
    13 => 'PRACTITIONER',
    14 => 'RESPONSIVE & THOUGHTFUL',
    15 => 'SPECIALIST',
    16 => 'PERFECTIONIST',
    17 => 'PEACEMAKER, RESPECTFULL & ACCURATE',
    18 => 'CHALLENGER',
    19 => 'CHANCELLOR',
    20 => 'DIRECTOR',
    21 => 'Director',
    22 => 'CHALLENGER',
    23 => 'CHALLENGER',
    24 => 'COMMUNICATOR',
    25 => 'ADVISOR',
    26 => 'ASSESSOR',
    27 => 'ASSESSOR',
    28 => 'RESPONSIVE & THOUGHTFUL',
    29 => 'SELF-MOTIVATED',
    30 => 'ADVISOR',
    31 => 'DIRECTOR',
    32 => 'ADVISOR',
    33 => 'ADVOCATE',
    34 => 'INQUIRER',
    35 => 'ADVOCATE',
    36 => 'ASSESSOR',
    37 => 'CHALLENGER',
    38 => 'CONTEMPLATOR',
    39 => 'ASSESSOR',
    40 => 'PRECISIONIST',
];

/**
 * Ambil satu baris tabel konversi (VLOOKUP approximate). x di luar domain
 * di-clamp ke batas tabel.
 */
function discLookupTabel(array $tabel, int $x): array
{
    $keys = array_keys($tabel);
    $min = min($keys);
    $max = max($keys);
    $xClamped = max($min, min($max, $x));
    return $tabel[$xClamped] ?? ['D' => 0, 'I' => 0, 'S' => 0, 'C' => 0];
}

/**
 * Klasifikasi 40 pola dari nilai plot (D,I,S,C) - replika 40 formula di kolom
 * BH:CU sheet "Result". Aturan dicek berurutan 1..40, yang PERTAMA cocok menang.
 *
 * @return int|null index 1-40, atau null (setara #N/A di Excel)
 */
function discKlasifikasiPola(float $d, float $i, float $s, float $c): ?int
{
    // Aturan berantai "a>=b>=c" di Excel: lihat catatan DISC_MENIRU_RANTAI_EXCEL.
    $rantai = function (bool $ab, float $c): bool {
        if (DISC_MENIRU_RANTAI_EXCEL) {
            return true;              // perilaku Excel: TRUE/FALSE > angka -> selalu TRUE
        }
        return ((int)$ab) >= $c;      // perilaku LibreOffice (TRUE=1, FALSE=0)
    };
    $aturan = [
        1  => fn() => $d <= 0 && $i <= 0 && $s <= 0 && $c > 0,
        2  => fn() => $d > 0 && $i <= 0 && $s <= 0 && $c <= 0,
        3  => fn() => $d > 0 && $i <= 0 && $s <= 0 && $c > 0 && $c >= $d,
        4  => fn() => $d > 0 && $i > 0 && $s <= 0 && $c <= 0 && $i >= $d,
        5  => fn() => $d > 0 && $i > 0 && $s <= 0 && $c > 0 && $rantai($i >= $d, $c),
        6  => fn() => $d > 0 && $i > 0 && $s > 0 && $c <= 0 && $rantai($i >= $d, $s),
        7  => fn() => $d > 0 && $i > 0 && $s > 0 && $c <= 0 && $rantai($i >= $s, $d),
        8  => fn() => $d > 0 && $i <= 0 && $s > 0 && $c > 0 && $s >= $d && $d >= $c,
        9  => fn() => $d > 0 && $i > 0 && $s <= 0 && $c <= 0 && $d >= $i,
        10 => fn() => $d > 0 && $i > 0 && $s > 0 && $c <= 0 && $rantai($d >= $i, $s),
        11 => fn() => $d > 0 && $i <= 0 && $s > 0 && $c <= 0 && $d >= $s,
        12 => fn() => $d <= 0 && $i > 0 && $s > 0 && $c > 0 && $rantai($c >= $i, $s),
        13 => fn() => $d <= 0 && $i > 0 && $s > 0 && $c > 0 && $rantai($c >= $s, $i),
        14 => fn() => $d <= 0 && $i > 0 && $s > 0 && $c > 0 && $i >= $s && $i >= $c,
        15 => fn() => $d <= 0 && $i <= 0 && $s > 0 && $c <= 0,
        16 => fn() => $d <= 0 && $i <= 0 && $s > 0 && $c > 0 && $c >= $s,
        17 => fn() => $d <= 0 && $i <= 0 && $s > 0 && $c > 0 && $s >= $c,
        18 => fn() => $i <= 0 && $s <= 0 && $d > 0 && $c > 0 && $d >= $c,
        19 => fn() => $d > 0 && $i > 0 && $c > 0 && $s <= 0 && $rantai($d >= $i, $c),
        20 => fn() => $d > 0 && $s > 0 && $i > 0 && $c <= 0 && $rantai($d >= $s, $i),
        21 => fn() => $d > 0 && $s > 0 && $c > 0 && $i <= 0 && $d >= $s && $s >= $c,
        22 => fn() => $d > 0 && $i > 0 && $c > 0 && $s <= 0 && $d >= $c && $c >= $i,
        23 => fn() => $d > 0 && $s > 0 && $c > 0 && $i <= 0 && $d >= $c && $c >= $s,
        24 => fn() => $d <= 0 && $s <= 0 && $c <= 0 && $i > 0,
        25 => fn() => $i > 0 && $s > 0 && $d <= 0 && $c <= 0 && $i >= $s,
        26 => fn() => $i > 0 && $c > 0 && $d <= 0 && $s <= 0 && $i >= $c,
        27 => fn() => $d > 0 && $i > 0 && $c > 0 && $s <= 0 && $i >= $c && $c >= $d,
        28 => fn() => $d <= 0 && $i > 0 && $s > 0 && $c > 0 && $i >= $c && $c >= $s,
        29 => fn() => $d > 0 && $i <= 0 && $s > 0 && $c <= 0 && $s >= $d,
        30 => fn() => $i > 0 && $s > 0 && $d <= 0 && $c <= 0 && $s >= $i,
        31 => fn() => $d > 0 && $i > 0 && $s > 0 && $c <= 0 && $s >= $d && $d >= $i,
        32 => fn() => $d > 0 && $i > 0 && $s > 0 && $c <= 0 && $s >= $i && $i >= $d,
        33 => fn() => $i > 0 && $s > 0 && $c > 0 && $d <= 0 && $s >= $i && $i >= $c,
        34 => fn() => $d > 0 && $i <= 0 && $s > 0 && $c > 0 && $s >= $c && $c >= $d,
        35 => fn() => $i > 0 && $s > 0 && $c > 0 && $d <= 0 && $s >= $c && $c >= $i,
        36 => fn() => $i > 0 && $c > 0 && $d <= 0 && $s <= 0 && $c >= $i,
        37 => fn() => $d > 0 && $i > 0 && $c > 0 && $s <= 0 && $c >= $d && $d >= $i,
        38 => fn() => $d > 0 && $s > 0 && $c > 0 && $i <= 0 && $c >= $d && $d >= $s,
        39 => fn() => $d > 0 && $i > 0 && $c > 0 && $s <= 0 && $c >= $i && $i >= $d,
        40 => fn() => $d > 0 && $s > 0 && $c > 0 && $i <= 0 && $c >= $s && $s >= $d,
    ];

    foreach ($aturan as $index => $cek) {
        if ($cek()) {
            return $index;
        }
    }

    return null;
}

/**
 * Cari baris disc_pola di database berdasarkan index pola (1-40).
 */
function cariPolaDiscByIndex(?int $index, $db): ?array
{
    if ($index === null) {
        return null;
    }

    $labelTarget = DISC_KOMBINASI_40[$index];

    $stmt = $db->query("SELECT * FROM disc_pola");
    $semuaPola = $stmt->fetchAll();

    // 1) Cocokkan label PENUH dulu
    foreach ($semuaPola as $pola) {
        if (mb_strtolower(trim($pola['kombinasi'])) === mb_strtolower(trim($labelTarget))) {
            return $pola;
        }
    }

    // 2) Fallback: cocokkan per varian
    $varianTarget = array_map('trim', explode('/', $labelTarget));
    foreach ($semuaPola as $pola) {
        $variasiDb = array_map('trim', explode('/', $pola['kombinasi']));
        foreach ($varianTarget as $v) {
            if (in_array($v, $variasiDb, true)) {
                return $pola;
            }
        }
    }

    return null;
}

/**
 * Hitung skor DISC lengkap (3 grafik) dari baris jawaban satu attempt.
 *
 * @param array $jawabanRows Baris dari query:
 *   SELECT j.tipe_pilihan, o.dimensi, o.dimensi_least
 *   FROM jawaban j JOIN opsi o ON o.id = j.opsi_id
 *   WHERE j.attempt_id = ? AND j.tipe_pilihan IN ('most','least')
 * @param PDO $db
 */
function hitungSkorDisc(array $jawabanRows, $db): array
{
    $huruf = ['D', 'I', 'S', 'C'];

    // 1) Tally mentah (Line 1 & Line 2 di Excel)
    $tallyMost = array_fill_keys($huruf, 0);
    $tallyLeast = array_fill_keys($huruf, 0);
    $jumlahMost = 0;
    $jumlahLeast = 0;
    $leastTanpaHuruf = 0; // baris Least yang opsinya tidak punya dimensi_least ("*" atau belum diisi)

    foreach ($jawabanRows as $row) {
        if ($row['tipe_pilihan'] === 'most') {
            $jumlahMost++;
            // Most -> huruf kolom Most (opsi.dimensi)
            $h = $row['dimensi'] ?? '';
            if (in_array($h, $huruf, true)) {
                $tallyMost[$h]++;
            }
        } elseif ($row['tipe_pilihan'] === 'least') {
            $jumlahLeast++;
            // Least -> huruf kolom Least (opsi.dimensi_least) - BUKAN opsi.dimensi
            $h = $row['dimensi_least'] ?? '';
            if (in_array($h, $huruf, true)) {
                $tallyLeast[$h]++;
            } else {
                $leastTanpaHuruf++;
            }
        }
    }

    // Peringatan: kalau SEMUA jawaban Least tidak punya huruf, hampir pasti
    // kolom opsi.dimensi_least belum terisi di database.
    $peringatan = null;
    if ($jumlahLeast > 0 && $leastTanpaHuruf === $jumlahLeast) {
        $peringatan = 'opsi.dimensi_least belum terisi - tally Least = 0, hasil Core/Mirror tidak valid.';
    }

    $selisihMentah = [];
    foreach ($huruf as $h) {
        $selisihMentah[$h] = $tallyMost[$h] - $tallyLeast[$h];
    }

    // 2) Konversi tally -> nilai plot
    $plotMost = [];
    $plotLeast = [];
    $plotSelisih = [];
    foreach ($huruf as $h) {
        $plotMost[$h] = discLookupTabel(DISC_TABEL_MOST, $tallyMost[$h])[$h];
        $plotLeast[$h] = discLookupTabel(DISC_TABEL_LEAST, $tallyLeast[$h])[$h];
        $plotSelisih[$h] = discLookupTabel(DISC_TABEL_SELISIH, $selisihMentah[$h])[$h];
    }

    // 3) Klasifikasi 40 pola untuk tiap grafik
    $indexMost = discKlasifikasiPola($plotMost['D'], $plotMost['I'], $plotMost['S'], $plotMost['C']);
    $indexLeast = discKlasifikasiPola($plotLeast['D'], $plotLeast['I'], $plotLeast['S'], $plotLeast['C']);
    $indexSelisih = discKlasifikasiPola($plotSelisih['D'], $plotSelisih['I'], $plotSelisih['S'], $plotSelisih['C']);

    // 4) Nama pola
    $polaSelisih = cariPolaDiscByIndex($indexSelisih, $db);
    $namaPolaSelisih = $polaSelisih['nama_pola'] ?? (DISC_NAMA_POLA_40[$indexSelisih] ?? null);

    $polaMost = cariPolaDiscByIndex($indexMost, $db);
    $namaPolaMost = $polaMost['nama_pola'] ?? (DISC_NAMA_POLA_40[$indexMost] ?? null);

    $polaLeast = cariPolaDiscByIndex($indexLeast, $db);
    $namaPolaLeast = $polaLeast['nama_pola'] ?? (DISC_NAMA_POLA_40[$indexLeast] ?? null);

    return [
        'graph1' => $tallyMost,
        'graph2' => $tallyLeast,
        'graph3' => $selisihMentah,

        'plot_most' => $plotMost,
        'plot_least' => $plotLeast,
        'plot_selisih' => $plotSelisih,

        'index_most' => $indexMost,
        'index_least' => $indexLeast,
        'index_selisih' => $indexSelisih,
        'nama_pola_most' => $namaPolaMost,
        'nama_pola_least' => $namaPolaLeast,
        'nama_pola_selisih' => $namaPolaSelisih,

        'kombinasi' => DISC_KOMBINASI_40[$indexSelisih] ?? '-',
        'pola' => $polaSelisih,
        'peringatan' => $peringatan,
    ];
}

/**
 * Simpan hasil akhir DISC ke tabel attempt.
 *
 * @param bool $hitungUlang true = hanya perbarui profil_hasil & detail_skor
 *   (status dan waktu_selesai TIDAK diubah). Dipakai tombol "Hitung ulang" admin.
 */
function simpanHasilDisc(int $attemptId, $db, bool $hitungUlang = false): array
{
    $stmt = $db->prepare("
        SELECT j.tipe_pilihan, o.dimensi, o.dimensi_least
        FROM jawaban j
        JOIN opsi o ON o.id = j.opsi_id
        WHERE j.attempt_id = ? AND j.tipe_pilihan IN ('most','least')
    ");
    $stmt->execute([$attemptId]);
    $rows = $stmt->fetchAll();

    $hasil = hitungSkorDisc($rows, $db);

    $namaPola = $hasil['nama_pola_selisih'] ?? ($hasil['kombinasi'] . '?');
    $namaPola = mb_substr($namaPola, 0, 20);

    if ($hitungUlang) {
        $stmt = $db->prepare("UPDATE attempt SET profil_hasil = ?, detail_skor = ? WHERE id = ?");
    } else {
        $stmt = $db->prepare("
            UPDATE attempt
            SET profil_hasil = ?, detail_skor = ?, status = 'selesai', waktu_selesai = NOW()
            WHERE id = ?
        ");
    }
    $stmt->execute([$namaPola, json_encode($hasil, JSON_UNESCAPED_UNICODE), $attemptId]);

    return $hasil;
}