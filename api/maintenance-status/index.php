<?php
require dirname(__DIR__, 2) . '/lib/render.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['enabled' => fridge_is_work_in_progress_enabled(dirname(__DIR__, 2))]);
