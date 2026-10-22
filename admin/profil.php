<?php
// Simpan file ini sebagai: admin/profil.php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$activeMenu = 'profil';
require __DIR__ . '/../includes/profil_page.php';