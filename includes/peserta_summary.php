<?php
/**
 * Summary semua tes untuk SATU peserta.
 *
 * ambilSummaryPeserta() mengembalikan struktur yang sudah dinormalisasi,
 * sehingga halaman web, PDF, dan Excel cukup me-render struktur yang sama:
 *
 * [
 *   'peserta'  => ['id','nama','email'],
 *   'dibuat'   => 'dd/mm/yyyy HH:ii',
 *   'sections' => [
 *       [
 *         'attempt_id', 'judul', 'jenis', 'tipe', 'tanggal',
 *         'hasil'      => string (hasil utama, mis. "IQ 112" / "DISC: Achiever"),
 *         'keterangan' => string,
 *         'tables'     => [ ['headers'=>[...], 'rows'=>[[...],[...]], 'left'=>[indeks kolom rata kiri]], ... ],
 *         'notes'      => [ string, ... ],
 *       ], ...
 *   ]
 * ]
 *
 * Aturan: kalau peserta mengerjakan tes yang sama lebih dari sekali,
 * yang dipakai adalah attempt SELESAI yang paling baru.
 *
 * Rincian (tabel) disamakan dengan admin/hasil_detail.php dan HANYA dibuat
 * untuk: WPT, CFIT, Papikostik, DISC, dan MBTI (tes eksternal 16Personalities).
 * Tes lain hanya menampilkan hasil utama.
 */
require_once __DIR__ . '/papikostik_scoring.php';
require_once __DIR__ . '/disc_scoring.php';
require_once __DIR__ . '/disc_data.php';
require_once __DIR__ . '/mbti_data.php';

// Nama & label 5 sifat 16Personalities (sama seperti di hasil_detail.php)
const SUM16_NAMA  = ['Energi', 'Pikiran', 'Perasaan', 'Taktik', 'Identitas'];
const SUM16_LABEL = [
    ['E' => 'Ekstrovert',  'I' => 'Introvert'],
    ['N' => 'Visioner',    'S' => 'Realistik'],
    ['T' => 'Rasional',    'F' => 'Emosional'],
    ['J' => 'Perencanaan', 'P' => 'Improvisasi'],
    ['A' => 'Tegas',       'T' => 'Waspada'],
];

// Warna tabel DISC (tema hijau soft). Ubah di sini kalau mau ganti warna.
const SUM_DISC_WARNA = [
    'header_bg'   => '#bfe3cc', // baris header (Mask / Core / Mirror)
    'header_text' => '#14532d',
    'cell_bg'     => '#eef8f1', // isi tabel
    'cell_text'   => '#1f6b3f', // teks sifat
    'bold_text'   => '#0f172a', // baris nama pola (huruf tebal kapital)
];

