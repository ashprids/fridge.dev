<?php
declare(strict_types=1);

$sessionBootstrapDir = __DIR__;
while (!file_exists($sessionBootstrapDir . '/lib/session.php') && dirname($sessionBootstrapDir) !== $sessionBootstrapDir) {
    $sessionBootstrapDir = dirname($sessionBootstrapDir);
}
require_once $sessionBootstrapDir . '/lib/session.php';
require_once $sessionBootstrapDir . '/lib/feed.php';
require_once $sessionBootstrapDir . '/lib/render.php';
fridg3_start_session();

$title = 'website commission';
$description = 'submit a website commission request to fridge.dev.';
$commissionDataDir = $sessionBootstrapDir . '/data/website-commissions';
$rateLimitPath = $commissionDataDir . '/rate_limits.json';
$commissionSettingsPath = $sessionBootstrapDir . '/data/etc/website-commissions.json';

const COMMISSION_NOTIFY_CHANNEL_ID = '1547229814321188995';
const COMMISSION_COOLDOWN_SECONDS = 172800;

const COMMISSION_CONTACT_METHODS = [
    'email' => 'Email',
    'fridge_chat' => 'fridge.dev private chat',
    'discord' => 'Discord',
    'whatsapp' => 'WhatsApp',
    'other' => 'Other',
];

const COMMISSION_WEBSITE_TYPES = [
    'landing_page' => 'Landing page (social media links and contact information) only',
    'image_portfolio' => 'Image-based portfolio (artists, photographers, etc.)',
    'product_showcase' => 'Product showcase (developers, music producers, etc.)',
    'small_business' => 'Small business site (cafes, local services, tradespeople, etc.)',
    'content_site' => 'Content site with posts and updates (blogs, announcements, projects, etc.)',
    'community_hub' => 'Community hub (small communities, threads, publications, etc.)',
    'resource_download' => 'Resource/download sites (archives, downloads, assets, music, etc.)',
    'digital_goods' => 'Digital goods (marketplace, listings, etc.)',
    'not_sure' => 'Not sure/need help deciding',
    'other' => 'Other',
];

const COMMISSION_PAYMENT_METHODS = [
    'bank_transfer' => 'Bank transfer',
    'paypal' => 'PayPal',
    'crypto' => 'Crypto',
    'other' => 'Other',
];

function commission_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function commission_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function commission_find_template_file(string $filename): ?string
{
    $dir = __DIR__;
    while (true) {
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) return $path;
        $parent = dirname($dir);
        if ($parent === $dir) return null;
        $dir = $parent;
    }
}

function commission_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) return $fallback;
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function commission_write_json(string $path, array $data): bool
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) return false;
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true)) return false;
    $temporary = tempnam($directory, 'commission_');
    if ($temporary === false) return false;
    $ok = @file_put_contents($temporary, $encoded, LOCK_EX) !== false;
    if ($ok) @chmod($temporary, 0640);
    $ok = $ok && @rename($temporary, $path);
    if (!$ok) @unlink($temporary);
    return $ok;
}

