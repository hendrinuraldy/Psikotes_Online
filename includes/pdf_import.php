<?php
/**
 * includes/pdf_import.php
 * -------------------------------------------------------------
 * Butuh library: composer require smalot/pdfparser
 * (jalankan di root project, akan membuat folder vendor/)
 *
 * OCR (fallback untuk PDF hasil scan/gambar) butuh 2 program terpasang
 * DI SERVER (bukan library PHP): poppler-utils (untuk pdftoppm) dan
 * tesseract-ocr. Di banyak shared hosting ini TIDAK tersedia dan tidak
 * bisa diinstal sendiri — kalau begitu, PDF hasil scan akan gagal
 * diproses otomatis dan sistem akan bilang jujur ke admin untuk input
 * manual, bukan diam-diam menghasilkan soal kosong/ngawur.
 *
 * Ubuntu/Debian: sudo apt install poppler-utils tesseract-ocr tesseract-ocr-ind
 *
 * -------------------------------------------------------------
 * PARSER SOAL — ADA 2 JALUR, DIPILIH OTOMATIS:
 *
 * 1) parseTabelSoalDariPdf()  — untuk PDF berbentuk TABEL berkolom, mis.
 *    "No | A <teks> | B <teks>" atau "No | A <teks> | B <teks> | C <teks> | D <teks> | E <teks>".
 *    Umum dipakai di soal psikotes/kepribadian bergaya forced-choice
 *    (PAPI Kostick, DISC, dll) yang diekspor dari Excel/Word ke PDF.
 *    Parser ini baca POSISI (koordinat X/Y) tiap kata lewat `pdftotext -bbox`,
 *    supaya teks yang wrap ke baris baru di satu kolom tidak tercampur
 *    dengan kolom sebelahnya — ini tidak mungkin diandalkan kalau cuma
 *    baca urutan teks linear biasa.
 *    Jumlah kolom opsi mengikuti apa yang benar-benar ada di PDF (2 kolom
 *    -> 2 opsi A/B per soal, 5 kolom -> 5 opsi A/B/C/D/E per soal).
 *
 * 2) parseTeksSoal()  — parser lama, untuk format naskah biasa:
 *    "1. Pertanyaan...\nA. Opsi\nB. Opsi..." (satu kolom, mengalir ke bawah).
 *
 * import_soal.php akan COBA jalur (1) dulu; kalau strukturnya tidak
 * terdeteksi sebagai tabel (return null), otomatis jatuh ke jalur (2).
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Ekstrak teks dari file PDF. Coba native text extraction dulu (akurat,
 * cepat, tidak butuh tools eksternal). Kalau hasilnya terlalu sedikit
 * (indikasi PDF berupa gambar/scan), coba OCR sebagai fallback.
 *
 * @return array{text:string, method:'native'|'ocr'|'failed'}
 */
function extractPdfText(string $filePath): array {
    $textNative = '';
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($filePath);
        $textNative = $pdf->getText();
    } catch (\Throwable $e) {
        $textNative = '';
    }

    $panjangBersih = strlen(trim(preg_replace('/\s+/', '', $textNative)));

    // Ambang batas kasar: kalau teks yang terbaca terlalu sedikit dibanding
    // ukuran file, kemungkinan besar ini PDF hasil scan (gambar), bukan teks asli.
    if ($panjangBersih > 200) {
        return ['text' => $textNative, 'method' => 'native'];
    }

    if (!ocrToolsAvailable()) {
        return [
            'text' => $textNative,
            'method' => $panjangBersih > 0 ? 'native' : 'failed',
        ];
    }

    $textOcr = ocrPdfFile($filePath);
    if (strlen(trim($textOcr)) > $panjangBersih) {
        return ['text' => $textOcr, 'method' => 'ocr'];
    }

    return ['text' => $textNative, 'method' => $panjangBersih > 0 ? 'native' : 'failed'];
}

function ocrToolsAvailable(): bool {
    if (!function_exists('shell_exec')) return false;
    $pdftoppm = trim((string)@shell_exec('which pdftoppm 2>/dev/null'));
    $tesseract = trim((string)@shell_exec('which tesseract 2>/dev/null'));
    return $pdftoppm !== '' && $tesseract !== '';
}

