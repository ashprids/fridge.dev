<?php
// Post to feed
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing id']);
    exit;
}

// Sanitize ID to a simple basename without path components
$id = basename($id);

$rootDir = dirname(__DIR__, 2);
$postsDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'feed';
$postFile = $postsDir . DIRECTORY_SEPARATOR . $id . '.txt';

if (!is_file($postFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'post not found']);
    exit;
}

$raw = @file_get_contents($postFile);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'failed to read post']);
    exit;
}

$parsedPost = fridg3_feed_parse_post($raw);
$username = $parsedPost['username'];
$dateLine = $parsedPost['date'];
$body = $parsedPost['body'];

$replyCount = count(fridg3_feed_load_replies($id));

$response = [
    'id' => $id,
    'username' => $username,
    'date_raw' => $dateLine,
    'date_human' => fridg3_feed_humanize_datetime($dateLine),
    'body' => $body,
    'format' => $parsedPost['format'],
    'rendered_html' => $parsedPost['format'] === 'v2' ? fridg3_feed_render_post_body($body, 'v2') : null,
    'reply_count' => $replyCount,
];

echo json_encode($response);
