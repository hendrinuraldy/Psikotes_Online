<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
touchLastSeen();
http_response_code(204);