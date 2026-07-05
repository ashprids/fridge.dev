<?php

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();

const UPLOAD_ROOM_TTL_SECONDS = 86400;
const UPLOAD_HEARTBEAT_TIMEOUT_SECONDS = 15;
const UPLOAD_COOKIE_NAME = 'fridg3_upload_peer';
const UPLOAD_ROUTE_PATH = '/tools/upload/';

function upload_find_template_file($filename) {
    $dir = __DIR__;
    $prev_dir = '';

    while ($dir !== $prev_dir) {
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filepath)) {
            return $filepath;
        }
        $prev_dir = $dir;
        $dir = dirname($dir);
    }

    return null;
}

function upload_data_path() {
    $root = dirname(__DIR__, 2);
    $dir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'upload';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'rooms.json';
}

function upload_cookie_options() {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    $isSubdomain = strlen($host) > strlen('.fridge.dev') && substr($host, -strlen('.fridge.dev')) === '.fridge.dev';
    $options = [
        'expires' => time() + (86400 * 365),
        'path' => rtrim(UPLOAD_ROUTE_PATH, '/'),
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($host === 'fridge.dev' || $host === 'm.fridge.dev' || $isSubdomain) {
        $options['domain'] = '.fridge.dev';
    }
    return $options;
}

function upload_peer_id() {
    $peer = isset($_COOKIE[UPLOAD_COOKIE_NAME]) ? (string)$_COOKIE[UPLOAD_COOKIE_NAME] : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $peer)) {
        $peer = bin2hex(random_bytes(16));
        setcookie(UPLOAD_COOKIE_NAME, $peer, upload_cookie_options());
        $_COOKIE[UPLOAD_COOKIE_NAME] = $peer;
    }
    return $peer;
}

