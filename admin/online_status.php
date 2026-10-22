<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$ids = getDB()->query("
    SELECT id FROM users
    WHERE role = 'peserta' AND last_seen IS NOT NULL
      AND last_seen > (NOW() - INTERVAL 90 SECOND)
")->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: application/json');
echo json_encode(array_map('intval', $ids));