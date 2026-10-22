<?php
/**
 * Helper import soal dari Excel (.xlsx) / CSV — pasangan dari pdf_import.php.
 *
 * - .xlsx dibaca langsung lewat ZipArchive + DOM, TANPA Composer.
 *   (butuh ekstensi PHP "zip" dan "dom", biasanya sudah aktif di XAMPP / hosting umum)
 * - .csv dibaca dengan deteksi otomatis pemisah (, ; atau tab) & encoding.
 * - .xls (format lama) hanya jalan kalau PhpSpreadsheet terpasang di /vendor.
 *
 * Hasil parse memakai struktur blok yang SAMA dengan parser PDF, sehingga
 * bisa langsung dilempar ke import_review.php:
 *   ['pertanyaan' => string,
 *    'opsi'       => [['label' => 'A', 'teks' => '...', 'dimensi' => 'D', 'nilai' => 0], ...],
 *    'kunci'      => int|null]   // index opsi yang benar (khusus tes logika)
 */

if (!defined('EXCEL_MAX_BYTES')) define('EXCEL_MAX_BYTES', 5 * 1024 * 1024);

// Batas soal per sekali import. Halaman review mengirim banyak field sekaligus, sedangkan
// PHP membatasi jumlah field POST lewat max_input_vars (default 1000, sisanya DIPOTONG DIAM-DIAM).
// Naikkan angka ini hanya kalau max_input_vars di php.ini sudah dinaikkan juga.
if (!defined('EXCEL_MAX_SOAL')) define('EXCEL_MAX_SOAL', 50);

// ============================================================
//  Kerangka dimensi psikotes (sama dengan yang dipakai soal.php)
// ============================================================
function excelDimensiPsikotes(?string $tipe): array {
    switch ($tipe) {
        case 'mbti':       return ['E', 'I', 'S', 'N', 'T', 'F', 'J', 'P'];
        case 'disc':       return ['D', 'I', 'S', 'C'];
        case 'papikostik': return ['A', 'N', 'G', 'C', 'D', 'R', 'T', 'V', 'W', 'F',
                                   'L', 'P', 'I', 'S', 'B', 'O', 'X', 'E', 'K', 'Z'];
        default:           return [];
    }
}

// ============================================================
//  PEMBACA FILE  →  array baris (tiap baris = array sel string)
// ============================================================

/**
 * @return array ['rows' => array<int, array<int,string>>, 'method' => string]
 * @throws RuntimeException dengan pesan yang siap ditampilkan ke admin
 */
function excelBacaFile(string $path, string $ext): array {
    $ext = strtolower($ext);

    if ($ext === 'csv') {
        return ['rows' => excelBacaCsv($path), 'method' => 'csv'];
    }

    if ($ext === 'xlsx' || $ext === 'xlsm') {
        if (class_exists('ZipArchive')) {
            return ['rows' => excelBacaXlsx($path), 'method' => 'xlsx'];
        }
        $rows = excelBacaViaPhpSpreadsheet($path);
        if ($rows !== null) return ['rows' => $rows, 'method' => 'xlsx via phpspreadsheet'];
        throw new RuntimeException('Ekstensi PHP "zip" belum aktif di server ini, jadi file .xlsx tidak bisa dibaca. '
            . 'Aktifkan extension=zip di php.ini, atau simpan file sebagai CSV lalu upload ulang.');
    }

    if ($ext === 'xls') {
        $rows = excelBacaViaPhpSpreadsheet($path);
        if ($rows !== null) return ['rows' => $rows, 'method' => 'xls via phpspreadsheet'];
        throw new RuntimeException('Format .xls (Excel lama) tidak didukung. Buka file di Excel lalu '
            . '"Save As" ke .xlsx (atau CSV), kemudian upload ulang.');
    }

    throw new RuntimeException('Format file harus .xlsx atau .csv.');
}

