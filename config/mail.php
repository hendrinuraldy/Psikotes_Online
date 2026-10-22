<?php
/**
 * Konfigurasi SMTP untuk pengiriman email (reset password, dll).
 * Simpan file ini di: config/mail.php
 *
 * PENTING: file ini berisi kredensial rahasia.
 * Tambahkan "config/mail.php" ke .gitignore agar tidak ikut ter-commit ke Git.
 */

define('SMTP_HOST', 'idsmtp17.idcloudhosting.com');
define('SMTP_PORT', 465);
define('SMTP_USERNAME', 'hrd_support@ssktama.com');
define('SMTP_PASSWORD', 'SSKtama@150216');
define('SMTP_SECURE', 'ssl');

define('SMTP_FROM_EMAIL', 'hrd_support@ssktama.com');
define('SMTP_FROM_NAME', 'HRD SSKTama');