function ocrPdfFile(string $filePath): string {
    $tmpDir = sys_get_temp_dir() . '/ocr_' . uniqid();
    if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) return '';

    $prefix = $tmpDir . '/page';
    @shell_exec('pdftoppm -png -r 200 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($prefix) . ' 2>&1');

    $images = glob($prefix . '*.png') ?: [];
    sort($images);

    $fullText = '';
    foreach ($images as $img) {
        $out = @shell_exec('tesseract ' . escapeshellarg($img) . ' stdout -l ind+eng 2>/dev/null');
        $fullText .= ($out ?: '') . "\n";
        @unlink($img);
    }
    @rmdir($tmpDir);

    return $fullText;
}

/**
 * Parser heuristik: mengenali pola umum
 *   1. Pertanyaan .... (boleh beberapa baris)
 *   A. Opsi A
 *   B. Opsi B
 *   ...
 * Format nomor bisa "1." atau "1)", label opsi bisa "A." "A)" huruf besar/kecil.
 * Baris yang tidak cocok pola dianggap lanjutan baris sebelumnya (soal/opsi
 * yang terpotong ke baris baru).
 *
 * HASIL PARSING INI HARUS DIANGGAP DRAFT — selalu direview manual sebelum
 * disimpan, karena PDF dengan format tidak rapi / campur-campur bisa salah
 * dikenali (nomor tersasar, opsi ketuker, dsb).
 *
 * @return array<int, array{pertanyaan:string, opsi: array<int, array{label:string, teks:string}>}>
 */
function parseTeksSoal(string $text): array {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $blocks = [];
    $current = null;
    $currentOptionIdx = null;

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') continue;

        // Baris nomor soal baru: "1. ..." atau "1) ..."
        if (preg_match('/^(\d{1,3})[\.\)]\s+(.+)$/', $line, $m)) {
            if ($current !== null && !empty($current['pertanyaan'])) {
                $blocks[] = $current;
            }
            $current = ['pertanyaan' => trim($m[2]), 'opsi' => []];
            $currentOptionIdx = null;
            continue;
        }

        // Baris opsi: "A. ..." / "a) ..." — hanya diproses kalau sedang di dalam soal
        if ($current !== null && preg_match('/^([A-Ea-e])[\.\)]\s+(.+)$/', $line, $m)) {
            $current['opsi'][] = ['label' => strtoupper($m[1]), 'teks' => trim($m[2])];
            $currentOptionIdx = count($current['opsi']) - 1;
            continue;
        }

        // Baris lanjutan (wrap teks panjang)
        if ($current === null) continue; // teks sebelum soal pertama (judul dok, dsb), abaikan
        if ($currentOptionIdx === null) {
            $current['pertanyaan'] .= ' ' . $line;
        } else {
            $current['opsi'][$currentOptionIdx]['teks'] .= ' ' . $line;
        }
    }
    if ($current !== null && !empty($current['pertanyaan'])) {
        $blocks[] = $current;
    }

    // Normalisasi: maksimal 5 opsi per soal (A-E), label dipaksa urut A/B/C/D/E
    foreach ($blocks as &$b) {
        $b['opsi'] = array_slice($b['opsi'], 0, 5);
        $labels = ['A', 'B', 'C', 'D', 'E'];
        foreach ($b['opsi'] as $i => &$o) {
            $o['label'] = $labels[$i];
        }
        unset($o);
    }
    unset($b);

    return $blocks;
}

/** Bersihkan file draft import yang lebih tua dari $jamKedaluwarsa jam */
function bersihkanDraftLama(string $draftDir, int $jamKedaluwarsa = 6): void {
    if (!is_dir($draftDir)) return;
    $batas = time() - ($jamKedaluwarsa * 3600);
    foreach (glob($draftDir . '*.json') ?: [] as $f) {
        if (filemtime($f) < $batas) @unlink($f);
    }
}

// =====================================================================
// PARSER BARU: TABEL BERKOLOM BERBASIS KOORDINAT (untuk PDF psikotes /
// forced-choice / MCQ yang diekspor jadi tabel, mis. dari Excel).
// =====================================================================

/**
 * Redirect stderr ke "tong sampah" sesuai OS: /dev/null di Linux/Mac,
 * NUL di Windows.
 */
function redirectNull(): string {
    return (stripos(PHP_OS, 'WIN') === 0) ? '2>NUL' : '2>/dev/null';
}