function excelBacaXlsx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('File Excel tidak bisa dibuka — kemungkinan rusak atau bukan .xlsx asli.');
    }

    // 1) Shared strings (teks sel yang disimpan terpusat)
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $dom = new DOMDocument();
        if (@$dom->loadXML($ssXml, LIBXML_NONET)) {
            foreach ($dom->getElementsByTagNameNS('*', 'si') as $si) {
                $shared[] = excelTeksNode($si);
            }
        }
    }

    // 2) Lokasi sheet pertama
    $sheetPath = excelCariSheetPertama($zip);
    $sheetXml = $sheetPath ? $zip->getFromName($sheetPath) : false;
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Sheet pertama di file Excel tidak ditemukan.');
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($sheetXml, LIBXML_NONET)) {
        throw new RuntimeException('Isi sheet Excel tidak bisa dibaca.');
    }

    // 3) Baca baris & sel
    $rows = [];
    foreach ($dom->getElementsByTagNameNS('*', 'row') as $rowNode) {
        $row = [];
        $next = 0;
        foreach ($rowNode->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE || $c->localName !== 'c') continue;

            $ci = excelKolomKeIndex($c->getAttribute('r'));
            if ($ci < 0) $ci = $next;
            $next = $ci + 1;

            $tipe = $c->getAttribute('t');
            if ($tipe === 'inlineStr') {
                $val = excelTeksNode($c);
            } else {
                $vNodes = $c->getElementsByTagNameNS('*', 'v');
                $v = $vNodes->length ? $vNodes->item(0)->textContent : '';
                if ($tipe === 's')      $val = $shared[(int)$v] ?? '';
                elseif ($tipe === 'b')  $val = ($v === '1') ? 'TRUE' : 'FALSE';
                else                    $val = $v; // angka / string hasil formula
            }
            $row[$ci] = trim((string)$val);
        }
        $rows[] = excelRapatkanBaris($row);
    }

    return excelBuangBarisKosong($rows);
}

function excelCariSheetPertama(ZipArchive $zip): ?string {
    $wb   = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($wb !== false && $rels !== false) {
        $dWb = new DOMDocument();
        $dRel = new DOMDocument();
        if (@$dWb->loadXML($wb, LIBXML_NONET) && @$dRel->loadXML($rels, LIBXML_NONET)) {
            $sheets = $dWb->getElementsByTagNameNS('*', 'sheet');
            if ($sheets->length > 0) {
                $rid = $sheets->item(0)->getAttributeNS(
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id'
                );
                foreach ($dRel->getElementsByTagNameNS('*', 'Relationship') as $rel) {
                    if ($rel->getAttribute('Id') === $rid) {
                        $target = ltrim($rel->getAttribute('Target'), '/');
                        return (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
                    }
                }
            }
        }
    }

    // Fallback umum
    return ($zip->locateName('xl/worksheets/sheet1.xml') !== false) ? 'xl/worksheets/sheet1.xml' : null;
}

/** Gabungkan semua teks (<t>) di dalam sebuah node, abaikan anotasi fonetik (rPh). */
function excelTeksNode(DOMNode $node): string {
    $out = '';
    foreach ($node->getElementsByTagNameNS('*', 't') as $t) {
        if ($t->parentNode && $t->parentNode->localName === 'rPh') continue;
        $out .= $t->textContent;
    }
    return $out;
}

/** "B3" → 1, "AA10" → 26 */
function excelKolomKeIndex(string $ref): int {
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) return -1;
    $n = 0;
    foreach (str_split(strtoupper($m[1])) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** 0 → "A", 26 → "AA" */
function excelIndexKeKolom(int $i): string {
    $s = '';
    $i++;
    while ($i > 0) {
        $mod = ($i - 1) % 26;
        $s = chr(65 + $mod) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/** Sel yang bolong (kolom kosong di tengah) diisi '' supaya index kolom konsisten. */
function excelRapatkanBaris(array $row): array {
    if (empty($row)) return [];
    $max = max(array_keys($row));
    $dense = array_fill(0, $max + 1, '');
    foreach ($row as $i => $v) $dense[$i] = $v;
    return $dense;
}

function excelBuangBarisKosong(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        if (trim(implode('', $r)) !== '') $out[] = $r;
    }
    return $out;
}

function excelBacaCsv(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('File CSV kosong atau tidak bisa dibaca.');
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3); // buang BOM UTF-8
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); // CSV lawas dari Excel Windows
    }

    // Excel versi Indonesia menyimpan CSV dengan pemisah titik-koma
    $barisPertama = (string)strtok($raw, "\r\n");
    $delim = ',';
    $terbanyak = 0;
    foreach ([',', ';', "\t"] as $d) {
        $jml = substr_count($barisPertama, $d);
        if ($jml > $terbanyak) { $terbanyak = $jml; $delim = $d; }
    }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $rows[] = array_map(fn($v) => trim((string)$v), $r);
    }
    fclose($fh);

    return excelBuangBarisKosong($rows);
}