function upload_json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function upload_with_store($callback) {
    $path = upload_data_path();
    $handle = @fopen($path, 'c+');
    if (!$handle) {
        upload_json_response(['ok' => false, 'error' => 'store_unavailable'], 500);
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        upload_json_response(['ok' => false, 'error' => 'store_locked'], 503);
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $store = json_decode($raw ?: '{}', true);
    if (!is_array($store)) {
        $store = [];
    }
    if (!isset($store['rooms']) || !is_array($store['rooms'])) {
        $store['rooms'] = [];
    }

    $now = time();
    foreach ($store['rooms'] as $token => $room) {
        $updated = isset($room['updatedAt']) ? (int)$room['updatedAt'] : 0;
        if ($updated > 0 && ($now - $updated) > UPLOAD_ROOM_TTL_SECONDS) {
            unset($store['rooms'][$token]);
        }
    }

    $result = $callback($store);
    if (!is_array($result) || !array_key_exists('response', $result)) {
        $result = ['response' => ['ok' => false, 'error' => 'invalid_store_callback'], 'status' => 500];
    }

    if (($result['write'] ?? true) !== false) {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);
    upload_json_response($result['response'], (int)($result['status'] ?? 200));
}

function upload_public_room($room, $peerId) {
    $side = null;
    if (($room['creatorPeer'] ?? '') === $peerId) {
        $side = 'creator';
    } elseif (($room['guestPeer'] ?? '') === $peerId) {
        $side = 'guest';
    }

    return [
        'token' => $room['token'],
        'createdAt' => $room['createdAt'],
        'updatedAt' => $room['updatedAt'],
        'creatorRole' => $room['creatorRole'],
        'ownSide' => $side,
        'guestJoined' => !empty($room['guestPeer']),
        'ended' => !empty($room['ended']),
        'endedReason' => $room['endedReason'] ?? null,
        'publicKeys' => $room['publicKeys'] ?? [],
    ];
}

function upload_mark_side_seen(&$room, $side) {
    if (!in_array($side, ['creator', 'guest'], true)) {
        return;
    }
    if (!isset($room['lastSeen']) || !is_array($room['lastSeen'])) {
        $room['lastSeen'] = [];
    }
    $room['lastSeen'][$side] = time();
    $room['updatedAt'] = time();
}

function upload_mark_room_ended(&$room, $reason = 'peer_left') {
    $room['ended'] = true;
    $room['endedReason'] = $reason;
    $room['updatedAt'] = time();
}

function upload_check_heartbeat_timeout(&$room) {
    if (!empty($room['ended']) || empty($room['guestPeer']) || !isset($room['lastSeen']) || !is_array($room['lastSeen'])) {
        return;
    }
    $now = time();
    foreach (['creator', 'guest'] as $side) {
        $lastSeen = isset($room['lastSeen'][$side]) ? (int)$room['lastSeen'][$side] : 0;
        if ($lastSeen > 0 && ($now - $lastSeen) > UPLOAD_HEARTBEAT_TIMEOUT_SECONDS) {
            upload_mark_room_ended($room, 'peer_timeout');
            return;
        }
    }
}

function upload_claim_room(&$store, $token, $peerId) {
    if (!isset($store['rooms'][$token]) || !is_array($store['rooms'][$token])) {
        return 'not_found';
    }

    $room =& $store['rooms'][$token];
    upload_check_heartbeat_timeout($room);
    if (!empty($room['ended'])) {
        return 'room_ended';
    }
    if (($room['creatorPeer'] ?? '') === $peerId || ($room['guestPeer'] ?? '') === $peerId) {
        $side = ($room['creatorPeer'] ?? '') === $peerId ? 'creator' : 'guest';
        upload_mark_side_seen($room, $side);
        return null;
    }

    if (empty($room['guestPeer'])) {
        $room['guestPeer'] = $peerId;
        upload_mark_side_seen($room, 'guest');
        return null;
    }

    return 'room_full';
}

if (isset($_GET['api'])) {
    $peerId = upload_peer_id();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)$_GET['api'];

    if ($action === 'create' && $method === 'POST') {
        $role = (string)($_POST['role'] ?? 'sender');
        if (!in_array($role, ['sender', 'receiver'], true)) {
            upload_json_response(['ok' => false, 'error' => 'invalid_role'], 400);
        }

        upload_with_store(function (&$store) use ($peerId, $role) {
            $token = bin2hex(random_bytes(18));
            $now = time();
            $store['rooms'][$token] = [
                'token' => $token,
                'creatorPeer' => $peerId,
                'guestPeer' => null,
                'creatorRole' => $role,
                'createdAt' => $now,
                'updatedAt' => $now,
                'lastSeen' => ['creator' => $now],
                'ended' => false,
                'publicKeys' => [],
                'signals' => [],
                'nextSignalId' => 1,
            ];

            return [
                'response' => [
                    'ok' => true,
                    'room' => upload_public_room($store['rooms'][$token], $peerId),
                    'url' => UPLOAD_ROUTE_PATH . '?r=' . rawurlencode($token),
                ],
            ];
        });
    }

    $token = (string)($_GET['r'] ?? $_POST['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{36}$/', $token)) {
        upload_json_response(['ok' => false, 'error' => 'invalid_room'], 400);
    }

    if ($action === 'room' && $method === 'GET') {
        upload_with_store(function (&$store) use ($token, $peerId) {
            $error = upload_claim_room($store, $token, $peerId);
            if ($error) {
                return ['response' => ['ok' => false, 'error' => $error], 'status' => $error === 'not_found' ? 404 : ($error === 'room_ended' ? 410 : 403)];
            }
            $room =& $store['rooms'][$token];
            return ['response' => ['ok' => true, 'room' => upload_public_room($room, $peerId)]];
        });
    }

    if ($action === 'heartbeat' && $method === 'POST') {
        $side = (string)($_POST['side'] ?? '');
        upload_with_store(function (&$store) use ($token, $peerId, $side) {
            $error = upload_claim_room($store, $token, $peerId);
            if ($error) {
                return ['response' => ['ok' => false, 'error' => $error], 'status' => $error === 'not_found' ? 404 : ($error === 'room_ended' ? 410 : 403)];
            }
            $room =& $store['rooms'][$token];
            $ownSide = ($room['creatorPeer'] ?? '') === $peerId ? 'creator' : 'guest';
            if ($side !== '' && $side !== $ownSide) {
                return ['response' => ['ok' => false, 'error' => 'wrong_side'], 'status' => 403];
            }
            upload_mark_side_seen($room, $ownSide);
            return ['response' => ['ok' => true, 'room' => upload_public_room($room, $peerId)]];
        });
    }

    if ($action === 'end' && $method === 'POST') {
        $side = (string)($_POST['side'] ?? '');
        upload_with_store(function (&$store) use ($token, $peerId, $side) {
            if (!isset($store['rooms'][$token]) || !is_array($store['rooms'][$token])) {
                return ['response' => ['ok' => false, 'error' => 'not_found'], 'status' => 404];
            }
            $room =& $store['rooms'][$token];
            $ownSide = null;
            if (($room['creatorPeer'] ?? '') === $peerId) {
                $ownSide = 'creator';
            } elseif (($room['guestPeer'] ?? '') === $peerId) {
                $ownSide = 'guest';
            }
            if ($ownSide === null || ($side !== '' && $side !== $ownSide)) {
                return ['response' => ['ok' => false, 'error' => 'wrong_side'], 'status' => 403];
            }
            upload_mark_room_ended($room, 'peer_left');
            return ['response' => ['ok' => true]];
        });
    }

    if ($action === 'key' && $method === 'POST') {
        $side = (string)($_POST['side'] ?? '');
        $publicKey = (string)($_POST['publicKey'] ?? '');
        if (!in_array($side, ['creator', 'guest'], true) || strlen($publicKey) > 4096) {
            upload_json_response(['ok' => false, 'error' => 'invalid_key'], 400);
        }

        upload_with_store(function (&$store) use ($token, $peerId, $side, $publicKey) {
            $error = upload_claim_room($store, $token, $peerId);
            if ($error) {
                return ['response' => ['ok' => false, 'error' => $error], 'status' => $error === 'not_found' ? 404 : ($error === 'room_ended' ? 410 : 403)];
            }
            $room =& $store['rooms'][$token];
            if (($side === 'creator' && ($room['creatorPeer'] ?? '') !== $peerId) || ($side === 'guest' && ($room['guestPeer'] ?? '') !== $peerId)) {
                return ['response' => ['ok' => false, 'error' => 'wrong_side'], 'status' => 403];
            }
            $room['publicKeys'][$side] = $publicKey;
            $room['updatedAt'] = time();
            return ['response' => ['ok' => true, 'room' => upload_public_room($room, $peerId)]];
        });
    }

    if ($action === 'signal' && $method === 'POST') {
        $side = (string)($_POST['side'] ?? '');
        $type = (string)($_POST['type'] ?? '');
        $payload = (string)($_POST['payload'] ?? '');
        if (!in_array($side, ['creator', 'guest'], true) || !preg_match('/^[a-z-]{2,32}$/', $type) || strlen($payload) > 262144) {
            upload_json_response(['ok' => false, 'error' => 'invalid_signal'], 400);
        }

        upload_with_store(function (&$store) use ($token, $peerId, $side, $type, $payload) {
            $error = upload_claim_room($store, $token, $peerId);
            if ($error) {
                return ['response' => ['ok' => false, 'error' => $error], 'status' => $error === 'not_found' ? 404 : ($error === 'room_ended' ? 410 : 403)];
            }
            $room =& $store['rooms'][$token];
            if (($side === 'creator' && ($room['creatorPeer'] ?? '') !== $peerId) || ($side === 'guest' && ($room['guestPeer'] ?? '') !== $peerId)) {
                return ['response' => ['ok' => false, 'error' => 'wrong_side'], 'status' => 403];
            }
            $id = (int)($room['nextSignalId'] ?? 1);
            $room['signals'][] = [
                'id' => $id,
                'from' => $side,
                'type' => $type,
                'payload' => $payload,
                'createdAt' => time(),
            ];
            $room['nextSignalId'] = $id + 1;
            if (count($room['signals']) > 400) {
                $room['signals'] = array_slice($room['signals'], -300);
            }
            $room['updatedAt'] = time();
            return ['response' => ['ok' => true, 'id' => $id]];
        });
    }

    if ($action === 'signals' && $method === 'GET') {
        $since = max(0, (int)($_GET['since'] ?? 0));
        upload_with_store(function (&$store) use ($token, $peerId, $since) {
            $error = upload_claim_room($store, $token, $peerId);
            if ($error) {
                return ['response' => ['ok' => false, 'error' => $error], 'status' => $error === 'not_found' ? 404 : ($error === 'room_ended' ? 410 : 403)];
            }
            $room =& $store['rooms'][$token];
            $ownSide = ($room['creatorPeer'] ?? '') === $peerId ? 'creator' : 'guest';
            $signals = [];
            foreach (($room['signals'] ?? []) as $signal) {
                if ((int)$signal['id'] > $since && ($signal['from'] ?? '') !== $ownSide) {
                    $signals[] = $signal;
                }
            }
            return [
                'response' => [
                    'ok' => true,
                    'room' => upload_public_room($room, $peerId),
                    'signals' => $signals,
                ],
            ];
        });
    }

    upload_json_response(['ok' => false, 'error' => 'not_found'], 404);
}

$title = 'upload';
$description = 'peer-to-peer encrypted file transfer.';

$render_helper_path = upload_find_template_file('lib/render.php');
if ($render_helper_path) {
    require_once $render_helper_path;
}

$template_name = function_exists('get_preferred_template_name')
    ? get_preferred_template_name(__DIR__)
    : 'template.html';
$template_path = upload_find_template_file($template_name);
if (!$template_path && $template_name !== 'template.html') {
    $template_path = upload_find_template_file('template.html');
}
if (!$template_path) {
    die('page template not found. report this issue to me@fridge.dev.');
}

$template = file_get_contents($template_path);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}

$content_path = upload_find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);
$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);

$user_greeting = '';
if (isset($_SESSION['user']) && isset($_SESSION['user']['name'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>';
    $html = str_replace($accountBtn, $logoutBtn, $html);
}
$html = str_replace('{user_greeting}', $user_greeting, $html);
echo $html;
?>