/**
 * Cari path executable di sistem, cross-platform (Windows pakai `where`,
 * Linux/Mac pakai `which`). PHP_OS di Windows dimulai dengan "WIN".
 */
function cariPathExecutable(string $bin): string {
    if (!function_exists('shell_exec')) return '';
    $isWindows = stripos(PHP_OS, 'WIN') === 0;
    if ($isWindows) {
        $out = (string)@shell_exec('where ' . escapeshellarg($bin) . ' 2>NUL');
    } else {
        $out = (string)@shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null');
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($out));
    return trim($lines[0] ?? '');
}

/**
 * Ekstrak posisi (koordinat) tiap kata dari PDF pakai `pdftotext -bbox`
 * (bagian dari poppler-utils). Dipakai untuk membaca PDF yang isinya
 * berupa TABEL berkolom (nomor | opsi A | opsi B | ... ), yang kalau
 * dibaca sebagai teks linear biasa akan tercampur urutannya.
 *
 * @return array<int, array{page:int, x0:float, top:float, text:string}>|null
 *         null kalau pdftotext tidak tersedia atau gagal.
 */
function extractPdfWordsBbox(string $filePath): ?array {
    $pdftotext = cariPathExecutable('pdftotext');
    if ($pdftotext === '') return null;

    $xml = @shell_exec('pdftotext -bbox ' . escapeshellarg($filePath) . ' - ' . redirectNull());
    if (!$xml || trim($xml) === '') return null;

    $dom = new DOMDocument();
    $prevErr = libxml_use_internal_errors(true);
    $ok = $dom->loadHTML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prevErr);
    if (!$ok) return null;

    $words = [];
    $pages = $dom->getElementsByTagName('page');
    foreach ($pages as $pageIdx => $pageNode) {
        foreach ($pageNode->getElementsByTagName('word') as $wordNode) {
            $x0 = (float)($wordNode->getAttribute('xmin') ?: $wordNode->getAttribute('xMin'));
            $top = (float)($wordNode->getAttribute('ymin') ?: $wordNode->getAttribute('yMin'));
            $text = trim($wordNode->textContent);
            if ($text === '') continue;
            $words[] = ['page' => $pageIdx, 'x0' => $x0, 'top' => $top, 'text' => $text];
        }
    }
    return $words ?: null;
}

/**
 * Rapikan spasi hasil penggabungan kata per-kata dari bbox: hilangkan
 * spasi ganda dan spasi yang nyasar sebelum tanda baca penutup
 * seperti ")" "," "." sehingga "exciting )" jadi "exciting)".
 */
function rapikanSpasiTanda(string $s): string {
    $s = preg_replace('/\s+/', ' ', trim($s));
    $s = preg_replace('/\s+([.,\)\]:;!?])/', '$1', $s);
    $s = preg_replace('/([\(\[])\s+/', '$1', $s);
    return trim($s);
}

/**
 * Deteksi otomatis apakah PDF berbentuk TABEL berkolom (nomor + kolom
 * opsi A/B/.../E) dan kalau ya, susun ulang soal per baris berdasarkan
 * KOORDINAT kata (bukan urutan teks linear yang bisa tercampur akibat
 * teks panjang yang wrap ke baris baru pada kolom lain, atau akibat
 * nomor/huruf opsi yang diletakkan di tengah vertikal sel tabel).
 *
 * Jumlah kolom opsi mengikuti apa yang benar-benar ada di PDF:
 * kalau cuma ada kolom A & B, hasilnya 2 opsi per soal; kalau ada
 * A/B/C/D/E, hasilnya 5 opsi per soal.
 *
 * Kalau strukturnya tidak cocok (bukan tabel berkolom, atau kolom tidak
 * konsisten), fungsi ini SENGAJA mundur (return null) supaya caller
 * jatuh ke parser lama (parseTeksSoal) — tidak memaksakan hasil yang
 * meragukan.
 *
 * @return array<int, array{pertanyaan:string, opsi: array<int, array{label:string, teks:string}>}>|null
 */