/** Opsional: dipakai kalau proyek punya PhpSpreadsheet (composer). Return null kalau tidak ada. */
function excelBacaViaPhpSpreadsheet(string $path): ?array {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) return null;
    require_once $autoload;
    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) return null;

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $data = $spreadsheet->getSheet(0)->toArray(null, true, true, false);
    } catch (\Throwable $e) {
        throw new RuntimeException('File Excel tidak bisa dibaca: ' . $e->getMessage());
    }

    $rows = [];
    foreach ($data as $r) {
        $rows[] = array_map(fn($v) => trim((string)$v), $r);
    }
    return excelBuangBarisKosong($rows);
}

// ============================================================
//  PARSER  baris tabel  →  blok soal
// ============================================================

function excelRapikan(string $s): string {
    $s = str_replace("\xC2\xA0", ' ', $s); // nbsp — sering muncul di teks hasil copy dari Word/Excel
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * Petakan judul kolom → index kolom. Nama kolom fleksibel, contoh yang dikenali:
 *   No / Nomor | Pertanyaan / Soal / Pernyataan | A..E / "Opsi A" / "Pilihan B"
 *   Kunci / "Kunci Jawaban" / "Jawaban Benar" | "Dimensi A".."Dimensi E" | "Nilai A".."Nilai E"
 */
function excelPetakanHeader(array $row): array {
    // 'dikenal' / 'asing' = jumlah sel judul yang dikenali / tidak dikenali.
    // Dipakai supaya baris data biasa (mis. sel "a" dan "b" di dalam tabel soal) tidak salah dikira header.
    $map = ['no' => null, 'pertanyaan' => null, 'kunci' => null, 'opsi' => [], 'dimensi' => [], 'nilai' => [],
            'dikenal' => 0, 'asing' => 0];

    foreach ($row as $ci => $cell) {
        $k = preg_replace('/[^a-z0-9]/', '', strtolower((string)$cell));
        if ($k === '') continue;

        if (in_array($k, ['no', 'nomor', 'nomer', 'urut'], true)) {
            $map['no'] = $map['no'] ?? $ci;
        } elseif (in_array($k, ['pertanyaan', 'soal', 'pernyataan', 'teksoal'], true)) {
            $map['pertanyaan'] = $map['pertanyaan'] ?? $ci;
        } elseif (in_array($k, ['kunci', 'kuncijawaban', 'jawabanbenar', 'jawaban', 'key'], true)) {
            $map['kunci'] = $map['kunci'] ?? $ci;
        } elseif (preg_match('/^(?:opsi|pilihan|option)?([a-e])$/', $k, $m)) {
            $map['opsi'][ord(strtoupper($m[1])) - 65] = $ci;
        } elseif (preg_match('/^(?:dimensi|dim|kriteria)([a-e])$/', $k, $m)) {
            $map['dimensi'][ord(strtoupper($m[1])) - 65] = $ci;
        } elseif (preg_match('/^(?:nilai|skor|bobot)([a-e])$/', $k, $m)) {
            $map['nilai'][ord(strtoupper($m[1])) - 65] = $ci;
        } else {
            $map['asing']++;
            continue;
        }
        $map['dikenal']++;
    }
    return $map;
}

/**
 * @param array  $rows          hasil excelBacaFile()['rows']
 * @param string $jenis         'logika' | 'psikotes'
 * @param array  $dimensiValid  daftar dimensi sah (kosong = tidak divalidasi)
 * @return array ['blocks' => array, 'warnings' => string[]]
 * @throws RuntimeException
 */
function excelParseSoal(array $rows, string $jenis, array $dimensiValid = []): array {
    $rows = array_values($rows);

    // --- cari baris header (minimal ada kolom A dan B) di 15 baris pertama ---
    $map = null;
    $headerIdx = -1;
    $batas = min(15, count($rows));
    for ($r = 0; $r < $batas; $r++) {
        $m = excelPetakanHeader($rows[$r]);
        if (isset($m['opsi'][0]) && isset($m['opsi'][1]) && $m['asing'] <= $m['dikenal']) {
            $map = $m;
            $headerIdx = $r;
            break;
        }
    }
    if ($map === null) {
        // Bukan format tabel → coba format naskah (nomor & opsi a./b./c. ditulis berurutan ke bawah)
        $naskah = excelParseNaskah($rows);
        if ($naskah !== null) return $naskah;

        throw new RuntimeException('Format isi Excel tidak dikenali. Sistem mendukung dua format: '
            . '(1) TABEL — baris pertama berisi judul kolom, minimal "A" dan "B" (opsional: No, Pertanyaan, C, D, E, Kunci); '
            . '(2) NASKAH — nomor soal "1." lalu pertanyaan, disusul baris opsi "a. ...", "b. ..." dst berurutan ke bawah. '
            . 'Gunakan tombol "Unduh Template" untuk format tabel.');
    }

    $isPsikotes = ($jenis === 'psikotes');
    $blocks = [];
    $warnings = [];

    for ($r = $headerIdx + 1; $r < count($rows); $r++) {
        $row = $rows[$r];
        $cell = function (?int $ci) use ($row): string {
            return $ci === null ? '' : trim((string)($row[$ci] ?? ''));
        };

        // Teks opsi A-E (posisi kolom menentukan label, jadi kolom kosong di tengah tetap dihormati)
        $teksOpsi = [];
        $terakhir = -1;
        for ($i = 0; $i < 5; $i++) {
            $teksOpsi[$i] = isset($map['opsi'][$i]) ? excelRapikan($cell($map['opsi'][$i])) : '';
            if ($teksOpsi[$i] !== '') $terakhir = $i;
        }
        if ($terakhir < 0) continue; // baris tanpa opsi sama sekali → lewati

        $noSoal = count($blocks) + 1;

        $pertanyaan = excelRapikan($cell($map['pertanyaan']));
        if ($pertanyaan === '') {
            // Forced-choice (mis. Papikostik) memang sering tidak punya kalimat pertanyaan.
            $pertanyaan = $isPsikotes
                ? 'Pilih pernyataan yang paling menggambarkan diri Anda.'
                : 'Pilih jawaban yang paling tepat.';
            if (!$isPsikotes) $warnings[] = "Soal #{$noSoal}: kolom Pertanyaan kosong, diisi teks bawaan — sesuaikan di bawah.";
        }

        // Susun opsi
        $opsi = [];
        for ($i = 0; $i <= $terakhir; $i++) {
            $o = ['label' => chr(65 + $i), 'teks' => mb_substr($teksOpsi[$i], 0, 255)];

            if ($isPsikotes) {
                if (isset($map['dimensi'][$i])) {
                    $d = strtoupper($cell($map['dimensi'][$i]));
                    if ($d !== '' && !empty($dimensiValid) && !in_array($d, $dimensiValid, true)) {
                        $warnings[] = "Soal #{$noSoal} opsi {$o['label']}: dimensi \"{$d}\" tidak dikenal untuk tes ini, dikosongkan.";
                        $d = '';
                    }
                    $o['dimensi'] = $d;
                }
                if (isset($map['nilai'][$i])) {
                    $o['nilai'] = (int)$cell($map['nilai'][$i]);
                }
            }
            $opsi[] = $o;
        }

        // Kunci jawaban (tes logika)
        $kunci = null;
        if (!$isPsikotes && $map['kunci'] !== null) {
            $raw = strtoupper($cell($map['kunci']));
            $bersih = preg_replace('/[\s\.\)\(]/', '', $raw);
            if ($bersih !== '') {
                $idx = null;
                if (ctype_digit($bersih) && (int)$bersih >= 1 && (int)$bersih <= 5) {
                    $idx = (int)$bersih - 1;
                } elseif (preg_match('/^[A-E](?:[,;\/&][A-E])*$/', $bersih)) {
                    $idx = ord($bersih[0]) - 65;
                    if (strlen($bersih) > 1) {
                        $warnings[] = "Soal #{$noSoal}: kunci lebih dari satu (\"{$raw}\"), hanya yang pertama dipakai. Soal multi-jawaban atur lewat Kelola Soal.";
                    }
                }

                if ($idx === null) {
                    $warnings[] = "Soal #{$noSoal}: isi kolom Kunci \"{$raw}\" tidak dikenali (pakai huruf A-E), pilih manual.";
                } elseif ($idx > $terakhir || $teksOpsi[$idx] === '') {
                    $warnings[] = "Soal #{$noSoal}: kunci menunjuk opsi yang kosong, pilih manual.";
                } else {
                    $kunci = $idx;
                }
            }
        }

        $blocks[] = ['pertanyaan' => $pertanyaan, 'opsi' => $opsi, 'kunci' => $kunci];
    }

    if (empty($blocks)) {
        throw new RuntimeException('Judul kolom terbaca, tetapi tidak ada baris soal di bawahnya.');
    }
    if (count($blocks) > EXCEL_MAX_SOAL) {
        throw new RuntimeException('File berisi ' . count($blocks) . ' soal, batas per sekali import adalah ' . EXCEL_MAX_SOAL
            . ' (supaya halaman review tidak melewati batas max_input_vars PHP dan datanya terpotong diam-diam). '
            . 'Pecah file jadi beberapa bagian, atau naikkan max_input_vars di php.ini lalu ubah EXCEL_MAX_SOAL.');
    }

    return ['blocks' => $blocks, 'warnings' => $warnings, 'mode' => 'tabel'];
}

/**
 * Format NASKAH: isi Excel berupa dokumen soal biasa, satu sel per baris, mis.
 *
 *   A.  DERET BILANGAN                      ← judul bagian (diabaikan)
 *   1.  | 11 22 8 9 20 6 ... 4              ← nomor (kolom A) + pertanyaan (kolom B, atau satu sel)
 *       | a.  7 dan 8                       ← opsi berurutan a, b, c, d, e
 *       | b.  8 dan 16
 *   ...
 *       | Tentukan a dan b pada nomor 7 — 8!  ← teks/tabel "stimulus" di antara soal; kalau menyebut
 *       | 7 | 11 | 8                            "nomor X–Y" ditempelkan ke soal X sampai Y, kalau tidak
 *                                                ke soal berikutnya saja
 *
 * @return array|null null kalau tidak ada satu pun soal yang terdeteksi
 * @throws RuntimeException kalau jumlah soal melebihi EXCEL_MAX_SOAL
 */
function excelParseNaskah(array $rows): ?array {
    $blocks = [];
    $warnings = [];
    $cur = null;            // soal yang sedang dibaca
    $grpAktif = null;       // baris "stimulus" yang sedang dikumpulkan (di antara soal)
    $grpRentang = [];       // stimulus yang berlaku untuk rentang nomor tertentu
    $grpNext = null;        // stimulus yang berlaku untuk soal berikutnya saja
    $sudahAdaSoal = false;

    $tutupSoal = function () use (&$cur, &$blocks, &$warnings) {
        if ($cur === null) return;
        $no = count($blocks) + 1;
        if (empty($cur['opsi'])) {
            $warnings[] = "Nomor {$cur['no']} di Excel tidak punya opsi jawaban (a., b., ...), jadi dilewati.";
        } else {
            $teks = trim(implode("\n", $cur['lines']));
            if ($teks === '') {
                $teks = 'Pilih jawaban yang paling tepat.';
                $warnings[] = "Soal #{$no} (nomor {$cur['no']} di Excel): teks pertanyaan kosong, diisi teks bawaan.";
            }
            if (count($cur['opsi']) < 2) {
                $warnings[] = "Soal #{$no} (nomor {$cur['no']} di Excel): opsi kurang dari 2, cek manual.";
            }
            if (preg_match('/\bgambar\b/iu', $teks)) {
                $warnings[] = "Soal #{$no} (nomor {$cur['no']} di Excel) menyebut gambar — gambar tidak ikut ter-import, upload manual di bawah.";
            }
            foreach ($cur['opsi'] as &$o) $o['teks'] = mb_substr($o['teks'], 0, 255);
            unset($o);
            $blocks[] = ['pertanyaan' => $teks, 'opsi' => $cur['opsi'], 'kunci' => null];
        }
        $cur = null;
    };

    $tutupGrup = function () use (&$grpAktif, &$grpRentang, &$grpNext) {
        if ($grpAktif === null) return;
        $teks = implode(' ', $grpAktif);
        if (preg_match('/nomor\s+(\d{1,3})\s*(?:[-–—]|s\.?d\.?|sampai|dan)\s*(\d{1,3})/iu', $teks, $m)) {
            $grpRentang[] = ['from' => (int)$m[1], 'to' => (int)$m[2], 'lines' => $grpAktif];
        } else {
            $grpNext = $grpAktif;
        }
        $grpAktif = null;
    };

    // Judul bagian, mis. "A. DERET/POLA BILANGAN": label huruf kapital + sisanya kapital semua.
    // Bukan judul kalau huruf itu justru opsi berikutnya yang wajar dari soal yang sedang dibaca.
    $adalahJudul = function (string $t) use (&$cur): bool {
        if (!preg_match('/^(\p{Lu})\s*[\.\)]\s*(.+)$/u', $t, $m)) return false;
        if (!preg_match('/\p{Lu}{3,}/u', $m[2]) || preg_match('/\p{Ll}/u', $m[2])) return false;
        if ($cur !== null && (ord($m[1]) - 65) === $cur['last'] + 1) return false;
        return true;
    };

    foreach (array_values($rows) as $row) {
        $cells = [];
        foreach ($row as $c) {
            $c = excelRapikan((string)$c);
            if ($c !== '') $cells[] = $c;
        }
        if (empty($cells)) continue;
        $first = $cells[0];
        $others = array_slice($cells, 1);

        // (1) Awal soal: "12." / "12)" di sel pertama, teks pertanyaan di sel yang sama atau sel sebelahnya.
        //     "12.5" (angka desimal) sengaja TIDAK dianggap nomor karena setelah titik harus ada spasi/akhir sel.
        if (preg_match('/^(\d{1,3})\s*[\.\)](?:\s+(.*))?$/u', $first, $m)) {
            $tutupSoal();
            $tutupGrup();
            $no = (int)$m[1];
            $stem = trim(($m[2] ?? '') . ' ' . implode(' ', $others));

            $lines = [];
            foreach ($grpRentang as $k => $g) {
                if ($no > $g['to']) { unset($grpRentang[$k]); continue; }
                if ($no >= $g['from']) $lines = array_merge($lines, $g['lines']);
            }
            if ($grpNext !== null) { $lines = array_merge($lines, $grpNext); $grpNext = null; }
            if ($stem !== '') $lines[] = $stem;

            $cur = ['no' => $no, 'lines' => $lines, 'opsi' => [], 'last' => -1];
            $sudahAdaSoal = true;
            continue;
        }

        // (2) Judul bagian → diabaikan, sekaligus menutup soal sebelumnya
        if ($adalahJudul($first)) {
            $tutupSoal();
            $tutupGrup();
            continue;
        }

        // (3) Opsi jawaban: harus berurutan a, b, c, ... supaya "a." di tengah kalimat tidak salah dikira opsi
        if ($cur !== null && preg_match('/^([A-Ea-e])\s*[\.\)]\s*(.*)$/u', $first, $m)
            && (ord(strtoupper($m[1])) - 65) === $cur['last'] + 1) {
            $idx = ord(strtoupper($m[1])) - 65;
            $cur['opsi'][] = ['label' => chr(65 + $idx), 'teks' => trim($m[2] . ' ' . implode(' ', $others))];
            $cur['last'] = $idx;
            continue;
        }

        // (4) Baris lain. Baris berisi banyak sel (mis. tabel angka) digabung dengan " | ".
        $line = count($cells) > 1 ? implode(' | ', $cells) : $first;

        if ($cur !== null && $cur['last'] === -1) {
            $cur['lines'][] = $line;        // lanjutan pertanyaan (sebelum opsi pertama)
            continue;
        }
        if ($cur !== null) $tutupSoal();    // opsi sudah selesai, baris ini bukan bagian soal tadi

        if ($sudahAdaSoal) {                // stimulus untuk soal berikutnya (judul dokumen sebelum soal 1 dibuang)
            if ($grpAktif === null) $grpAktif = [];
            $grpAktif[] = $line;
        }
    }
    $tutupSoal();

    if (empty($blocks)) return null;
    if (count($blocks) > EXCEL_MAX_SOAL) {
        throw new RuntimeException('File berisi ' . count($blocks) . ' soal, batas per sekali import adalah ' . EXCEL_MAX_SOAL
            . ' (supaya halaman review tidak melewati batas max_input_vars PHP dan datanya terpotong diam-diam). '
            . 'Pecah file jadi beberapa bagian, atau naikkan max_input_vars di php.ini lalu ubah EXCEL_MAX_SOAL.');
    }

    return ['blocks' => $blocks, 'warnings' => $warnings, 'mode' => 'naskah'];
}

// ============================================================
//  TEMPLATE  (.xlsx dibuat tanpa library)
// ============================================================

/** Isi template sesuai jenis tes. */
function excelDataTemplate(string $jenis, ?string $tipePsikotes): array {
    if ($jenis === 'psikotes') {
        $dim = array_slice(array_pad(excelDimensiPsikotes($tipePsikotes), 4, ''), 0, 4);
        return [
            ['No', 'Pertanyaan', 'A', 'B', 'C', 'D', 'E', 'Dimensi A', 'Dimensi B', 'Dimensi C', 'Dimensi D', 'Dimensi E'],
            [1, 'Saat bekerja dalam tim, saya cenderung...', 'Memimpin dan mengambil keputusan', 'Menghidupkan suasana', 'Menjaga keharmonisan', 'Memeriksa detail', '', $dim[0], $dim[1], $dim[2], $dim[3], ''],
            [2, '', 'Saya seorang pekerja keras.', 'Saya bukan seorang pemurung.', '', '', '', $dim[0], $dim[1], '', '', ''],
        ];
    }
    return [
        ['No', 'Pertanyaan', 'A', 'B', 'C', 'D', 'E', 'Kunci'],
        [1, 'Berapa hasil dari 12 + 8?', '18', '20', '22', '24', '', 'B'],
        [2, 'Ibu kota Indonesia adalah...', 'Bandung', 'Jakarta', 'Surabaya', 'Medan', '', 'B'],
    ];
}

/**
 * Bangun file .xlsx minimal dari array baris; return isi file (binary string).
 * Angka (int/float) ditulis sebagai angka, lainnya sebagai teks.
 */
function excelBuatXlsx(array $rows, array $lebarKolom = []): string {
    $sheetRows = '';
    foreach (array_values($rows) as $ri => $row) {
        $cells = '';
        foreach (array_values($row) as $ci => $val) {
            if ($val === '' || $val === null) continue;
            $ref = excelIndexKeKolom($ci) . ($ri + 1);
            if (is_int($val) || is_float($val)) {
                $cells .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } else {
                $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        $sheetRows .= '<row r="' . ($ri + 1) . '">' . $cells . '</row>';
    }

    $cols = '';
    if ($lebarKolom) {
        $cols = '<cols>';
        foreach ($lebarKolom as $ci => $w) {
            $n = $ci + 1;
            $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
    }

    $xmlHead = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $files = [
        '[Content_Types].xml' => $xmlHead . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>',
        '_rels/.rels' => $xmlHead . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => $xmlHead . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Soal" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $xmlHead . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>',
        'xl/worksheets/sheet1.xml' => $xmlHead . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $cols . '<sheetData>' . $sheetRows . '</sheetData></worksheet>',
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    @unlink($tmp); // ZipArchive::CREATE butuh path yang belum ada
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Gagal membuat file template.');
    }
    foreach ($files as $nama => $isi) $zip->addFromString($nama, $isi);
    $zip->close();

    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}