function commission_is_open(string $path): bool
{
    $settings = commission_read_json($path, ['open' => true]);
    if (!array_key_exists('open', $settings)) return true;
    $open = filter_var($settings['open'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $open ?? true;
}

function commission_admin_control(string $csrfToken, bool $commissionsOpen): string
{
    if (empty($_SESSION['user']['isAdmin'])) return '';
    return '<style>.commission-admin-toggle{max-width:760px;margin-bottom:18px}.commission-admin-toggle .checkbox-label{margin:0}</style>'
        . '<form class="form-card commission-admin-toggle" method="post" action="/others/fridge-builds-websites/submit" data-no-spa="1">'
        . '<input type="hidden" name="csrf" value="' . commission_h($csrfToken) . '">'
        . '<input type="hidden" name="action" value="set_commissions_open">'
        . '<label class="checkbox-label"><input class="checkbox" type="checkbox" name="commissions_open" value="1"'
        . ($commissionsOpen ? ' checked' : '') . ' onchange="this.form.submit()"><span>commissions open</span></label>'
        . '</form>';
}

function commission_create_csrf_token(): string
{
    if (empty($_SESSION['commission_csrf']) || !is_string($_SESSION['commission_csrf'])) {
        $_SESSION['commission_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['commission_csrf'];
}

function commission_create_challenge(): array
{
    $left = random_int(1, 12);
    $right = random_int(1, 12);
    $operators = ['+', '-', '*'];
    $operator = $operators[array_rand($operators)];
    if ($operator === '-' && $right > $left) [$left, $right] = [$right, $left];
    $answer = match ($operator) {
        '+' => $left + $right,
        '-' => $left - $right,
        default => $left * $right,
    };
    $_SESSION['commission_challenge_answer'] = $answer;
    return ['question' => 'what is ' . $left . ' ' . $operator . ' ' . $right . '?'];
}

function commission_client_ip(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function commission_rate_key(): string
{
    return hash('sha256', commission_client_ip());
}

function commission_cooldown_remaining(string $path): int
{
    $limits = commission_read_json($path);
    $timestamps = $limits[commission_rate_key()] ?? [];
    $latest = is_array($timestamps) && $timestamps !== [] ? max(array_map('intval', $timestamps)) : 0;
    return max(0, $latest + COMMISSION_COOLDOWN_SECONDS - time());
}

function commission_claim_cooldown(string $path): int
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true)) return -1;
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        return -1;
    }

    $now = time();
    $limits = commission_read_json($path);
    foreach ($limits as $key => $timestamps) {
        $latest = is_array($timestamps) && $timestamps !== [] ? max(array_map('intval', $timestamps)) : 0;
        if ($latest < 1 || $latest + COMMISSION_COOLDOWN_SECONDS <= $now) unset($limits[$key]);
        else $limits[$key] = [$latest];
    }

    $key = commission_rate_key();
    $latest = isset($limits[$key][0]) ? (int)$limits[$key][0] : 0;
    $remaining = max(0, $latest + COMMISSION_COOLDOWN_SECONDS - $now);
    if ($remaining === 0) {
        $limits[$key] = [$now];
        if (!commission_write_json($path, $limits)) $remaining = -1;
    } else {
        commission_write_json($path, $limits);
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    return $remaining;
}

function commission_format_cooldown(int $seconds): string
{
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function commission_generate_id(string $directory): string
{
    do {
        $id = gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
    } while (is_file($directory . '/' . $id . '.json'));
    return $id;
}

function commission_count_links(string $text): int
{
    preg_match_all('/https?:\/\/|www\.|[a-z0-9.-]+\.[a-z]{2,}/i', $text, $matches);
    return count($matches[0] ?? []);
}

function commission_notify_toast(array $submission): ?string
{
    $payload = [
        'channel_id' => COMMISSION_NOTIFY_CHANNEL_ID,
        'id' => (string)$submission['id'],
        'name' => (string)$submission['name'],
        'email' => (string)$submission['email'],
        'contact_method' => (string)$submission['contactMethod'],
        'website_types' => (array)$submission['websiteTypes'],
        'description' => (string)$submission['description'],
        'budget' => (string)$submission['budget'],
        'payment_method' => (string)$submission['paymentMethod'],
        'signature' => (string)$submission['signature'],
        'terms_agreed' => true,
        'created_at' => (int)$submission['createdAt'],
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) return 'could not encode the Toast notification';
    $responseRaw = false;
    $httpCode = 0;
    $transportError = '';

    if (function_exists('curl_init')) {
        $curl = curl_init('http://127.0.0.1:8765/commission/notify');
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
        $responseRaw = curl_exec($curl);
        if ($responseRaw === false) $transportError = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
    } else {
        $http_response_header = [];
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $encoded,
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $responseRaw = @file_get_contents('http://127.0.0.1:8765/commission/notify', false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $status)) $httpCode = (int)$status[1];
        if ($responseRaw === false) $transportError = 'could not contact Toast';
    }

    if ($transportError !== '') return $transportError;
    $decoded = json_decode((string)$responseRaw, true);
    if ($httpCode >= 400 || !is_array($decoded) || empty($decoded['ok'])) {
        return is_array($decoded) && !empty($decoded['error']) ? (string)$decoded['error'] : 'unknown Toast response';
    }
    return null;
}

function commission_render_page(string $title, string $description, string $content): void
{
    $templateName = get_preferred_template_name(__DIR__);
    $templatePath = commission_find_template_file($templateName);
    if (!$templatePath && $templateName !== 'template.html') $templatePath = commission_find_template_file('template.html');
    if (!$templatePath) die('page template not found. report this issue to ashton@fridge.dev.');
    $html = apply_preferred_theme_stylesheet((string)file_get_contents($templatePath), __DIR__);
    $greeting = '';
    if (isset($_SESSION['user']['name'])) {
        $greeting = '<div id="user-greeting">Hello, ' . commission_h((string)$_SESSION['user']['name']) . '!</div>';
        $html = str_replace(
            '<a href="/account"><div id="footer-button" data-tooltip="access your fridge.dev account"><i class="fa-solid fa-user"></i></div></a>',
            '<a href="/account/logout"><div id="footer-button" data-tooltip="log out"><i class="fa-solid fa-right-from-bracket"></i></div></a>',
            $html
        );
    }
    echo str_replace(
        ['{content}', '{title}', '{description}', '{user_greeting}'],
        [$content, $title, $description, $greeting],
        $html
    );
}

fridg3_feed_refresh_session_user();
fridg3_refresh_current_user_posting_restriction();
$csrfToken = commission_create_csrf_token();
$isAdmin = !empty($_SESSION['user']['isAdmin']);
$commissionsOpen = commission_is_open($commissionSettingsPath);
$settingsError = '';
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'POST' && commission_post_string('action') === 'set_commissions_open') {
    if (!$isAdmin) {
        http_response_code(403);
        $settingsError = 'only administrators can change commission availability.';
    } elseif (!hash_equals($csrfToken, commission_post_string('csrf'))) {
        http_response_code(403);
        $settingsError = 'invalid request token. refresh and try again.';
    } else {
        $newState = isset($_POST['commissions_open']);
        $settings = [
            'open' => $newState,
            'updatedAt' => time(),
            'updatedBy' => (string)($_SESSION['user']['username'] ?? ''),
        ];
        if (!commission_write_json($commissionSettingsPath, $settings)) {
            $settingsError = 'commission availability could not be saved.';
        } else {
            header('Location: /others/fridge-builds-websites/submit');
            exit;
        }
        $commissionsOpen = commission_is_open($commissionSettingsPath);
    }
}

$adminControl = commission_admin_control($csrfToken, $commissionsOpen);
if (!$commissionsOpen) {
    $closedNotice = '<p>Sorry, but commissions are currently closed. Come back later!</p>';
    if ($settingsError !== '') $closedNotice = '<div id="error">' . commission_h($settingsError) . '</div><br>' . $closedNotice;
    commission_render_page($title, $description, $adminControl . $closedNotice);
    exit;
}

$postingRestricted = fridg3_current_user_posting_restricted();
$ipBanned = fridg3_feed_is_ip_banned(fridg3_feed_client_ip());
$submissionBlocked = $postingRestricted || $ipBanned;
$errors = [];
$values = [
    'name' => '',
    'email' => '',
    'contact_method' => '',
    'contact_other' => '',
    'website_types' => [],
    'website_other' => '',
    'description' => '',
    'has_budget' => '',
    'budget' => '',
    'payment_method' => '',
    'payment_other' => '',
    'terms_agreed' => '',
    'signature' => '',
];

if ($requestMethod === 'POST' && commission_post_string('action') === '') {
    foreach (array_keys($values) as $key) {
        if ($key === 'website_types') continue;
        $values[$key] = commission_post_string($key);
    }
    $postedTypes = $_POST['website_types'] ?? [];
    $values['website_types'] = is_array($postedTypes)
        ? array_values(array_unique(array_map('trim', array_filter($postedTypes, 'is_string'))))
        : [];

    if ($submissionBlocked) $errors[] = $postingRestricted ? 'your account has been restricted.' : 'your IP address has been restricted.';
    if (!hash_equals($csrfToken, commission_post_string('csrf'))) $errors[] = 'invalid request token. refresh and try again.';
    if (commission_post_string('website') !== '') $errors[] = 'spam check failed.';
    $startedAt = (int)($_POST['started_at'] ?? 0);
    if ($startedAt < 1 || time() - $startedAt < 4) $errors[] = 'that was suspiciously fast. wait a second and try again.';
    $answer = commission_post_string('security_answer');
    if ($answer === '' || !isset($_SESSION['commission_challenge_answer']) || (int)$answer !== (int)$_SESSION['commission_challenge_answer']) {
        $errors[] = 'security answer was wrong.';
    }
    if ($values['name'] === '' || strlen($values['name']) > 120) $errors[] = 'name is required and must be 120 characters or less.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 254) $errors[] = 'enter a valid email address.';
    if (!isset(COMMISSION_CONTACT_METHODS[$values['contact_method']])) $errors[] = 'choose a preferred contact method.';
    if ($values['contact_method'] === 'other' && ($values['contact_other'] === '' || strlen($values['contact_other']) > 160)) {
        $errors[] = 'enter your preferred contact method.';
    }
    $invalidTypes = array_diff($values['website_types'], array_keys(COMMISSION_WEBSITE_TYPES));
    if ($values['website_types'] === [] || $invalidTypes !== []) $errors[] = 'choose at least one valid website type.';
    if (in_array('other', $values['website_types'], true) && ($values['website_other'] === '' || strlen($values['website_other']) > 200)) {
        $errors[] = 'describe the other type of website.';
    }
    if ($values['description'] === '' || strlen($values['description']) > 3000) $errors[] = 'description is required and must be 3000 characters or less.';
    if (commission_count_links($values['description']) > 5) $errors[] = 'the description contains too many links.';
    if (!in_array($values['has_budget'], ['yes', 'no'], true)) $errors[] = 'choose whether you have a budget.';
    if ($values['has_budget'] === 'yes' && ($values['budget'] === '' || strlen($values['budget']) > 160)) $errors[] = 'enter your budget and currency.';
    if (!isset(COMMISSION_PAYMENT_METHODS[$values['payment_method']])) $errors[] = 'choose a preferred payment method.';
    if ($values['payment_method'] === 'other' && ($values['payment_other'] === '' || strlen($values['payment_other']) > 160)) {
        $errors[] = 'enter your preferred payment method.';
    }
    if ($values['terms_agreed'] !== 'yes') $errors[] = 'you must agree to the commission terms before submitting.';
    if ($values['terms_agreed'] === 'yes' && ($values['signature'] === '' || strlen($values['signature']) > 120)) {
        $errors[] = 'a signature is required and must be 120 characters or less.';
    }

    if ($errors === []) {
        if (!commission_is_open($commissionSettingsPath)) {
            $errors[] = 'commissions are currently closed.';
        }
    }

    if ($errors === []) {
        $remaining = commission_claim_cooldown($rateLimitPath);
        if ($remaining > 0) $errors[] = 'please wait ' . commission_format_cooldown($remaining) . ' before sending another commission request.';
        elseif ($remaining < 0) $errors[] = 'commission cooldown storage is unavailable. please try again later.';
    }

    if ($errors === []) {
        $id = commission_generate_id($commissionDataDir);
        $websiteTypes = array_map(static fn(string $key): string => COMMISSION_WEBSITE_TYPES[$key], $values['website_types']);
        if (in_array('other', $values['website_types'], true)) {
            $otherIndex = array_search('Other', $websiteTypes, true);
            if ($otherIndex !== false) $websiteTypes[$otherIndex] = 'Other: ' . $values['website_other'];
        }
        $contactMethod = COMMISSION_CONTACT_METHODS[$values['contact_method']];
        if ($values['contact_method'] === 'other') $contactMethod .= ': ' . $values['contact_other'];
        $paymentMethod = COMMISSION_PAYMENT_METHODS[$values['payment_method']];
        if ($values['payment_method'] === 'other') $paymentMethod .= ': ' . $values['payment_other'];
        $budget = $values['has_budget'] === 'yes' ? 'Yes: ' . $values['budget'] : 'No stated budget';
        $clientIp = commission_client_ip();
        $submission = [
            'id' => $id,
            'createdAt' => time(),
            'ip' => filter_var($clientIp, FILTER_VALIDATE_IP) ? $clientIp : 'unknown',
            'ipHash' => commission_rate_key(),
            'userAgent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'name' => $values['name'],
            'email' => $values['email'],
            'contactMethod' => $contactMethod,
            'websiteTypes' => $websiteTypes,
            'description' => $values['description'],
            'budget' => $budget,
            'paymentMethod' => $paymentMethod,
            'termsAgreed' => true,
            'signature' => $values['signature'],
            'notifyChannelId' => COMMISSION_NOTIFY_CHANNEL_ID,
            'notifyError' => '',
        ];
        $submissionPath = $commissionDataDir . '/' . $id . '.json';
        if (!commission_write_json($submissionPath, $submission)) {
            $errors[] = 'failed to save the commission request. please try again later.';
        } else {
            $notifyError = commission_notify_toast($submission);
            if ($notifyError !== null) {
                $submission['notifyError'] = $notifyError;
                commission_write_json($submissionPath, $submission);
                error_log('website commission ' . $id . ' Toast notification failed: ' . $notifyError);
            }
            unset($_SESSION['commission_challenge_answer']);
            header('Location: /others/fridge-builds-websites/submit?sent=1');
            exit;
        }
    }
}

// A commission can be closed while someone has an already-open form, so refresh
// the setting before rendering any validation response as well as before saving.
$commissionsOpen = commission_is_open($commissionSettingsPath);
if (!$commissionsOpen) {
    commission_render_page(
        $title,
        $description,
        commission_admin_control($csrfToken, false)
            . '<p>Sorry, but commissions are currently closed. Come back later!</p>'
    );
    exit;
}

$challenge = commission_create_challenge();
$cooldownRemaining = commission_cooldown_remaining($rateLimitPath);
$serverDisabled = $submissionBlocked || $cooldownRemaining > 0;
$termsAccepted = $values['terms_agreed'] === 'yes';
$hasSignature = $values['signature'] !== '';
$submitDisabled = $serverDisabled || !$termsAccepted || !$hasSignature;
$submitText = $cooldownRemaining > 0 ? commission_format_cooldown($cooldownRemaining) : 'submit';
$submitTooltip = $cooldownRemaining > 0
    ? 'all users are placed on a 48 hour cooldown to prevent spam'
    : ($termsAccepted && $hasSignature ? 'submit this website commission request' : 'agree to and sign the commission terms to submit');
$submitAttributes = ' data-server-disabled="' . ($serverDisabled ? '1' : '0') . '"';
if ($cooldownRemaining > 0) $submitAttributes .= ' data-commission-cooldown-until="' . (time() + $cooldownRemaining) . '"';
if ($submitDisabled) $submitAttributes .= ' disabled aria-disabled="true" class="form-button-disabled"';

$notice = '';
if (isset($_GET['sent'])) {
    $popup = 'your website commission request has been submitted. i\'ll contact you using your preferred method as soon as i can.';
    $notice = '<scr' . 'ipt>(function(){function showCommissionSuccess(){'
        . 'if(typeof window.showSitePopup!=="function"){window.setTimeout(showCommissionSuccess,50);return;}'
        . 'window.showSitePopup({title:"commission submitted",html:' . json_encode($popup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ',okText:"ok"});}'
        . 'if(document.readyState==="loading"){window.addEventListener("DOMContentLoaded",showCommissionSuccess,{once:true});}else{showCommissionSuccess();}})();</scr' . 'ipt>';
}
if ($settingsError !== '') $notice = '<div id="error">' . commission_h($settingsError) . '</div><br>';
if ($errors !== []) $notice = '<div id="error">' . commission_h(implode(' ', $errors)) . '</div><br>';

$contentPath = __DIR__ . '/content.html';
if (!is_file($contentPath)) die('content.html not found. report this issue to ashton@fridge.dev.');
$content = (string)file_get_contents($contentPath);
$replacements = [
    '{admin_control}' => $adminControl,
    '{notice}' => $notice,
    '{csrf}' => commission_h($csrfToken),
    '{started_at}' => (string)time(),
    '{name}' => commission_h($values['name']),
    '{email}' => commission_h($values['email']),
    '{contact_other}' => commission_h($values['contact_other']),
    '{website_other}' => commission_h($values['website_other']),
    '{project_description}' => commission_h($values['description']),
    '{budget}' => commission_h($values['budget']),
    '{payment_other}' => commission_h($values['payment_other']),
    '{signature}' => commission_h($values['signature']),
    '{signature_status_hidden}' => $hasSignature ? '' : ' hidden',
    '{security_question}' => commission_h($challenge['question']),
    '{submit_button_tooltip}' => commission_h($submitTooltip),
    '{submit_button_attributes}' => $submitAttributes,
    '{submit_button_text}' => commission_h($submitText),
    '{contact_other_hidden}' => $values['contact_method'] === 'other' ? '' : ' hidden',
    '{website_other_hidden}' => in_array('other', $values['website_types'], true) ? '' : ' hidden',
    '{budget_hidden}' => $values['has_budget'] === 'yes' ? '' : ' hidden',
    '{payment_other_hidden}' => $values['payment_method'] === 'other' ? '' : ' hidden',
];
foreach (array_keys(COMMISSION_CONTACT_METHODS) as $key) {
    $replacements['{contact_method_' . $key . '_checked}'] = $values['contact_method'] === $key ? ' checked' : '';
}
foreach (array_keys(COMMISSION_WEBSITE_TYPES) as $key) {
    $replacements['{website_type_' . $key . '_checked}'] = in_array($key, $values['website_types'], true) ? ' checked' : '';
}
foreach (array_keys(COMMISSION_PAYMENT_METHODS) as $key) {
    $replacements['{payment_method_' . $key . '_checked}'] = $values['payment_method'] === $key ? ' checked' : '';
}
$replacements['{has_budget_yes_checked}'] = $values['has_budget'] === 'yes' ? ' checked' : '';
$replacements['{has_budget_no_checked}'] = $values['has_budget'] === 'no' ? ' checked' : '';
$replacements['{terms_agreed_yes_checked}'] = $termsAccepted ? ' checked' : '';
$replacements['{terms_agreed_no_checked}'] = $values['terms_agreed'] === 'no' ? ' checked' : '';
$content = strtr($content, $replacements);

if ($submissionBlocked) {
    $restriction = $postingRestricted ? fridg3_posting_restriction_notice() : '<p class="posting-restriction-message">your IP address has been restricted.</p>';
    $content = $restriction . fridg3_disable_composer_controls($content);
}

fridg3_debug_log('[PHP] website commission form initialized');
commission_render_page($title, $description, $content);
