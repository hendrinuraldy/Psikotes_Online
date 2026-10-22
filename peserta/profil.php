<?php
// Simpan file ini sebagai: peserta/profil.php
require_once __DIR__ . '/../includes/functions.php';
requireRole('peserta');
$activeMenu = 'profil';
require __DIR__ . '/../includes/profil_page.php';