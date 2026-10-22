<?php
/**
 * cek_pdf_tabel.php — SCRIPT DIAGNOSA SEMENTARA
 * -------------------------------------------------------------
 * Tujuan: cari tahu persis kenapa parseTabelSoalDariPdf() gagal di
 * server ini. Upload file ini ke root project (sejajar folder admin/,
 * includes/, dll), lalu akses lewat browser:
 *
 *   https://domainkamu.com/cek_pdf_tabel.php
 *
 * Upload PDF psikotes-nya di form yang muncul, lalu SCREENSHOT atau
 * copy-paste semua teks hasilnya ke saya.
 *
 * HAPUS FILE INI setelah selesai diagnosa — jangan dibiarkan di server
 * production karena tidak ada proteksi login.
 * -------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre style='font-family:monospace; font-size:13px; padding:20px; background:#111; color:#0f0;'>";

echo "=== 1. INFO DASAR SERVER ===\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "shell_exec function_exists: " . (function_exists('shell_exec') ? 'YA' : 'TIDAK ADA') . "\n";
echo "DOMDocument class_exists: " . (class_exists('DOMDocument') ? 'YA' : 'TIDAK ADA') . "\n";

$disabled = ini_get('disable_functions');
echo "disable_functions di php.ini: " . ($disabled ?: '(kosong)') . "\n";
if ($disabled && stripos($disabled, 'shell_exec') !== false) {
    echo ">>> shell_exec DI-DISABLE lewat php.ini. Ini kemungkinan besar penyebabnya.\n";
}

echo "\n=== 2. CEK PDFTOTEXT ===\n";
$isWindows = stripos(PHP_OS, 'WIN') === 0;
echo "OS terdeteksi: " . PHP_OS . " (" . ($isWindows ? 'Windows' : 'Linux/Unix') . ")\n";
if (function_exists('shell_exec')) {
    $cmd = $isWindows ? 'where pdftotext 2>&1' : 'which pdftotext 2>&1';
    $which = trim((string)@shell_exec($cmd));
    echo "$cmd => " . ($which !== '' ? $which : '(TIDAK KETEMU)') . "\n";

    if ($which === '') {
        echo ">>> pdftotext TIDAK TERPASANG di sistem ini.\n";
        if ($isWindows) {
            echo ">>> Ini kelihatannya Windows (lokal/XAMPP). Untuk pasang poppler di Windows:\n";
            echo "    1. Download dari https://github.com/oschwartz10612/poppler-windows/releases\n";
            echo "    2. Extract, lalu tambahkan folder 'Library\\bin' ke System PATH\n";
            echo "    3. Restart Apache/XAMPP, lalu cek ulang lewat CMD: pdftotext -v\n";
            echo ">>> CATATAN: kalau server PRODUCTION kamu nanti Linux, di sana perintahnya\n";
            echo "    cukup: sudo apt install poppler-utils (biasanya sudah cukup tanpa langkah manual ini).\n";
        } else {
            echo ">>> Di Linux: sudo apt install poppler-utils\n";
        }
    } else {
        $verCmd = 'pdftotext -v ' . ($isWindows ? '2>&1' : '2>&1');
        $ver = trim((string)@shell_exec($verCmd));
        echo "pdftotext -v output:\n$ver\n";
    }
} else {
    echo "(dilewati karena shell_exec tidak ada)\n";
}

echo "\n=== 3. UPLOAD PDF UNTUK DITES ===\n";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['pdf']['tmp_name'])) {
    $tmpPath = sys_get_temp_dir() . '/cek_' . uniqid() . '.pdf';
    move_uploaded_file($_FILES['pdf']['tmp_name'], $tmpPath);
    echo "File diterima, disimpan sementara di: $tmpPath\n";
    echo "Ukuran file: " . filesize($tmpPath) . " bytes\n\n";

    echo "--- Menjalankan: pdftotext -bbox <file> - ---\n";
    $nullRedir = $isWindows ? '2>NUL' : '2>/dev/null';
    $xml = @shell_exec('pdftotext -bbox ' . escapeshellarg($tmpPath) . ' - ' . $nullRedir);
    if (!$xml || trim($xml) === '') {
        echo ">>> HASIL KOSONG / GAGAL. Ini penyebab utamanya kalau shell_exec jalan tapi output kosong.\n";
        echo "Kemungkinan: pdftotext tidak ada, atau exec()/shell_exec() dibatasi open_basedir, atau permission.\n";
    } else {
        echo "Panjang output XML: " . strlen($xml) . " karakter\n";
        echo "Cuplikan 500 karakter pertama:\n" . htmlspecialchars(substr($xml, 0, 500)) . "\n\n";

        $dom = new DOMDocument();
        $prevErr = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML($xml);
        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prevErr);

        echo "DOMDocument->loadHTML() berhasil: " . ($ok ? 'YA' : 'TIDAK') . "\n";
        if (!empty($libxmlErrors)) {
            echo "Ada " . count($libxmlErrors) . " warning/error libxml (biasanya tidak fatal untuk HTML longgar).\n";
        }

        $words = [];
        foreach ($dom->getElementsByTagName('page') as $pageIdx => $pageNode) {
            foreach ($pageNode->getElementsByTagName('word') as $wordNode) {
                $x0 = (float)($wordNode->getAttribute('xmin') ?: $wordNode->getAttribute('xMin'));
                $top = (float)($wordNode->getAttribute('ymin') ?: $wordNode->getAttribute('yMin'));
                $text = trim($wordNode->textContent);
                if ($text === '') continue;
                $words[] = ['page' => $pageIdx, 'x0' => $x0, 'top' => $top, 'text' => $text];
            }
        }
        echo "Total kata (words) berhasil di-parse dari XML: " . count($words) . "\n\n";

        if (count($words) > 0) {
            echo "Contoh 10 kata pertama (page, x0, top, text):\n";
            foreach (array_slice($words, 0, 10) as $w) {
                echo "  [{$w['page']}] x0={$w['x0']} top={$w['top']}  \"{$w['text']}\"\n";
            }

            // Deteksi kolom nomor
            $digitWords = array_values(array_filter($words, fn($w) => ctype_digit($w['text'])));
            echo "\nJumlah kata berupa angka murni: " . count($digitWords) . "\n";

            if (count($digitWords) > 0) {
                usort($digitWords, fn($a, $b) => $a['x0'] <=> $b['x0']);
                $clusters = [];
                $current = [];
                $lastX = null;
                foreach ($digitWords as $it) {
                    if ($lastX !== null && $it['x0'] - $lastX > 7.0) {
                        $clusters[] = $current;
                        $current = [];
                    }
                    $current[] = $it;
                    $lastX = $it['x0'];
                }
                if ($current) $clusters[] = $current;

                echo "Jumlah cluster kolom angka terdeteksi: " . count($clusters) . "\n";
                foreach ($clusters as $i => $c) {
                    $xs = array_column($c, 'x0');
                    echo "  Cluster #$i: x0 antara " . min($xs) . " - " . max($xs) . ", jumlah kata: " . count($c) . "\n";
                }
            }

            // Deteksi kata huruf A/B/C/D
            $letterWords = array_values(array_filter($words, fn($w) => in_array($w['text'], ['A','B','C','D'], true)));
            echo "\nJumlah kata berupa huruf A/B/C/D saja: " . count($letterWords) . "\n";
            if (count($letterWords) > 0) {
                usort($letterWords, fn($a, $b) => $a['x0'] <=> $b['x0']);
                $clusters = [];
                $current = [];
                $lastX = null;
                foreach ($letterWords as $it) {
                    if ($lastX !== null && $it['x0'] - $lastX > 7.0) {
                        $clusters[] = $current;
                        $current = [];
                    }
                    $current[] = $it;
                    $lastX = $it['x0'];
                }
                if ($current) $clusters[] = $current;
                echo "Jumlah cluster kolom huruf terdeteksi: " . count($clusters) . "\n";
                foreach ($clusters as $i => $c) {
                    $xs = array_column($c, 'x0');
                    $counts = [];
                    foreach ($c as $w) $counts[$w['text']] = ($counts[$w['text']] ?? 0) + 1;
                    echo "  Cluster #$i: x0 antara " . min($xs) . " - " . max($xs) . ", jumlah kata: " . count($c) . ", isi: " . json_encode($counts) . "\n";
                }
            }
        }
    }

    @unlink($tmpPath);
} else {
    echo "(belum ada file diupload, lihat form di bawah)\n";
}

echo "</pre>";
?>

<form method="POST" enctype="multipart/form-data" style="padding:20px; font-family:sans-serif;">
    <p>Upload PDF psikotes untuk didiagnosa:</p>
    <input type="file" name="pdf" accept="application/pdf" required>
    <button type="submit">Jalankan Diagnosa</button>
</form>