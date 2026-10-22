<?php
/**
 * Simpan sebagai: includes/mailer.php
 *
 * Kirim email pakai PHPMailer + kredensial SMTP dari config/mail.php
 * (sudah ada di proyek ini, folder includes/PHPMailer/).
 */

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!defined('SITE_NAME'))      define('SITE_NAME', 'Psikotes Online');
// URL halaman login peserta - sesuaikan dengan lokasi login.php di server Anda.
if (!defined('SITE_LOGIN_URL')) define('SITE_LOGIN_URL', 'https://domainanda.com/login.php');

/**
 * Kirim email mentah (subject + body HTML) ke satu alamat via SMTP.
 * Return true kalau berhasil terkirim ke server SMTP.
 */
function kirimEmailMentah(string $to, string $subject, string $bodyHtml): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $bodyHtml));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Gagal kirim email ke ' . $to . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Ubah durasi menit jadi teks yang gampang dibaca, misal:
 * 90 -> "1 jam 30 menit", 1500 -> "1 hari 1 jam", 45 -> "45 menit".
 */
function formatDurasiMenit(int $totalMenit): string {
    if ($totalMenit <= 0) return '0 menit';

    $hari  = intdiv($totalMenit, 1440);
    $sisa  = $totalMenit % 1440;
    $jam   = intdiv($sisa, 60);
    $menit = $sisa % 60;

    $bagian = [];
    if ($hari > 0)  $bagian[] = $hari . ' hari';
    if ($jam > 0)   $bagian[] = $jam . ' jam';
    if ($menit > 0) $bagian[] = $menit . ' menit';

    return implode(' ', $bagian);
}

/**
 * Ubah unix timestamp jadi teks tanggal & jam bahasa Indonesia untuk isi email, misal:
 * "Jumat, 25 September 2026 pukul 09.00".
 */
function formatTanggalJamId(int $ts): string {
    $hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $hari[(int)date('w', $ts)] . ', ' . date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts) . ' pukul ' . date('H.i', $ts);
}

/**
 * Kirim email akun peserta (baru dibuat ATAU diperbarui admin), berisi email + password login,
 * dokumen yang harus disiapkan (CV & form lamaran kerja), dan (opsional) MASA AKTIF akun:
 * - `$aktifMulai`  : akun baru bisa dipakai login mulai tanggal & jam ini,
 * - `$aktifSampai` : batas akhir akun; lewat dari waktu ini akun otomatis gugur.
 * Keduanya berformat DATETIME 'Y-m-d H:i:s' (atau null).
 * - `$diperbarui`  : true kalau email ini dikirim ulang setelah admin mengedit akun
 *                    (subject & kalimat pembuka dibedakan dari email akun baru).
 */
