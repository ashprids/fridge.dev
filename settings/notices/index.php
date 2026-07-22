<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/session.php';
fridg3_start_session();
require_once dirname(__DIR__, 2) . '/account/admin/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/render.php';
require_once dirname(__DIR__, 2) . '/lib/site-notices.php';
account_admin_require_admin();

if (empty($_SESSION['site_notices_csrf']) || !is_string($_SESSION['site_notices_csrf'])) {
    $_SESSION['site_notices_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['site_notices_csrf'];
$notice = '';
$notices = fridg3_site_notices_load(__DIR__);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $scope = (string)($_POST['scope'] ?? 'global');
    $audience = is_string($_POST['audience'] ?? null) ? (string)$_POST['audience'] : '';
    $pageAudiences = array_values(array_unique(array_filter(
        is_array($_POST['audiences'] ?? null) ? $_POST['audiences'] : [],
        static fn($item) => in_array($item, ['users', 'guests'], true)
    )));
    $type = (string)($_POST['type'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $notice = '<div id="error">invalid request token. refresh and try again.</div><br>';
    } elseif (!in_array($scope, ['global', 'page'], true)
        || ($scope === 'global' && !in_array($audience, ['users', 'guests'], true))
        || ($scope === 'page' && $pageAudiences === [])
        || !in_array($type, ['banner', 'popup'], true) || !in_array($action, ['save', 'clear'], true)) {
        $notice = '<div id="error">invalid notice request.</div><br>';
    } else {
        $message = fridg3_site_notices_text($_POST['message'] ?? '', $type === 'banner' ? 1000 : 2000);
        $record = [
            'id' => bin2hex(random_bytes(16)), 'type' => $type, 'message' => $message,
        ];
        if ($type === 'banner') {
            $record['dismissible'] = !empty($_POST['dismissible']);
        } else {
            $label = fridg3_site_notices_text($_POST['button_label'] ?? '', 80);
            $url = fridg3_site_notices_url($_POST['button_url'] ?? '');
            if (($label === '') !== ($url === '')) {
                $notice = '<div id="error">the popup custom button needs both a label and a site-relative URL.</div><br>';
            }
            $record += [
                'title' => fridg3_site_notices_text($_POST['title'] ?? '', 120) ?: 'notice',
                'buttonLabel' => $label, 'buttonUrl' => $url,
            ];
        }
        if ($notice === '' && $scope === 'global') {
            $notices[$audience][$type] = $action === 'clear' || $message === '' ? null : $record;
        } elseif ($notice === '') {
            $recordId = (string)($_POST['notice_id'] ?? '');
            $path = fridg3_site_notices_page_path($_POST['page_path'] ?? '');
            $notices['pages'] = array_values(array_filter($notices['pages'] ?? [], static fn($item) => ($item['id'] ?? '') !== $recordId));
            if ($action === 'save') {
                if ($path === '' || $message === '') {
                    $notice = '<div id="error">page notices need a valid site path and message.</div><br>';
                } else {
                    $record['path'] = $path;
                    $record['audiences'] = $pageAudiences;
                    $notices['pages'] = array_values(array_filter($notices['pages'], static fn($item) =>
                        ($item['path'] ?? '') !== $path || ($item['type'] ?? '') !== $type
                    ));
                    $notices['pages'][] = $record;
                }
            }
        }
        if ($notice === '') {
            if (fridg3_site_notices_save(__DIR__, $notices)) {
                $notices = fridg3_site_notices_load(__DIR__);
                $notice = '<div id="result">' . ($action === 'clear' ? 'notice cleared.' : 'notice saved. visitors will see this new revision.') . '</div><br>';
            } else {
                $notice = '<div id="error">could not save notices. check data directory permissions.</div><br>';
            }
        }
    }
}

function notices_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function notices_fields(string $prefix, string $type, ?array $current): string
{
    $html = '<label for="' . $prefix . '-message">message</label><textarea id="' . $prefix . '-message" name="message" required maxlength="2000">' . notices_h($current['message'] ?? '') . '</textarea>'
        . '<div data-notice-options="banner"' . ($type !== 'banner' ? ' hidden' : '') . '><label class="checkbox-label"><input type="checkbox" class="checkbox" name="dismissible" value="1"' . (!empty($current['dismissible']) ? ' checked' : '') . '> allow visitors to dismiss this banner</label></div>'
        . '<div data-notice-options="popup"' . ($type !== 'popup' ? ' hidden' : '') . '><label for="' . $prefix . '-title">title</label><input id="' . $prefix . '-title" name="title" maxlength="120" value="' . notices_h($current['title'] ?? '') . '" placeholder="notice">'
        . '<p class="site-notices-editor-help">shown once per browser for this revision. custom buttons must link within fridge.dev.</p>'
            . '<label for="' . $prefix . '-button-label">custom button label <small>(optional)</small></label><input id="' . $prefix . '-button-label" name="button_label" maxlength="80" value="' . notices_h($current['buttonLabel'] ?? '') . '" placeholder="learn more">'
            . '<label for="' . $prefix . '-button-url">custom button URL <small>(optional)</small></label><input id="' . $prefix . '-button-url" name="button_url" maxlength="2000" value="' . notices_h($current['buttonUrl'] ?? '') . '" placeholder="/journal"></div>';
    return $html;
}

function notices_global_form(string $audience, string $type, ?array $current, string $csrf): string
{
    $prefix = $audience . '-' . $type;
    return '<details class="site-notices-editor-card"><summary>' . notices_h($type) . ($current ? ' <span class="site-notice-active">active</span>' : '') . '</summary>'
        . '<form method="post" action="/settings/notices/" data-no-spa="1"><input type="hidden" name="csrf_token" value="' . notices_h($csrf) . '"><input type="hidden" name="scope" value="global"><input type="hidden" name="audience" value="' . notices_h($audience) . '"><input type="hidden" name="type" value="' . notices_h($type) . '">'
        . notices_fields($prefix, $type, $current)
        . '<div class="site-notices-editor-actions"><button id="form-button" type="submit" name="action" value="save">save ' . notices_h($type) . '</button><button class="danger-button" type="submit" name="action" value="clear" formnovalidate>clear ' . notices_h($type) . '</button></div></form></details>';
}

function notices_page_form(?array $current, string $csrf, int $index): string
{
    $isExisting = $current !== null;
    $prefix = 'page-' . $index;
    $audiences = $current['audiences'] ?? (($current['audience'] ?? '') !== '' ? [$current['audience']] : ['users']);
    $type = (string)($current['type'] ?? 'banner');
    $audienceLabel = implode(' + ', array_map(static fn($item) => $item === 'users' ? 'logged-in users' : 'guests', $audiences));
    $summary = $isExisting ? notices_h(($current['path'] ?? '/') . ' · ' . $audienceLabel . ' · ' . $type) : 'add a page notice';
    $html = '<details class="site-notices-editor-card site-notices-page-card"><summary>' . $summary . '</summary><form method="post" action="/settings/notices/" data-no-spa="1">'
        . '<input type="hidden" name="csrf_token" value="' . notices_h($csrf) . '"><input type="hidden" name="scope" value="page"><input type="hidden" name="notice_id" value="' . notices_h($current['id'] ?? '') . '">'
        . '<label for="' . $prefix . '-path">page path</label><input id="' . $prefix . '-path" name="page_path" required value="' . notices_h($current['path'] ?? '') . '" placeholder="/feed">'
        . '<fieldset class="site-notices-audience-choices"><legend>audience</legend><label class="checkbox-label"><input type="checkbox" class="checkbox" name="audiences[]" value="users"' . (in_array('users', $audiences, true) ? ' checked' : '') . '> logged-in users</label><label class="checkbox-label"><input type="checkbox" class="checkbox" name="audiences[]" value="guests"' . (in_array('guests', $audiences, true) ? ' checked' : '') . '> guests</label></fieldset>'
        . '<label for="' . $prefix . '-type">notice type</label><select id="' . $prefix . '-type" name="type" data-page-notice-type><option value="banner"' . ($type === 'banner' ? ' selected' : '') . '>banner</option><option value="popup"' . ($type === 'popup' ? ' selected' : '') . '>popup</option></select>'
        . '<div data-page-notice-fields>' . notices_fields($prefix, $type, $current) . '</div>'
        . '<div class="site-notices-editor-actions"><button id="form-button" type="submit" name="action" value="save">' . ($isExisting ? 'save changes' : 'add notice') . '</button>'
        . ($isExisting ? '<button class="danger-button" type="submit" name="action" value="clear" formnovalidate>remove notice</button>' : '') . '</div></form></details>';
    return $html;
}

$content = '<style>.site-notices-editor{display:grid;gap:16px;max-width:820px}.site-notices-audience,.site-notices-pages{border:1px solid var(--border);padding:0 14px}.site-notices-audience>summary,.site-notices-pages>summary,.site-notices-editor-card>summary{cursor:pointer;padding:14px 4px;font-weight:bold}.site-notices-editor-card{border-top:1px solid var(--border)}.site-notices-editor-card form{padding:0 4px 16px}.site-notices-editor-card label{display:block;margin:12px 0 6px}.site-notices-editor-card input:not(.checkbox),.site-notices-editor-card textarea,.site-notices-editor-card select{box-sizing:border-box;width:100%;padding:8px;background:var(--bg);color:var(--fg);border:1px solid var(--border);font:inherit}.site-notices-editor-card textarea{min-height:110px;resize:vertical}.site-notices-editor-card .checkbox-label{display:flex;gap:8px;align-items:center}.site-notices-audience-choices{margin:14px 0 0;padding:8px 12px 12px;border:1px solid var(--border)}.site-notices-audience-choices .checkbox-label{margin:8px 0 0}.site-notices-editor-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.site-notices-editor-help{color:var(--subtle);font-size:.9em}.site-notice-active{color:var(--links);font-size:.8em;font-weight:normal}</style>'
    . '<h1>site notices</h1><h2>messages for visitors</h2>' . $notice
    . '<p>global notices appear throughout the site. Page notices override the matching global banner or popup on one exact page. All editor sections start collapsed.</p><div class="site-notices-editor">'
    . '<details class="site-notices-audience"><summary>logged-in users</summary>' . notices_global_form('users', 'banner', $notices['users']['banner'], $csrf) . notices_global_form('users', 'popup', $notices['users']['popup'], $csrf) . '</details>'
    . '<details class="site-notices-audience"><summary>guests</summary>' . notices_global_form('guests', 'banner', $notices['guests']['banner'], $csrf) . notices_global_form('guests', 'popup', $notices['guests']['popup'], $csrf) . '</details>'
    . '<details class="site-notices-pages"><summary>specific pages (' . count($notices['pages'] ?? []) . ')</summary>' . notices_page_form(null, $csrf, 0);
foreach (($notices['pages'] ?? []) as $index => $page) $content .= notices_page_form($page, $csrf, $index + 1);
$content .= '</details></div><script>(function(){document.querySelectorAll("[data-page-notice-type]").forEach(function(select){var sync=function(){var form=select.closest("form");if(!form)return;form.querySelectorAll("[data-notice-options]").forEach(function(box){box.hidden=box.getAttribute("data-notice-options")!==select.value;});};select.addEventListener("change",sync);sync();});})();</script>';

account_admin_render_page('site notices', 'create global and page-specific visitor notices.', $content);