function parseTabelSoalDariPdf(string $filePath): ?array {
    $words = extractPdfWordsBbox($filePath);
    if ($words === null) return null;

    // Helper: clustering 1D toleran (bukan grid kaku), karena angka
    // rata-kanan bisa punya x0 sedikit beda antara "1" dan "10" walau
    // sebenarnya satu kolom yang sama.
    $clusterByX = function (array $items, float $tolerance = 7.0): array {
        usort($items, fn($a, $b) => $a['x0'] <=> $b['x0']);
        $clusters = [];
        $current = [];
        $lastX = null;
        foreach ($items as $it) {
            if ($lastX !== null && $it['x0'] - $lastX > $tolerance) {
                $clusters[] = $current;
                $current = [];
            }
            $current[] = $it;
            $lastX = $it['x0'];
        }
        if ($current) $clusters[] = $current;
        return $clusters;
    };

    // 1) Cari kolom NOMOR: cluster x0 yang isinya kata angka murni,
    //    muncul berkali-kali (indikasi kolom nomor soal).
    $digitWords = array_values(array_filter($words, fn($w) => ctype_digit($w['text'])));
    if (empty($digitWords)) return null;

    $digitClusters = $clusterByX($digitWords);
    $numWords = []; $bestCount = 0;
    foreach ($digitClusters as $cluster) {
        if (count($cluster) > $bestCount) { $bestCount = count($cluster); $numWords = $cluster; }
    }
    // Minimal 5 baris supaya masuk akal disebut tabel soal
    if ($bestCount < 5) return null;

    usort($numWords, fn($a, $b) => $a['page'] <=> $b['page'] ?: $a['top'] <=> $b['top']);
    $numColX = min(array_column($numWords, 'x0'));

    // 2) Cari kolom-kolom MARKER huruf opsi (A/B/C/D/E), letaknya di kanan
    //    kolom nomor. Kelompokkan pakai clustering X, lalu filter cluster
    //    yang isinya mayoritas SATU huruf spesifik dan jumlah kemunculannya
    //    mendekati jumlah baris nomor (indikasi kolom asli, bukan huruf
    //    kebetulan di tengah kalimat).
    $letterWords = array_values(array_filter(
        $words,
        fn($w) => $w['x0'] > $numColX + 15 && in_array($w['text'], ['A', 'B', 'C', 'D', 'E'], true)
    ));
    $letterClusters = $clusterByX($letterWords);

    $markerCols = []; // ['x0'=>.., 'label'=>'A', 'rows'=>[...]]
    foreach ($letterClusters as $ws) {
        $countByLetter = [];
        foreach ($ws as $w) $countByLetter[$w['text']] = ($countByLetter[$w['text']] ?? 0) + 1;
        arsort($countByLetter);
        $majorLetter = array_key_first($countByLetter);
        $majorCount = $countByLetter[$majorLetter];
        // Minimal 60% dari jumlah baris nomor, dan mayoritas jelas 1 huruf
        if ($majorCount >= max(3, 0.6 * $bestCount) && $majorCount >= 0.8 * count($ws)) {
            $rows = array_values(array_filter($ws, fn($w) => $w['text'] === $majorLetter));
            usort($rows, fn($a, $b) => $a['page'] <=> $b['page'] ?: $a['top'] <=> $b['top']);
            $markerCols[] = ['x0' => min(array_column($rows, 'x0')), 'label' => $majorLetter, 'rows' => $rows];
        }
    }

    if (count($markerCols) < 2) return null; // minimal harus ada 2 kolom opsi (A & B)
    usort($markerCols, fn($a, $b) => $a['x0'] <=> $b['x0']); // urutkan kiri ke kanan

    // Jumlah baris tiap kolom marker harus konsisten dengan jumlah nomor soal
    foreach ($markerCols as $mc) {
        if (abs(count($mc['rows']) - $bestCount) > max(2, 0.15 * $bestCount)) {
            return null; // tidak konsisten -> bukan tabel yang kita kenali, mundur
        }
    }

    // 3) Helper: bikin batas atas/bawah tiap baris pakai TITIK TENGAH antar
    //    baris berurutan (bukan posisi persis), karena label nomor/huruf
    //    biasanya diletakkan di tengah vertikal sel yang tingginya bisa
    //    beda-beda tergantung panjang teks di kolom lain.
    $makeBounds = function (array $rows) {
        $n = count($rows);
        $bounds = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $rows[$i]['page']; $t = $rows[$i]['top'];
            if ($i === 0 || $rows[$i - 1]['page'] !== $p) {
                $lower = [$p, -1e9];
            } else {
                $lower = [$p, ($rows[$i - 1]['top'] + $t) / 2];
            }
            if ($i === $n - 1 || $rows[$i + 1]['page'] !== $p) {
                $upper = [$p, 1e9];
            } else {
                $upper = [$p, ($t + $rows[$i + 1]['top']) / 2];
            }
            $bounds[] = [$lower, $upper];
        }
        return $bounds;
    };

    // 4) Cari batas awal halaman: baris header tabel (kata "No") supaya
    //    teks instruksi/judul di atas tabel tidak ikut ketarik ke soal #1.
    $headerTopByPage = [];
    foreach ($words as $w) {
        if ($w['text'] === 'No' && $w['x0'] <= $numColX + 15) {
            $headerTopByPage[$w['page']] = $w['top'];
        }
    }

    $numBounds = $makeBounds($numWords);
    foreach ($numBounds as $i => &$b) {
        $p = $numWords[$i]['page'];
        if (isset($headerTopByPage[$p]) && $b[0][1] < $headerTopByPage[$p]) {
            $b[0] = [$p, $headerTopByPage[$p]];
        }
    }
    unset($b);

    $markerBoundsList = [];
    foreach ($markerCols as $mc) {
        $bounds = $makeBounds($mc['rows']);
        foreach ($bounds as $i => &$b) {
            $p = $mc['rows'][$i]['page'];
            if (isset($headerTopByPage[$p]) && $b[0][1] < $headerTopByPage[$p]) {
                $b[0] = [$p, $headerTopByPage[$p]];
            }
        }
        unset($b);
        $markerBoundsList[] = $bounds;
    }

    // 5) Kumpulkan kata dalam rentang X tiap kolom teks (antara satu marker
    //    dan marker/kolom berikutnya), lalu gabungkan sesuai batas baris.
    $collectWords = function (array $bounds, float $xLow, float $xHigh) use ($words) {
        $out = [];
        foreach ($bounds as $b) {
            [$lp, $lt] = $b[0]; [$up, $ut] = $b[1];
            foreach ($words as $w) {
                if ($w['x0'] < $xLow || $w['x0'] >= $xHigh) continue;
                $afterLower = ($w['page'] === $lp && $w['top'] >= $lt) || $w['page'] > $lp;
                $beforeUpper = ($w['page'] === $up && $w['top'] < $ut) || $w['page'] < $up;
                if ($afterLower && $beforeUpper) $out[] = $w;
            }
        }
        return $out;
    };

    // batas kanan area "pertanyaan" (kalau ada kolom pertanyaan terpisah
    // sebelum kolom opsi A) = posisi marker kolom A itu sendiri.
    $markerAX = $markerCols[0]['rows'][0]['x0'] - 5;

    $n = $bestCount;
    $results = [];
    for ($i = 0; $i < $n; $i++) {
        $opsi = [];
        foreach ($markerCols as $mi => $mc) {
            $xHigh = ($mi + 1 < count($markerCols)) ? ($markerCols[$mi + 1]['rows'][0]['x0'] - 20) : 1e9;
            $rowWords = $collectWords([$markerBoundsList[$mi][$i]], $mc['rows'][0]['x0'] + 5, $xHigh);
            usort($rowWords, fn($a, $b) => $a['page'] <=> $b['page'] ?: $a['top'] <=> $b['top'] ?: $a['x0'] <=> $b['x0']);
            $teks = rapikanSpasiTanda(implode(' ', array_column($rowWords, 'text')));
            if ($teks !== '') {
                $opsi[] = ['label' => $mc['label'], 'teks' => $teks];
            }
        }

        // teks pertanyaan (area antara nomor & marker kolom pertama, kalau ada)
        $qWords = $collectWords([$numBounds[$i]], $numColX + 15, $markerAX);
        usort($qWords, fn($a, $b) => $a['page'] <=> $b['page'] ?: $a['top'] <=> $b['top'] ?: $a['x0'] <=> $b['x0']);
        $pertanyaan = rapikanSpasiTanda(implode(' ', array_column($qWords, 'text')));
        if ($pertanyaan === '') {
            $pertanyaan = '(Pilih pernyataan yang paling sesuai dengan Anda)';
        }

        if (!empty($opsi)) {
            $results[] = ['pertanyaan' => $pertanyaan, 'opsi' => $opsi];
        }
    }

    return $results ?: null;
}