function kirimEmailAkunPeserta(string $nama, string $email, string $password, ?string $jabatan = null, ?string $aktifMulai = null, ?string $aktifSampai = null, bool $diperbarui = false): bool {
    $siteName  = htmlspecialchars(SITE_NAME);
    $subject   = ($diperbarui ? 'Pembaruan Akses Akun ' : 'Akses Akun ') . $siteName;
    $namaSafe  = htmlspecialchars($nama);
    $emailSafe = htmlspecialchars($email);
    $passSafe  = htmlspecialchars($password);
    $loginUrl  = htmlspecialchars(SITE_LOGIN_URL);

    $jabatanHtml = '';
    if ($jabatan) {
        $jabatanHtml = '
        <div style="margin-bottom: 20px; padding: 10px 14px; background-color: #f1f5f9; border-radius: 8px; font-size: 13px; color: #475569;">
            Posisi Dilamar: <strong style="color: #0f172a;">' . htmlspecialchars($jabatan) . '</strong>
        </div>';
    }

    // Masa aktif akun. "Aktif mulai" hanya ditampilkan kalau jadwalnya belum tiba.
    $mulaiTs   = $aktifMulai ? strtotime($aktifMulai) : false;
    $sampaiTs  = $aktifSampai ? strtotime($aktifSampai) : false;
    $adaMulai  = ($mulaiTs !== false && $mulaiTs > time());
    $adaSampai = ($sampaiTs !== false);

    $masaAktifHtml = '';
    if ($adaMulai || $adaSampai) {
        // Kotak kuning kalau ada batas akhir (peringatan), biru kalau hanya jadwal mulai
        $bg  = $adaSampai ? '#fffbeb' : '#eff6ff';
        $bd  = $adaSampai ? '#fde68a' : '#bfdbfe';
        $clr = $adaSampai ? '#92400e' : '#1e40af';

        $labelStyle = 'padding: 4px 0; font-size: 13px; color: ' . $clr . '; width: 110px; vertical-align: top;';
        $valueStyle = 'padding: 4px 0; font-size: 13px; color: #0f172a;';

        $baris = '<tr><td style="' . $labelStyle . '">Aktif mulai</td><td style="' . $valueStyle . '"><strong>'
            . ($adaMulai ? htmlspecialchars(formatTanggalJamId($mulaiTs)) : 'Sejak email ini diterima')
            . '</strong></td></tr>';
        if ($adaSampai) {
            $baris .= '<tr><td style="' . $labelStyle . '">Berlaku sampai</td><td style="' . $valueStyle . '"><strong>'
                . htmlspecialchars(formatTanggalJamId($sampaiTs)) . '</strong></td></tr>';
        }

        $catatan = [];
        if ($adaMulai)  $catatan[] = 'Sebelum waktu mulai, Anda belum dapat login untuk mengikuti psikotes.';
        if ($adaSampai) $catatan[] = 'Login dan selesaikan seluruh rangkaian tes sebelum batas akhir. Setelah itu akun otomatis dinyatakan <strong>gugur</strong> dan tidak dapat digunakan lagi.';

        $masaAktifHtml = '
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:' . $bg . '; border:1px solid ' . $bd . '; border-radius:10px; margin-bottom: 20px; padding: 14px 16px;">
            <tr>
                <td style="font-size: 13px; color: ' . $clr . '; line-height: 1.5;">
                    🗓️ <strong>Masa Aktif Akun</strong>
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 8px 0;">' . $baris . '</table>
                    ' . implode(' ', $catatan) . '
                </td>
            </tr>
        </table>';
    }

    // Dokumen yang wajib disiapkan peserta
    $persiapanHtml = '
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; margin-bottom: 24px; padding: 14px 16px;">
        <tr>
            <td style="font-size: 13px; color: #166534; line-height: 1.6;">
                📎 <strong>Dokumen yang Harus Disiapkan</strong>
                <ul style="margin: 8px 0 8px 18px; padding: 0;">
                    <li>CV (Curriculum Vitae) terbaru</li>
                    <li>Form lamaran kerja yang sudah diisi</li>
                </ul>
                Format file <strong>PDF</strong> atau <strong>DOC/DOCX</strong>, ukuran maksimal <strong>5 MB</strong> per file.
                Mohon siapkan kedua dokumen ini sebelum login agar proses tidak terhambat.
            </td>
        </tr>
    </table>';

    // Kalimat pembuka: dibedakan antara akun baru dan akun yang diperbarui
    $pembukaHtml = $diperbarui
        ? 'Akun Anda telah <strong>diperbarui</strong> oleh admin (masa aktif dan/atau password). Silakan gunakan data terbaru berikut untuk masuk ke portal ujian:'
        : 'Akun Anda telah dikonfigurasi untuk mengikuti rangkaian tes psikotes online. Silakan gunakan data berikut untuk masuk ke portal ujian:';

    $bodyHtml = '
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . $subject . '</title>
    </head>
    <body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; -webkit-font-smoothing:antialiased;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f8fafc; padding: 30px 15px;">
            <tr>
                <td align="center">
                    <!-- Main Card Container -->
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:520px; background-color:#ffffff; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                        
                        <!-- Header Banner -->
                        <tr>
                            <td style="background-color:#0f172a; padding: 28px 32px; text-align: left;">
                                <span style="font-size: 12px; font-weight: 700; color: #38bdf8; letter-spacing: 1.5px; text-transform: uppercase; display: block; margin-bottom: 4px;">Portal Psikotes</span>
                                <h1 style="color:#ffffff; font-size: 20px; font-weight: 600; margin: 0;">' . $siteName . '</h1>
                            </td>
                        </tr>

                        <!-- Body Content -->
                        <tr>
                            <td style="padding: 32px; color: #334155;">
                                <h2 style="font-size: 18px; font-weight: 600; color: #0f172a; margin-top: 0; margin-bottom: 12px;">Halo, ' . $namaSafe . ' 👋</h2>
                                <p style="font-size: 14px; line-height: 1.6; color: #475569; margin: 0 0 20px 0;">
                                    ' . $pembukaHtml . '
                                </p>

                                ' . $jabatanHtml . '
                                ' . $masaAktifHtml . '

                                <!-- Account Box -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; margin-bottom: 24px; padding: 16px 20px;">
                                    <tr>
                                        <td style="padding: 6px 0; font-size: 13px; color: #64748b; width: 80px;">Email</td>
                                        <td style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #0f172a;">' . $emailSafe . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; font-size: 13px; color: #64748b;">Password</td>
                                        <td style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #2563eb; font-family: monospace;">' . $passSafe . '</td>
                                    </tr>
                                </table>

                                ' . $persiapanHtml . '

                                <!-- Button CTA -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 28px;">
                                    <tr>
                                        <td align="center">
                                            <a href="' . $loginUrl . '" target="_blank" style="background-color: #2563eb; color: #ffffff; display: block; text-align: center; padding: 14px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; text-decoration: none; transition: background-color 0.2s;">
                                                Masuk ke Portal Ujian &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Security Warning -->
                                <p style="font-size: 12px; line-height: 1.5; color: #94a3b8; margin: 0; text-align: center;">
                                    ⚠️ Harap simpan informasi login ini dengan aman dan jangan memberikannya kepada siapa pun.
                                </p>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 32px; text-align: center; font-size: 12px; color: #94a3b8;">
                                Email ini dikirim secara otomatis oleh sistem <strong>' . $siteName . '</strong>. Mohon tidak membalas email ini.
                            </td>
                        </tr>

                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    return kirimEmailMentah($email, $subject, $bodyHtml);
}

