<?php
$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . "/lib/session.php") && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . "/lib/session.php";
fridg3_start_session();
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'feed.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'guestbook.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'toast.php';
fridg3_feed_refresh_session_user();

function find_template_file($filename) {
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

$title = 'feed post';
$description = 'view a single feed post.';
$replyError = '';
$replySuccess = false;
$replyEditError = '';
$replyEditTargetId = trim((string)($_GET['edit_reply'] ?? $_POST['reply_id'] ?? ''));

// Resolve post id from path (/feed/posts/{id}) with ?= fallback for old links
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$postFilename = null;
$postIdNoExt = null;

if ($requestPath) {
    $segments = explode('/', trim($requestPath, '/'));
    if (count($segments) >= 3 && $segments[0] === 'feed' && $segments[1] === 'posts' && $segments[2] !== '') {
        $slug = rawurldecode($segments[2]);
        $slug = basename($slug); // strip any nested paths
        $slug = preg_replace('/\.txt$/i', '', $slug); // drop optional extension
        if ($slug !== '') {
            $postIdNoExt = $slug;
            $postFilename = $postIdNoExt . '.txt';
        }
    }
}

// Fallback: legacy ?= links
if ($postFilename === null) {
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    if (strpos($queryString, '=') === 0) {
        $postIdNoExt = basename(substr($queryString, 1));
        $postFilename = $postIdNoExt . '.txt';
    }
}

if (!$postFilename) {
    header('Location: /feed');
    exit;
}

// Load the post file
$postsDir = fridg3_feed_posts_dir();
$postPath = $postsDir . DIRECTORY_SEPARATOR . $postFilename;
if (!file_exists($postPath) || !preg_match('/\.txt$/', $postFilename)) {
    header('Location: /feed');
    exit;
}

$raw = @file_get_contents($postPath);
if ($raw === false) {
    header('Location: /feed');
    exit;
}

// Parse the post
$lines = preg_split("/(\r\n|\n|\r)/", $raw);
$usernameLine = isset($lines[0]) ? trim($lines[0]) : '';
$dateLine = isset($lines[1]) ? trim($lines[1]) : '';
$body = '';
if (count($lines) > 2) {
    $body = implode("\n", array_slice($lines, 2));
}

// Normalize username
$username = ltrim($usernameLine, '@');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$clientIp = fridg3_feed_client_ip();
$isLoggedIn = isset($_SESSION['user']) && isset($_SESSION['user']['username']);
$postingRestricted = $isLoggedIn && fridg3_current_user_posting_restricted();
$isClientIpBanned = fridg3_feed_is_ip_banned($clientIp);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $replyBody = trim((string)($_POST['reply_content'] ?? ''));
    $replyAction = (string)($_POST['reply_action'] ?? 'create');
    $replyId = trim((string)($_POST['reply_id'] ?? ''));
    $parentReplyId = trim((string)($_POST['parent_reply_id'] ?? ''));
    $guestBrowserId = trim((string)($_POST['guest_browser_id'] ?? ''));
    $guestDisplayName = trim((string)($_POST['guest_username'] ?? ''));
    $guestDisplayNameForSave = $guestDisplayName;
    $replyBodyForSave = $replyBody;
    $imageMap = [];
    $voiceMap = [];
    if ($isLoggedIn && !$postingRestricted && isset($_FILES['images']) && is_array($_FILES['images'])) {
        $imageMap = fridg3_feed_process_uploaded_images($_FILES['images']);
        $replyBody = fridg3_feed_replace_image_placeholders($replyBody, $imageMap);
    }
    if ($isLoggedIn && !$postingRestricted && $replyAction === 'create' && isset($_FILES['voice_notes']) && is_array($_FILES['voice_notes'])) {
        $voiceMap = fridg3_feed_process_uploaded_voice_notes($_FILES['voice_notes']);
        $replyBody = fridg3_feed_replace_voice_placeholders($replyBody, $voiceMap);
        if (preg_match('/\[voice:\d+\]/i', $replyBody) === 1) {
            foreach ($voiceMap as $voice) {
                fridg3_feed_delete_voice_files_from_content('[audio=' . ($voice['url'] ?? '') . ']');
            }
            $replyBody = '';
            $replyError = 'voice note failed. keep it under 2 minutes and try again.';
        }
    }
    if (!$isLoggedIn && $replyAction === 'create') {
        $guestDisplayNameForSave = fridg3_feed_apply_guest_filter($guestDisplayNameForSave);
        $replyBodyForSave = fridg3_feed_apply_guest_filter($replyBodyForSave, true);
    }
    $canModerateReplies = fridg3_feed_current_user_can_moderate_replies($username);
    $existingReplies = fridg3_feed_load_replies((string)$postIdNoExt);
    $targetReply = null;
    $parentReply = null;
    foreach ($existingReplies as $existingReply) {
        if (($existingReply['id'] ?? '') === $replyId) {
            $targetReply = $existingReply;
        }
        if ($parentReplyId !== '' && ($existingReply['id'] ?? '') === $parentReplyId) {
            $parentReply = $existingReply;
        }
    }
    $canManageTargetReply = $targetReply !== null
        && fridg3_feed_current_visitor_can_manage_reply($username, $targetReply, $clientIp);

    if (!hash_equals((string)$_SESSION['csrf_token'], $submittedToken)) {
        foreach ($voiceMap as $voice) {
            fridg3_feed_delete_voice_files_from_content('[audio=' . ($voice['url'] ?? '') . ']');
        }
        $replyError = 'invalid request. try again.';
    } elseif (!$isLoggedIn && $replyAction !== 'create' && !$canManageTargetReply) {
        $replyEditError = 'You do not have permission to manage replies.';
    } elseif ($postingRestricted && in_array($replyAction, ['create', 'update'], true)) {
        if ($replyAction === 'update') {
            $replyEditError = 'your account has been restricted.';
        } else {
            $replyError = 'your account has been restricted.';
        }
    } elseif (!$isLoggedIn && $isClientIpBanned) {
        $replyError = 'your IP address has been restricted.';
    } elseif (!$isLoggedIn && $replyAction === 'create' && $guestDisplayName !== '' && fridg3_feed_registered_username_exists($guestDisplayName)) {
        $replyError = 'that username belongs to a registered account. please choose another name or log in.';
    } elseif (!$isLoggedIn && $replyAction === 'create' && fridg3_feed_guest_filter_is_mostly_filtered($replyBody)) {
        $replyError = 'that reply is mostly filtered words. please rewrite it.';
    } elseif (!$isLoggedIn && preg_match('/\[(?:img|voice):\d+\]/i', $replyBody) === 1) {
        $replyError = 'Guest replies can link images, but cannot upload files.';
    } elseif ($replyError !== '' && $replyAction === 'create') {
        // Keep the validation error set above.
    } elseif ($replyAction === 'ban_ip') {
        if (empty($_SESSION['user']['isAdmin'])) {
            $replyEditError = 'you do not have permission to ban IP addresses.';
        } elseif ($targetReply === null || ($targetReply['isGuest'] ?? false) !== true || !filter_var((string)($targetReply['ip'] ?? ''), FILTER_VALIDATE_IP)) {
            $replyEditError = 'could not find a guest IP to ban.';
        } elseif (!fridg3_feed_ban_guest_ip((string)$targetReply['ip'], (string)$_SESSION['user']['username'], (string)($targetReply['username'] ?? 'Anonymous'))) {
            $replyEditError = 'failed to ban IP.';
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: /feed/posts/' . rawurlencode((string)$postIdNoExt) . '?ip_banned=1');
            exit;
        }
    } elseif ($replyAction === 'purge_ip_replies') {
        if (empty($_SESSION['user']['isAdmin'])) {
            $replyEditError = 'you do not have permission to purge guest content.';
        } elseif (!fridg3_feed_verify_current_admin_password((string)($_POST['admin_password'] ?? ''))) {
            $replyEditError = 'admin password did not match. purge cancelled.';
        } elseif ($targetReply === null || ($targetReply['isGuest'] ?? false) !== true || !filter_var((string)($targetReply['ip'] ?? ''), FILTER_VALIDATE_IP)) {
            $replyEditError = 'could not find a guest IP to purge.';
        } else {
            $purgeResult = fridg3_feed_purge_guest_replies_by_ip((string)$targetReply['ip']);
            $guestbookPurgeResult = fridg3_guestbook_purge_entries_by_ip((string)$targetReply['ip']);
            $purgedCount = (int)$purgeResult['deleted'] + (int)$guestbookPurgeResult['deleted'];
            $failedCount = (int)$purgeResult['failed'] + (int)$guestbookPurgeResult['failed'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: /feed/posts/' . rawurlencode((string)$postIdNoExt) . '?ip_purged=' . rawurlencode((string)$purgedCount) . '&ip_purge_failed=' . rawurlencode((string)$failedCount));
            exit;
        }
    } elseif ($replyAction === 'delete') {
        if (!$canManageTargetReply) {
            $replyEditError = 'you do not have permission to delete replies.';
        } elseif ($replyId === '' || !fridg3_feed_delete_reply((string)$postIdNoExt, $replyId)) {
            $replyEditError = 'failed to delete reply.';
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: /feed/posts/' . rawurlencode((string)$postIdNoExt) . '?reply_deleted=1');
            exit;
        }
    } elseif ($replyAction === 'update') {
        if (!$canManageTargetReply) {
            $replyEditError = 'you do not have permission to edit replies.';
        } elseif (!$isLoggedIn && $targetReply !== null && ($targetReply['isGuest'] ?? false) === true && fridg3_feed_guest_reply_has_filtered_text($targetReply)) {
            $replyEditError = 'guest replies with filtered words cannot be edited.';
        } elseif ($replyBody === '') {
            $replyEditError = 'reply cannot be empty.';
        } elseif (strlen($replyBody) > 4000) {
            $replyEditError = 'reply is too long.';
        } elseif ($replyId === '' || !fridg3_feed_update_reply((string)$postIdNoExt, $replyId, $replyBody)) {
            $replyEditError = 'failed to update reply.';
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: /feed/posts/' . rawurlencode((string)$postIdNoExt) . '?reply_updated=1');
            exit;
        }
    } elseif ($replyBody === '') {
        $replyError = 'reply cannot be empty.';
    } elseif (strlen($replyBody) > 4000) {
        $replyError = 'reply is too long.';
    } elseif ($parentReplyId !== '' && $parentReply === null) {
        $replyError = 'could not find the comment you are replying to.';
    } elseif ($isLoggedIn && !fridg3_feed_save_reply($postIdNoExt ?? '', (string)$_SESSION['user']['username'], $replyBody, $parentReplyId)) {
        $replyError = 'failed to save reply.';
    } elseif (!$isLoggedIn && !fridg3_feed_save_guest_reply($postIdNoExt ?? '', $guestDisplayNameForSave, $clientIp, $replyBodyForSave, $parentReplyId, $guestBrowserId)) {
        $replyError = 'failed to save reply.';
    } else {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: /feed/posts/' . rawurlencode((string)$postIdNoExt) . '?reply_posted=1');
        $triggerUsername = $isLoggedIn ? (string)$_SESSION['user']['username'] : ($guestDisplayNameForSave !== '' ? $guestDisplayNameForSave : 'Anonymous');
        $shouldQueueToastAutoReply = strcasecmp($triggerUsername, 'toast') !== 0
            && (strcasecmp(ltrim($username, '@'), 'toast') === 0 || fridg3_toast_feed_mentions_toast($replyBody));
        if ($shouldQueueToastAutoReply) {
            $toastReplyPostId = (string)($postIdNoExt ?? '');
            $toastReplyPostUsername = $username;
            $toastReplyPostDate = $dateLine;
            $toastReplyPostBody = $body;
            $toastReplyTriggerUsername = $triggerUsername;
            $toastReplyTriggerBody = $isLoggedIn ? $replyBody : $replyBodyForSave;
            fridg3_toast_run_auto_reply_after_response(static function () use (
                $toastReplyPostId,
                $toastReplyPostUsername,
                $toastReplyPostDate,
                $toastReplyPostBody,
                $toastReplyTriggerUsername,
                $toastReplyTriggerBody
            ): void {
                fridg3_toast_maybe_auto_reply_to_feed(
                    $toastReplyPostId,
                    $toastReplyPostUsername,
                    $toastReplyPostDate,
                    $toastReplyPostBody,
                    [
                        'username' => $toastReplyTriggerUsername,
                        'body' => $toastReplyTriggerBody,
                    ]
                );
            });
        }
        exit;
    }
}

$safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
$humanizedDate = fridg3_feed_humanize_datetime($dateLine);
$safeDate = htmlspecialchars($humanizedDate, ENT_QUOTES, 'UTF-8');
$safeBody = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
$replySuccess = isset($_GET['reply_posted']) && $_GET['reply_posted'] === '1';
$replyUpdated = isset($_GET['reply_updated']) && $_GET['reply_updated'] === '1';
$replyDeleted = isset($_GET['reply_deleted']) && $_GET['reply_deleted'] === '1';
$ipBanned = isset($_GET['ip_banned']) && $_GET['ip_banned'] === '1';
$ipPurged = isset($_GET['ip_purged']) ? max(0, (int)$_GET['ip_purged']) : null;
$ipPurgeFailed = isset($_GET['ip_purge_failed']) ? max(0, (int)$_GET['ip_purge_failed']) : 0;
$replyFormValue = isset($_POST['reply_content']) ? htmlspecialchars((string)$_POST['reply_content'], ENT_QUOTES, 'UTF-8') : '';
$guestUsernameValue = isset($_POST['guest_username']) ? htmlspecialchars((string)$_POST['guest_username'], ENT_QUOTES, 'UTF-8') : '';
$guestFilterTermsJson = json_encode(fridg3_feed_filter_terms(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($guestFilterTermsJson)) {
    $guestFilterTermsJson = '[]';
}
$replies = fridg3_feed_load_replies((string)$postIdNoExt);
$replyParentId = trim((string)($_POST['parent_reply_id'] ?? $_GET['reply_to'] ?? ''));
$repliesById = [];
foreach ($replies as $reply) {
    $replyKey = (string)($reply['id'] ?? '');
    if ($replyKey !== '') {
        $repliesById[$replyKey] = $reply;
    }
}
if ($replyParentId !== '' && !isset($repliesById[$replyParentId])) {
    $replyParentId = '';
}
$canModerateReplies = fridg3_feed_current_user_can_moderate_replies($username);
$editReplyBodyValue = '';
if ($replyEditTargetId !== '' && isset($_POST['reply_action']) && (string)$_POST['reply_action'] === 'update') {
    $editReplyBodyValue = (string)($_POST['reply_content'] ?? '');
}

// Extract first image from body for og:image metadata
$imageUrl = null;
if (preg_match('/\[img=([^\]\s]+)\]/', $body, $matches)) {
    $imageUrl = $matches[1];
}

// Remove BBCode from description
$plainBody = $body;
$plainBody = preg_replace('/\[img[^\]]*\](?:\[name:[^\]]*\])?/i', '', $plainBody); // Remove images
$plainBody = preg_replace('/\[[^\]]*\][^\[]*\[\/[^\]]*\]/s', '', $plainBody); // Remove other BBCode tags
$plainBody = preg_replace('/\[([a-z]+)[^\]]*\]/i', '', $plainBody); // Remove remaining opening tags
$plainBody = trim($plainBody);
// Limit description to 160 chars for metadata
$shortDescription = substr($plainBody, 0, 160);
if (strlen($plainBody) > 160) {
    $shortDescription .= '...';
}

// Update title and description
$title = 'feed post by @' . $safeUser;
$description = htmlspecialchars($shortDescription, ENT_QUOTES, 'UTF-8');

// Load template
$render_helper_path = find_template_file('lib/render.php');
if ($render_helper_path) {
    require_once $render_helper_path;
}

$template_name = function_exists('get_preferred_template_name')
    ? get_preferred_template_name(__DIR__)
    : 'template.html';
$template_path = find_template_file($template_name);
if (!$template_path && $template_name !== 'template.html') {
    $template_path = find_template_file('template.html');
}
if (!$template_path) {
    die('page template not found. report this issue to me@fridge.dev.');
}

$template = file_get_contents($template_path);
if (function_exists('apply_preferred_theme_stylesheet')) {
    $template = apply_preferred_theme_stylesheet($template, __DIR__);
}

// Inject og:image meta tag if post has an image
if ($imageUrl) {
    $ogImageTag = '<meta property="og:image" content="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '">';
    $template = str_replace('</head>', $ogImageTag . "\n</head>", $template);
}

// Generate user greeting if logged in
$user_greeting = '';
if (isset($_SESSION['user'])) {
    $user_name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
    $user_greeting = '<div id="user-greeting">Hello, ' . $user_name . '!</div>';
    
    // Swap Account button to Logout
    $accountBtn = '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>';
    $logoutBtn = '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-arrow-right-from-bracket"></i></div></a>';
    $template = str_replace($accountBtn, $logoutBtn, $template);
}

// Replace user greeting placeholder
$template = str_replace('{user_greeting}', $user_greeting, $template);

$content_path = find_template_file('content.html');
if (!$content_path) {
    die('content.html not found. report this issue to me@fridge.dev.');
}

$content = file_get_contents($content_path);

// Inject data-post-id on bookmark icon so JS knows which post this is
if ($postIdNoExt !== null) {
    $safePostId = htmlspecialchars($postIdNoExt, ENT_QUOTES, 'UTF-8');
    $content = str_replace('id="post-bookmark-feed"', 'id="post-bookmark-feed" data-post-id="' . $safePostId . '"', $content);
}

// Determine if current user can edit this post
$canEdit = false;
if (isset($_SESSION['user'])) {
    $currentUser = $_SESSION['user']['username'] ?? '';
    $isAdmin = $_SESSION['user']['isAdmin'] ?? false;
    $canEdit = ($currentUser === $username) || $isAdmin;
}

// Build edit icon if allowed
$editIcon = '';
if ($canEdit) {
    $postId = urlencode($postFilename);
    $editIcon = '<span id="post-edit-feed" data-tooltip="edit post"><a href="/feed/edit?post=' . $postId . '" style="color: inherit; text-decoration: none;"><i class="fa-solid fa-pencil"></i></a></span>';
}

$bookmarkIcon = '<span id="post-bookmark-feed" data-tooltip="save post"><i class="fa-regular fa-bookmark"></i></span>';
$postMeta = $safeDate . ' • ';
if ($editIcon !== '') {
    $postMeta .= $editIcon . ' ';
}
$postMeta .= $bookmarkIcon;

// Replace placeholders in content
$content = str_replace('{username}', $safeUser, $content);
$content = str_replace('{content}', $safeBody, $content);
$content = str_replace('{post_meta}', $postMeta, $content);
$content = str_replace('{reply_form_value}', $replyFormValue, $content);
$content = str_replace('{reply_csrf_token}', htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'), $content);

$replyNotice = '';
if ($replySuccess) {
    $replyNotice = '<div class="feed-reply-notice success">reply posted.</div>';
} elseif ($replyUpdated) {
    $replyNotice = '<div class="feed-reply-notice success">reply updated.</div>';
} elseif ($replyDeleted) {
    $replyNotice = '<div class="feed-reply-notice success">reply deleted.</div>';
} elseif ($ipBanned) {
    $replyNotice = '<div class="feed-reply-notice success">IP banned from feed and guestbook posting.</div>';
} elseif ($ipPurged !== null) {
    if ($ipPurgeFailed > 0) {
        $replyNotice = '<div class="feed-reply-notice error">purged ' . $ipPurged . ' guest item(s), but ' . $ipPurgeFailed . ' data file(s) failed.</div>';
    } elseif ($ipPurged === 0) {
        $replyNotice = '<div class="feed-reply-notice success">no guest content found for this IP.</div>';
    } else {
        $replyNotice = '<div class="feed-reply-notice success">purged ' . $ipPurged . ' guest item(s).</div>';
    }
} elseif ($replyError !== '') {
    $replyNotice = '<div class="feed-reply-notice error">' . htmlspecialchars($replyError, ENT_QUOTES, 'UTF-8') . '</div>';
}

$replyEditNotice = '';
if ($replyEditError !== '') {
    $replyEditNotice = '<div class="feed-reply-notice error">' . htmlspecialchars($replyEditError, ENT_QUOTES, 'UTF-8') . '</div>';
}

$replyFormHtml = '';
$replyTargetHtml = '';
if ($replyParentId !== '' && isset($repliesById[$replyParentId])) {
    $targetName = trim((string)($repliesById[$replyParentId]['username'] ?? ''));
    if ($targetName === '') {
        $targetName = 'Anonymous';
    }
    $replyTargetHtml = '<div class="feed-reply-target" data-feed-reply-target>'
        . 'replying to <strong>' . htmlspecialchars($targetName, ENT_QUOTES, 'UTF-8') . '</strong>'
        . ' <a href="/feed/posts/' . rawurlencode((string)$postIdNoExt) . '" data-feed-reply-cancel>cancel</a>'
        . '</div>';
} else {
    $replyTargetHtml = '<div class="feed-reply-target" data-feed-reply-target hidden></div>';
}
$replyToolbarHtml = '<div class="bbcode-toolbar">'
    . '<button type="button" class="bbcode-btn" data-tag="b" data-tooltip="bold"><i class="fa-solid fa-bold"></i></button>'
    . '<button type="button" class="bbcode-btn" data-tag="i" data-tooltip="italic"><i class="fa-solid fa-italic"></i></button>'
    . '<button type="button" class="bbcode-btn" data-tag="u" data-tooltip="underline"><i class="fa-solid fa-underline"></i></button>'
    . '<button type="button" class="bbcode-btn" data-tag="s" data-tooltip="strikethrough"><i class="fa-solid fa-strikethrough"></i></button>'
    . '<button type="button" id="bbcode-spoiler-btn" class="bbcode-btn" data-tooltip="spoiler"><i class="fa-solid fa-eye-slash"></i></button>'
    . '<button type="button" id="bbcode-color-btn" class="bbcode-btn" data-tooltip="color"><i class="fa-solid fa-palette"></i></button>'
    . '<input id="bbcode-color-input" type="color" style="display: none;">'
    . '<label for="bbcode-image-input">'
    . '<button type="button" id="bbcode-image-btn" class="bbcode-btn" data-tooltip="attach image"><i class="fa-solid fa-image"></i></button>'
    . '</label>'
    . ($isLoggedIn
        ? '<input id="bbcode-image-input" name="images[]" type="file" accept="image/*" multiple style="display: none;">'
        : '<input id="bbcode-image-input" type="file" accept="image/*" multiple disabled style="display: none;">')
    . ($isLoggedIn
        ? '<button type="button" id="bbcode-voice-btn" class="bbcode-btn bbcode-voice-btn" data-tooltip="record voice note"><i class="fa-solid fa-microphone"></i></button>'
            . '<input id="bbcode-voice-input" name="voice_notes[]" type="file" accept="audio/*" multiple style="display: none;">'
        : '')
    . '<button type="button" class="bbcode-btn" data-tag="code=python" data-tooltip="code block"><i class="fa-solid fa-code"></i></button>'
    . '<button type="button" id="bbcode-list-btn" class="bbcode-btn" data-tag="list" data-tooltip="list"><i class="fa-solid fa-list-ul"></i></button>'
    . ($isLoggedIn ? '<button type="button" id="bbcode-tooltip-btn" class="bbcode-btn" data-tooltip="tooltip"><i class="fa-solid fa-comment-dots"></i></button>' : '')
    . '<button type="button" id="bbcode-link-btn" class="bbcode-btn" data-tooltip="link"><i class="fa-solid fa-link"></i></button>'
    . ($isLoggedIn
        ? '<select id="bbcode-header-dropdown" class="bbcode-dropdown" data-tooltip="heading">'
            . '<option value="">headings</option>'
            . '<option value="h3">heading</option>'
            . '<option value="h4">sub-heading</option>'
            . '<option value="h5">caption</option>'
            . '</select>'
        : '')
    . '<button type="button" id="bbcode-preview-toggle" class="bbcode-btn" data-tooltip="toggle preview"><i class="fa-solid fa-eye"></i></button>'
    . '</div>';
if (!$isClientIpBanned || $isLoggedIn) {
    $replyFormHtml = '<form id="feed-reply-form" method="POST" enctype="multipart/form-data" action="/feed/posts/' . rawurlencode((string)$postIdNoExt) . '">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="parent_reply_id" value="' . htmlspecialchars($replyParentId, ENT_QUOTES, 'UTF-8') . '" data-feed-reply-parent-input>'
        . (!$isLoggedIn ? '<input type="hidden" name="guest_browser_id" value="" data-feed-guest-browser-id>' : '')
        . $replyTargetHtml
        . (!$isLoggedIn ? '<input id="textbox" class="feed-guest-username" name="guest_username" type="text" maxlength="50" placeholder="name (optional)" value="' . $guestUsernameValue . '">' : '')
        . (!$isLoggedIn ? '<br><br>' : '')
        . '<div class="bbcode-editor">'
        . (!$isLoggedIn ? '<script type="application/json" data-feed-guest-filter-terms>' . $guestFilterTermsJson . '</script>' : '')
        . $replyToolbarHtml
        . ($isLoggedIn ? '<div class="bbcode-voice-recorder" hidden></div>' : '')
        . '<textarea id="bbcode-textbox" class="feed-reply-textbox" name="reply_content" placeholder="write a reply..." maxlength="4000">{reply_form_value}</textarea>'
        . '<div id="bbcode-preview" style="display: none;"></div>'
        . '</div>'
        . '<button id="form-button" type="submit">reply</button>'
        . '</form>';
    if ($postingRestricted) {
        $replyFormHtml = fridg3_posting_restriction_notice() . fridg3_disable_composer_controls($replyFormHtml);
    }
} elseif (!$isLoggedIn && $isClientIpBanned) {
    $replyNotice = '<div class="feed-reply-notice error">your IP address has been restricted.</div>';
}

$canCreateReply = !$isClientIpBanned || $isLoggedIn;
$visibleReplies = [];
$visibleRepliesById = [];
foreach ($replies as $reply) {
    $replyId = (string)($reply['id'] ?? '');
    $isGuestReply = ($reply['isGuest'] ?? false) === true;
    $replyIp = (string)($reply['ip'] ?? '');
    $replyIpBanned = $isGuestReply && $replyIp !== '' && fridg3_feed_is_ip_banned($replyIp);
    if (!$canModerateReplies && $replyIpBanned) {
        continue;
    }

    $visibleReplies[] = $reply;
    if ($replyId !== '') {
        $visibleRepliesById[$replyId] = $reply;
    }
}

$rootReplies = [];
$childRepliesByParent = [];
foreach ($visibleReplies as $reply) {
    $parentId = (string)($reply['parentId'] ?? '');
    if ($parentId !== '' && isset($visibleRepliesById[$parentId])) {
        if (!isset($childRepliesByParent[$parentId])) {
            $childRepliesByParent[$parentId] = [];
        }
        $childRepliesByParent[$parentId][] = $reply;
    } else {
        $rootReplies[] = $reply;
    }
}

$renderReply = null;
$renderReply = function (array $reply, int $depth = 0) use (
    &$renderReply,
    $childRepliesByParent,
    $visibleRepliesById,
    $canCreateReply,
    $isLoggedIn,
    $canModerateReplies,
    $clientIp,
    $username,
    $replyEditTargetId,
    $editReplyBodyValue,
    $replyEditNotice,
    $postingRestricted,
    $postIdNoExt
): string {
    $replyUser = htmlspecialchars((string)$reply['username'], ENT_QUOTES, 'UTF-8');
    $replyDate = htmlspecialchars(fridg3_feed_humanize_datetime((string)$reply['date']), ENT_QUOTES, 'UTF-8');
    $replyBody = htmlspecialchars((string)$reply['body'], ENT_QUOTES, 'UTF-8');
    $replyId = (string)($reply['id'] ?? '');
    $isGuestReply = ($reply['isGuest'] ?? false) === true;
    $replyIp = (string)($reply['ip'] ?? '');
    $canManageThisReply = fridg3_feed_current_visitor_can_manage_reply($username, $reply, $clientIp);
    $guestFilteredEditLocked = !$isLoggedIn && $isGuestReply && fridg3_feed_guest_reply_has_filtered_text($reply);
    $canEditThisReply = $canManageThisReply && !$guestFilteredEditLocked;
    $isEditingReply = $canEditThisReply && $replyEditTargetId !== '' && $replyId === $replyEditTargetId;
    $replyActionsHtml = '';
    if ($replyId !== '' && ($canCreateReply || $canManageThisReply || (!empty($_SESSION['user']['isAdmin']) && $isGuestReply && $replyIp !== ''))) {
        $replyActionsHtml = '<span class="feed-reply-actions">';
        if ($canCreateReply) {
            $replyActionsHtml .= '<a class="feed-reply-action-link feed-reply-target-button" href="/feed/posts/' . rawurlencode((string)$postIdNoExt) . '?reply_to=' . rawurlencode($replyId) . '#feed-reply-form" data-feed-reply-to="' . htmlspecialchars($replyId, ENT_QUOTES, 'UTF-8') . '" data-feed-reply-user="' . htmlspecialchars((string)$reply['username'], ENT_QUOTES, 'UTF-8') . '" data-tooltip="reply to comment"><i class="fa-solid fa-reply"></i></a>';
        }
        if ($canEditThisReply) {
            $replyActionsHtml .= '<a class="feed-reply-action-link" href="/feed/posts/' . rawurlencode((string)$postIdNoExt) . '?edit_reply=' . rawurlencode($replyId) . '" data-tooltip="edit reply"><i class="fa-solid fa-pencil"></i></a>';
        }
        if (!empty($_SESSION['user']['isAdmin']) && $isGuestReply && $replyIp !== '') {
            $replyActionsHtml .= '<form class="feed-reply-delete-form" method="post" action="/feed/posts/' . rawurlencode((string)$postIdNoExt) . '" data-site-confirm="1" data-confirm-title="ban IP?" data-confirm-detail="this blocks new feed replies and guestbook posts from this IP." data-confirm-text="ban IP" data-cancel-text="cancel">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="reply_action" value="ban_ip">'
                . '<input type="hidden" name="reply_id" value="' . htmlspecialchars($replyId, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" class="feed-reply-action-button" data-tooltip="ban IP"><i class="fa-solid fa-ban"></i></button>'
                . '</form>'
                . '<form class="feed-reply-delete-form" method="post" action="/feed/posts/' . rawurlencode((string)$postIdNoExt) . '" data-site-confirm="1" data-admin-password-confirm="1" data-confirm-title="purge guest content from this IP?" data-confirm-detail="this deletes feed replies and guestbook posts from this IP. it does not ban or unban the IP." data-confirm-text="purge content" data-cancel-text="cancel" data-password-title="confirm guest purge" data-password-detail="enter your admin password to purge all guest content from this IP.">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="reply_action" value="purge_ip_replies">'
                . '<input type="hidden" name="reply_id" value="' . htmlspecialchars($replyId, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" class="feed-reply-action-button" data-tooltip="purge IP content"><i class="fa-solid fa-broom"></i></button>'
                . '</form>';
        }
        if ($canManageThisReply) {
            $replyActionsHtml .= '<form class="feed-reply-delete-form" method="post" action="/feed/posts/' . rawurlencode((string)$postIdNoExt) . '" data-site-confirm="1" data-confirm-title="delete reply?" data-confirm-detail="this removes the reply from this feed post." data-confirm-text="delete" data-cancel-text="cancel">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="reply_action" value="delete">'
            . '<input type="hidden" name="reply_id" value="' . htmlspecialchars($replyId, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="feed-reply-action-button" data-tooltip="delete reply"><i class="fa-solid fa-trash"></i></button>'
            . '</form>';
        }
        $replyActionsHtml .= '</span>';
    }

    $replyEditFormHtml = '';
    if ($isEditingReply) {
        $currentEditValue = $editReplyBodyValue !== '' ? $editReplyBodyValue : (string)$reply['body'];
        $replyEditFormHtml = '<div class="feed-reply-box feed-reply-edit-box">';
        if ($replyEditNotice !== '') {
            $replyEditFormHtml .= $replyEditNotice;
        }
        $replyEditFormHtml .= '<form method="post" enctype="multipart/form-data" action="/feed/posts/' . rawurlencode((string)$postIdNoExt) . '">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="reply_action" value="update">'
            . '<input type="hidden" name="reply_id" value="' . htmlspecialchars($replyId, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="bbcode-editor">'
            . '<div class="bbcode-toolbar">'
            . '<button type="button" class="bbcode-btn" data-tag="b" data-tooltip="bold"><i class="fa-solid fa-bold"></i></button>'
            . '<button type="button" class="bbcode-btn" data-tag="i" data-tooltip="italic"><i class="fa-solid fa-italic"></i></button>'
            . '<button type="button" class="bbcode-btn" data-tag="u" data-tooltip="underline"><i class="fa-solid fa-underline"></i></button>'
            . '<button type="button" class="bbcode-btn" data-tag="s" data-tooltip="strikethrough"><i class="fa-solid fa-strikethrough"></i></button>'
            . '<button type="button" id="bbcode-spoiler-btn" class="bbcode-btn" data-tooltip="spoiler"><i class="fa-solid fa-eye-slash"></i></button>'
            . '<button type="button" id="bbcode-color-btn" class="bbcode-btn" data-tooltip="color"><i class="fa-solid fa-palette"></i></button>'
            . '<input id="bbcode-color-input" type="color" style="display: none;">'
            . '<label for="bbcode-image-input">'
            . '<button type="button" id="bbcode-image-btn" class="bbcode-btn" data-tooltip="attach image"><i class="fa-solid fa-image"></i></button>'
            . '</label>'
            . ($isLoggedIn
                ? '<input id="bbcode-image-input" name="images[]" type="file" accept="image/*" multiple style="display: none;">'
                : '<input id="bbcode-image-input" type="file" accept="image/*" multiple disabled style="display: none;">')
            . '<button type="button" class="bbcode-btn" data-tag="code=python" data-tooltip="code block"><i class="fa-solid fa-code"></i></button>'
            . '<button type="button" id="bbcode-list-btn" class="bbcode-btn" data-tag="list" data-tooltip="list"><i class="fa-solid fa-list-ul"></i></button>'
            . ($isLoggedIn ? '<button type="button" id="bbcode-tooltip-btn" class="bbcode-btn" data-tooltip="tooltip"><i class="fa-solid fa-comment-dots"></i></button>' : '')
            . '<button type="button" id="bbcode-link-btn" class="bbcode-btn" data-tooltip="link"><i class="fa-solid fa-link"></i></button>'
            . ($isLoggedIn
                ? '<select id="bbcode-header-dropdown" class="bbcode-dropdown" data-tooltip="heading">'
                    . '<option value="">headings</option>'
                    . '<option value="h3">heading</option>'
                    . '<option value="h4">sub-heading</option>'
                    . '<option value="h5">caption</option>'
                    . '</select>'
                : '')
            . '<button type="button" id="bbcode-preview-toggle" class="bbcode-btn" data-tooltip="toggle preview"><i class="fa-solid fa-eye"></i></button>'
            . '</div>'
            . '<textarea id="bbcode-textbox" class="feed-reply-textbox" name="reply_content" maxlength="4000">' . htmlspecialchars($currentEditValue, ENT_QUOTES, 'UTF-8') . '</textarea>'
            . '<div id="bbcode-preview" style="display: none;"></div>'
            . '</div>'
            . '<button id="form-button" type="submit">save reply</button>'
            . '</form>'
            . '</div>';
        if ($postingRestricted) {
            $replyEditFormHtml = '<div class="feed-reply-box feed-reply-edit-box">'
                . fridg3_posting_restriction_notice()
                . fridg3_disable_composer_controls(substr($replyEditFormHtml, strlen('<div class="feed-reply-box feed-reply-edit-box">')));
        }
    }
    $replyUserHtml = $isGuestReply
        ? '<em>' . $replyUser . '</em>'
        : '@' . $replyUser;
    if ($canModerateReplies && $isGuestReply && $replyIp !== '') {
        $replyUserHtml .= ' <span class="feed-reply-ip" style="color: var(--subtle);">(' . htmlspecialchars($replyIp, ENT_QUOTES, 'UTF-8') . ')</span>';
        $replyIpBanned = fridg3_feed_is_ip_banned($replyIp);
        if ($replyIpBanned) {
            $replyUserHtml .= ' <span class="feed-reply-ip" style="color: var(--subtle);">(banned)</span>';
        }
    }
    $parentReferenceHtml = '';
    $parentId = (string)($reply['parentId'] ?? '');
    if ($parentId !== '' && isset($visibleRepliesById[$parentId])) {
        $parentReply = $visibleRepliesById[$parentId];
        $parentUser = trim((string)($parentReply['username'] ?? ''));
        if ($parentUser === '') {
            $parentUser = 'Anonymous';
        }
        $parentReferenceHtml = '<div class="feed-reply-parent">replying to <a href="#reply-' . htmlspecialchars($parentId, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($parentUser, ENT_QUOTES, 'UTF-8') . '</a></div>';
    }
    $replyClasses = 'feed-reply' . ($depth > 0 ? ' feed-reply-child' : '');
    $replyAnchorId = $replyId !== '' ? ' id="reply-' . htmlspecialchars($replyId, ENT_QUOTES, 'UTF-8') . '"' : '';
    $replyHtml = '<div class="' . $replyClasses . '"' . $replyAnchorId . '>'
        . '<div class="feed-reply-header">'
        . '<span class="feed-reply-username">' . $replyUserHtml . '</span>'
        . '<span class="feed-reply-date">' . $replyDate . $replyActionsHtml . '</span>'
        . '</div>'
        . $parentReferenceHtml
        . '<div class="post-content feed-reply-body">' . $replyBody . '</div>'
        . $replyEditFormHtml
        . '</div>';

    foreach ($childRepliesByParent[$replyId] ?? [] as $childReply) {
        $replyHtml .= $renderReply($childReply, $depth + 1);
    }

    return $replyHtml;
};

$repliesHtml = '';
foreach ($rootReplies as $reply) {
    $repliesHtml .= $renderReply($reply, 0);
}
$repliesSectionHtml = '';
if ($repliesHtml !== '') {
    $repliesSectionHtml = '<div class="feed-replies-shell">'
        . '<h2>comments</h2>'
        . '<div class="feed-replies-list">' . $repliesHtml . '</div>'
        . '</div>'
        . '<br>';
}
$content = str_replace('{replies_section}', $repliesSectionHtml, $content);

$replyBoxHtml = '';
if ($replyFormHtml !== '' && $replyEditTargetId === '') {
    $replyBoxHtml = '<div class="feed-reply-box">';
    if ($replyNotice !== '') {
        $replyBoxHtml .= $replyNotice;
    }
    $replyBoxHtml .= $replyFormHtml . '</div>';
} elseif ($replyEditTargetId === '' && $replyNotice !== '') {
    $replyBoxHtml = '<div class="feed-reply-box">' . $replyNotice . '</div>';
}
$content = str_replace('{reply_box}', $replyBoxHtml, $content);
if ($replyFormValue !== '') {
    $content = str_replace('{reply_form_value}', $replyFormValue, $content);
} else {
    $content = str_replace('{reply_form_value}', '', $content);
}

// Add edit button to header if allowed
$html = str_replace('{content}', $content, $template);
$html = str_replace('{title}', $title, $html);
$html = str_replace('{description}', $description, $html);
echo $html;
?>
