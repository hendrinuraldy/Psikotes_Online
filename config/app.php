<?php
/**
 * Alamat dasar (base URL) aplikasi ini, TANPA trailing slash di akhir.
 * Dipakai untuk membuat link absolut (mis. link reset password di email).
 *
 * Kalau IP komputer/server berubah, atau nanti pindah ke domain asli,
 * cukup ubah nilai ini saja.
 */
define('BASE_URL', 'http://192.168.100.201/room/meeting-room-booking/public/psikotes');

// Set zona waktu default ke WIB (Asia/Jakarta)
date_default_timezone_set('Asia/Jakarta');