/**
 * Kirim email akun ADMIN (baru dibuat ATAU diperbarui) lewat admin/kelola_admin.php.
 * Lebih ringkas dari email peserta -- tidak ada blok jabatan/batas waktu/dokumen,
 * karena akun admin tidak terikat aturan gugur/deadline.
 * - `$diperbarui` : true kalau email ini dikirim ulang setelah akun admin diedit
 *                   (subject, kalimat pembuka, dan peringatan dibedakan dari email akun baru).
 */
function kirimEmailAkunAdmin(string $nama, string $email, string $password, bool $diperbarui = false): bool {
    $siteName  = htmlspecialchars(SITE_NAME);
    $subject   = ($diperbarui ? 'Pembaruan Akses Akun Admin - ' : 'Akses Akun Admin - ') . $siteName;
    $namaSafe  = htmlspecialchars($nama);
    $emailSafe = htmlspecialchars($email);
    $passSafe  = htmlspecialchars($password);
    $loginUrl  = htmlspecialchars(SITE_LOGIN_URL);

    // Kalimat pembuka & peringatan: dibedakan antara akun baru dan akun yang diperbarui
    $pembukaHtml = $diperbarui
        ? 'Akun <strong>Admin</strong> Anda pada sistem ' . $siteName . ' telah <strong>diperbarui</strong> (data akun dan/atau password). Gunakan kredensial terbaru berikut untuk masuk:'
        : 'Anda telah diberikan akses sebagai <strong>Admin</strong> pada sistem ' . $siteName . '. Gunakan kredensial berikut untuk masuk:';

    $peringatanHtml = $diperbarui
        ? '⚠️ Password lama Anda tidak berlaku lagi. Jika Anda tidak merasa meminta perubahan ini, segera hubungi admin lain.'
        : '⚠️ Segera ganti password Anda setelah login pertama kali, dan jangan bagikan akses ini kepada siapa pun.';

    $bodyHtml = '
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . $subject . '</title>
    </head>
    <body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; -webkit-font-smoothing:antialiased;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f8fafc; padding: 30px 15px;">
            <tr>
                <td align="center">
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:520px; background-color:#ffffff; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">

                        <!-- Header Banner -->
                        <tr>
                            <td style="background-color:#0f172a; padding: 28px 32px; text-align: left;">
                                <span style="font-size: 12px; font-weight: 700; color: #38bdf8; letter-spacing: 1.5px; text-transform: uppercase; display: block; margin-bottom: 4px;">Portal Psikotes &middot; Admin</span>
                                <h1 style="color:#ffffff; font-size: 20px; font-weight: 600; margin: 0;">' . $siteName . '</h1>
                            </td>
                        </tr>

                        <!-- Body Content -->
                        <tr>
                            <td style="padding: 32px; color: #334155;">
                                <h2 style="font-size: 18px; font-weight: 600; color: #0f172a; margin-top: 0; margin-bottom: 12px;">Halo, ' . $namaSafe . ' 👋</h2>
                                <p style="font-size: 14px; line-height: 1.6; color: #475569; margin: 0 0 20px 0;">
                                    ' . $pembukaHtml . '
                                </p>

                                <!-- Account Box -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; margin-bottom: 24px; padding: 16px 20px;">
                                    <tr>
                                        <td style="padding: 6px 0; font-size: 13px; color: #64748b; width: 80px;">Email</td>
                                        <td style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #0f172a;">' . $emailSafe . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; font-size: 13px; color: #64748b;">Password</td>
                                        <td style="padding: 6px 0; font-size: 14px; font-weight: 600; color: #2563eb; font-family: monospace;">' . $passSafe . '</td>
                                    </tr>
                                </table>

                                <!-- Button CTA -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 28px;">
                                    <tr>
                                        <td align="center">
                                            <a href="' . $loginUrl . '" target="_blank" style="background-color: #2563eb; color: #ffffff; display: block; text-align: center; padding: 14px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; text-decoration: none;">
                                                Masuk ke Panel Admin &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <p style="font-size: 12px; line-height: 1.5; color: #94a3b8; margin: 0; text-align: center;">
                                    ' . $peringatanHtml . '
                                </p>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 32px; text-align: center; font-size: 12px; color: #94a3b8;">
                                Email ini dikirim secara otomatis oleh sistem <strong>' . $siteName . '</strong>. Mohon tidak membalas email ini.
                            </td>
                        </tr>

                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';

    return kirimEmailMentah($email, $subject, $bodyHtml);
}