function ambilSummaryPeserta(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare("SELECT id, nama, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $peserta = $stmt->fetch();
    if (!$peserta) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT a.*, t.judul AS judul_tes, t.jenis, t.tipe_psikotes
        FROM attempt a
        JOIN tes t ON t.id = a.tes_id
        WHERE a.user_id = ?
          AND a.status = 'selesai'
          AND a.id = (
              SELECT MAX(a2.id) FROM attempt a2
              WHERE a2.user_id = a.user_id AND a2.tes_id = a.tes_id AND a2.status = 'selesai'
          )
        ORDER BY t.id ASC
    ");
    $stmt->execute([$userId]);

    $sections = [];
    foreach ($stmt->fetchAll() as $a) {
        $sections[] = bangunSectionTes($a);
    }

    return [
        'peserta'  => $peserta,
        'dibuat'   => date('d/m/Y H:i'),
        'sections' => $sections,
    ];
}

function tanggalAttempt(array $a): string
{
    foreach (['waktu_selesai', 'waktu_mulai'] as $k) {
        if (!empty($a[$k])) {
            $ts = strtotime($a[$k]);
            if ($ts) {
                return date('d/m/Y H:i', $ts);
            }
        }
    }
    return '-';
}

function bangunSectionTes(array $a): array
{
    $detail = !empty($a['detail_skor']) ? json_decode($a['detail_skor'], true) : null;
    if (!is_array($detail)) {
        $detail = [];
    }

    $sec = [
        'attempt_id' => (int)$a['id'],
        'judul'      => $a['judul_tes'],
        'jenis'      => $a['jenis'],
        'tipe'       => $a['tipe_psikotes'],
        'tanggal'    => tanggalAttempt($a),
        'hasil'      => '-',
        'keterangan' => '',
        'tables'     => [],
        'notes'      => [],
    ];

    // ---------- Tes eksternal: MBTI / 16Personalities (diisi sendiri oleh peserta) ----------
    if ($a['jenis'] === 'eksternal') {
        $t16 = (isset($detail['tipe16']) && is_array($detail['tipe16'])) ? $detail['tipe16'] : [];
        $tipe = strtoupper(trim((string)($t16['tipe'] ?? ($a['profil_hasil'] ?? ''))));
        $sec['hasil'] = $tipe !== '' ? $tipe : 'Belum diisi';

        $desk = mbtiDataTipe($tipe);
        $sec['keterangan'] = $desk ? $desk['nama'] : 'Tes eksternal';

        $sisi   = is_array($t16['sisi'] ?? null)   ? array_values($t16['sisi'])   : [];
        $persen = is_array($t16['persen'] ?? null) ? array_values($t16['persen']) : [];
        // Data lama tanpa 'sisi': turunkan dari tipe
        if (count($sisi) !== 5 && preg_match('/^([EI])([NS])([TF])([JP])-([AT])$/', $tipe, $m)) {
            $sisi = [$m[1], $m[2], $m[3], $m[4], $m[5]];
        }

        if (count($sisi) === 5 && count($persen) === 5) {
            $rows = [];
            foreach ($sisi as $i => $kode) {
                $rows[] = [SUM16_NAMA[$i], SUM16_LABEL[$i][$kode] ?? $kode, (int)$persen[$i] . '%'];
            }
            $sec['tables'][] = [
                'headers' => ['Sifat', 'Sisi', 'Persentase'],
                'rows'    => $rows,
                'left'    => [0, 1],
            ];
        }

        if ($desk) {
            $sec['notes'][] = 'Deskripsi tipe kepribadian: ' . $desk['deskripsi'];
        }

        // Link hanya ditampilkan kalau benar-benar dari 16personalities.com
        $link = $t16['link'] ?? null;
        if ($link) {
            $pl = parse_url($link);
            if (($pl['scheme'] ?? '') === 'https'
                && in_array(strtolower($pl['host'] ?? ''), ['16personalities.com', 'www.16personalities.com'], true)) {
                $sec['notes'][] = 'Link hasil: ' . $link;
            }
        }
        if ($tipe === '') {
            $sec['notes'][] = 'Peserta belum mengisi tipe dan persentase hasil tes.';
        }
        return $sec;
    }

    // ---------- Tes logika (WPT / CFIT) ----------
    if ($a['jenis'] === 'logika') {
        $rincian = null;
        $jenisIq = 'WPT';
        if (isset($detail['wpt']) && is_array($detail['wpt'])) {
            $rincian = $detail['wpt'];
        } elseif (isset($detail['cfit']) && is_array($detail['cfit'])) {
            $rincian = $detail['cfit'];
            $jenisIq = 'CFIT';
        }
        // Judul / tipe tes lebih akurat menentukan label (sama seperti hasil_detail)
        $teksPenentu = strtolower(($a['tipe_psikotes'] ?? '') . ' ' . ($a['judul_tes'] ?? ''));
        if (strpos($teksPenentu, 'cfit') !== false) {
            $jenisIq = 'CFIT';
        } elseif (strpos($teksPenentu, 'wpt') !== false) {
            $jenisIq = 'WPT';
        }

        $iq = $a['iq_score'] ?? null;

        if ($iq !== null) {
            $sec['hasil'] = 'IQ ' . (int)$iq;
            $sec['keterangan'] = trim(($a['iq_klasifikasi'] ?? '') . ' (' . (int)$a['total_benar'] . ' benar)');
        } else {
            $sec['hasil'] = 'Skor ' . (int)($a['total_skor'] ?? 0);
            $sec['keterangan'] = (int)$a['total_benar'] . ' jawaban benar';
        }

        if ($rincian) {
            $usia = $rincian['usia'] ?? null;
            $sec['tables'][] = [
                'headers' => ['Skor Mentah (Benar)', 'Usia', 'Koreksi Usia', 'Skor Setelah Koreksi', 'IQ', 'Klasifikasi'],
                'rows'    => [[
                    (int)($rincian['skor_mentah'] ?? 0),
                    $usia !== null ? (int)$usia . ' th' : 'tidak diketahui',
                    '+' . (int)($rincian['koreksi_usia'] ?? 0),
                    (int)($rincian['skor_koreksi'] ?? 0),
                    (int)($rincian['iq'] ?? 0),
                    $rincian['klasifikasi'] ?? '-',
                ]],
            ];
            $sec['notes'][] = 'Konversi IQ ' . $jenisIq . ': skor mentah + koreksi usia, lalu dicocokkan ke tabel konversi ' . $jenisIq . '.';
            if ($usia === null) {
                $sec['notes'][] = 'Usia peserta tidak tercatat, koreksi usia dianggap 0 (setara usia 16–29 tahun).';
            } elseif ((int)($rincian['skor_koreksi'] ?? 0) > 44) {
                $sec['notes'][] = 'Tabel konversi ' . $jenisIq . ' hanya mencakup skor sampai 44, jadi IQ dibatasi pada nilai tertinggi tabel.';
            }
        }
        return $sec;
    }

    // ---------- DISC (disamakan dengan "Gambaran Karakter" di hasil_detail) ----------
    // Profil akhir = Core Private Self (index_least), bukan Mirror.
    if ($a['tipe_psikotes'] === 'disc' && $detail) {
        $idx = [
            'Mask Public Self'      => isset($detail['index_most'])    ? (int)$detail['index_most']    : null,
            'Core Private Self'     => isset($detail['index_least'])   ? (int)$detail['index_least']   : null,
            'Mirror Perceived Self' => isset($detail['index_selisih']) ? (int)$detail['index_selisih'] : null,
        ];

        // Tiga kolom berdampingan seperti kotak di hasil_detail:
        // baris 1 = kombinasi, baris 2 = nama pola, baris berikutnya = satu sifat per baris.
        $headers = [];
        $kolKomb = [];
        $kolPola = [];
        $kolSifat = [];
        $defCore = null;
        foreach ($idx as $label => $i) {
            $def = discDataPola($i);
            if ($label === 'Core Private Self') {
                $defCore = $def;
            }
            $headers[]  = $label . ($label === 'Core Private Self' ? ' (Profil Akhir)' : '');
            $kolKomb[]  = 'Kombinasi: ' . ($i !== null ? (DISC_KOMBINASI_40[$i] ?? '-') : '-');
            $kolPola[]  = $def ? $def['nama'] : '#N/A';
            $kolSifat[] = $def ? array_values($def['sifat']) : ['Pola tidak ditemukan'];
        }

        $rows = [$kolKomb, $kolPola];
        $maks = max(array_map('count', $kolSifat));
        for ($r = 0; $r < $maks; $r++) {
            $rows[] = [$kolSifat[0][$r] ?? '', $kolSifat[1][$r] ?? '', $kolSifat[2][$r] ?? ''];
        }
        $sec['tables'][] = [
            'headers'   => $headers,
            'rows'      => $rows,
            'left'      => [0, 1, 2],
            'theme'     => 'disc',
            'bold_rows' => [1],
        ];

        // Nama profil diambil dari pola Core; profil_hasil hanya cadangan
        // kalau pola Core tidak ditemukan.
        $pola = $defCore['nama'] ?? null;
        $sec['hasil'] = $pola ?? (($a['profil_hasil'] ?? '') !== '' ? $a['profil_hasil'] : '-');
        $sec['keterangan'] = 'Profil akhir = Core Private Self' . ($pola ? ' (' . $pola . ')' : '');

        if ($defCore) {
            $sec['notes'][] = 'Deskripsi Kepribadian: ' . $defCore['deskripsi'];
            $sec['notes'][] = 'Job Match: ' . $defCore['job'];
        }
        return $sec;
    }

    // ---------- Papikostik ----------
    if ($a['tipe_psikotes'] === 'papikostik') {
        $dominan = $detail['dominan'] ?? [];
        $sec['hasil'] = $dominan ? implode(', ', $dominan) : ($a['profil_hasil'] ?? '-');
        $sec['keterangan'] = '3 dimensi dominan';

        if (!empty($detail['skor'])) {
            $rows = [];
            foreach (PAPIKOSTIK_DIMENSI as $h) {
                $rows[] = [
                    $h,
                    PAPIKOSTIK_LABEL[$h] ?? $h,
                    (int)($detail['skor'][$h] ?? 0),
                    $detail['interpretasi'][$h] ?? '-',
                ];
            }
            $sec['tables'][] = [
                'headers' => ['Dim.', 'Nama Dimensi', 'Skor', 'Interpretasi'],
                'rows'    => $rows,
                'left'    => [1, 3],
            ];
            if ($dominan) {
                $sec['notes'][] = '3 dimensi dengan skor tertinggi: ' . implode(', ', $dominan) . '.';
            }
        } else {
            $sec['notes'][] = 'Rincian skor belum dihitung. Buka detail hasil tes ini lalu klik "Hitung Skor Papikostik".';
        }
        return $sec;
    }

    // ---------- Tipe lain (fallback: hasil utama saja, tanpa rincian) ----------
    $sec['hasil'] = ($a['profil_hasil'] ?? '') !== '' ? $a['profil_hasil'] : (string)($a['total_skor'] ?? '-');
    return $sec;
}

/** Tabel-tabel sebuah section sebagai HTML (dipakai halaman web & PDF). */
function renderSectionTablesHtml(array $sec): string
{
    $w = SUM_DISC_WARNA;
    $out = '';
    foreach ($sec['tables'] as $t) {
        $left = $t['left'] ?? [];
        $disc = ($t['theme'] ?? '') === 'disc';
        $boldRows = $t['bold_rows'] ?? [];
        $out .= '<table class="table table-bordered table-sm summary-table"><thead><tr>';
        foreach ($t['headers'] as $ci => $h) {
            $st = in_array($ci, $left, true) ? 'text-align:left;' : '';
            if ($disc) $st .= 'background:' . $w['header_bg'] . ';color:' . $w['header_text'] . ';font-weight:800;';
            $out .= '<th' . ($st !== '' ? ' style="' . $st . '"' : '') . '>' . e((string)$h) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($t['rows'] as $ri => $r) {
            $out .= '<tr>';
            foreach ($r as $ci => $c) {
                $st = in_array($ci, $left, true) ? 'text-align:left;' : '';
                if ($disc) {
                    $st .= 'background:' . $w['cell_bg'] . ';';
                    $st .= in_array($ri, $boldRows, true)
                        ? 'color:' . $w['bold_text'] . ';font-weight:800;text-transform:uppercase;'
                        : 'color:' . $w['cell_text'] . ';font-weight:600;';
                }
                $out .= '<td' . ($st !== '' ? ' style="' . $st . '"' : '') . '>' . e((string)$c) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';
    }
    foreach ($sec['notes'] as $n) {
        $out .= '<p class="summary-note">' . e($n) . '</p>';
    }
    return $out;
}