/* ==========================================================================
   Shared dialogs and notices
   ========================================================================== */

(() => {
    try {
        const identifier = localStorage.getItem('fridg3_hard_ban_id') || '';
        if (!/^[a-f0-9]{64}$/.test(identifier)) return;
        const cookieMatch = document.cookie.match(/(?:^|;\s*)fridg3_hard_ban_id=([a-f0-9]{64})(?:;|$)/);
        if (cookieMatch && cookieMatch[1] === identifier) return;

        const domain = location.hostname === 'fridge.dev' || location.hostname.endsWith('.fridge.dev')
            ? '; Domain=.fridge.dev'
            : '';
        document.cookie = `fridg3_hard_ban_id=${identifier}; Path=/; Max-Age=157680000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}${domain}`;
        if (sessionStorage.getItem('fridg3_hard_ban_cookie_synced') !== identifier) {
            sessionStorage.setItem('fridg3_hard_ban_cookie_synced', identifier);
            location.reload();
        }
    } catch (_error) {
        // Storage may be unavailable in privacy-restricted browser contexts.
    }
})();

let hostRedirectInProgress = false;

function siteEscapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function showSitePopup(options) {
    const config = options || {};
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'site-popup-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        const dialog = document.createElement('div');
        dialog.className = 'site-popup-dialog';

        const title = document.createElement('div');
        title.className = 'site-popup-title';
        title.textContent = config.title || 'notice';

        const detail = document.createElement('div');
        detail.className = 'site-popup-detail';
        if (config.html) {
            detail.innerHTML = config.html;
        } else {
            detail.textContent = config.detail || '';
        }

        let input = null;
        if (config.input === true) {
            input = document.createElement('input');
            input.className = 'site-popup-input';
            input.type = config.inputType || 'text';
            input.value = config.inputValue || '';
            input.placeholder = config.inputPlaceholder || '';
            input.autocomplete = 'off';
        }

        const noButtons = config.noButtons === true;
        const actions = document.createElement('div');
        actions.className = 'site-popup-actions';

        const cancelText = config.cancelText || '';
        let cancel = null;
        if (cancelText) {
            cancel = document.createElement('button');
            cancel.className = 'site-popup-button site-popup-cancel';
            cancel.type = 'button';
            cancel.textContent = cancelText;
            actions.append(cancel);
        }

        const customText = config.customText || '';
        let custom = null;
        if (customText) {
            custom = document.createElement('button');
            custom.className = 'site-popup-button site-popup-custom';
            custom.type = 'button';
            custom.textContent = customText;
            actions.append(custom);
        }

        let ok = null;
        if (!noButtons) {
            ok = document.createElement('button');
            ok.className = 'site-popup-button site-popup-ok';
            ok.type = 'button';
            ok.textContent = config.okText || 'ok';
            actions.append(ok);
        }

        dialog.append(title, detail);
        if (input) dialog.append(input);
        if (!noButtons) dialog.append(actions);
        overlay.append(dialog);

        const close = (value) => {
            document.removeEventListener('keydown', onKeydown);
            overlay.classList.add('is-closing');
            window.setTimeout(() => overlay.remove(), 160);
            resolve(value);
        };

        const onKeydown = (event) => {
            if (event.key === 'Escape') close(input ? null : false);
            if (event.key === 'Enter') close(input ? input.value : true);
        };

        if (cancel) cancel.addEventListener('click', () => close(input ? null : false));
        if (custom) custom.addEventListener('click', () => close('custom'));
        if (ok) ok.addEventListener('click', () => close(input ? input.value : true));
        if (!noButtons) {
            overlay.addEventListener('click', event => {
                if (event.target === overlay) close(input ? null : false);
            });
            document.addEventListener('keydown', onKeydown);
        }
        document.body.append(overlay);
        if (input || ok) (input || ok).focus();
        if (input) input.select();
    });
}

window.showSitePopup = showSitePopup;

function showSiteNotice(title, detail) {
    return showSitePopup({
        title: title || 'notice',
        detail: detail || '',
        okText: 'ok'
    });
}

function showSitePrompt(title, detail, value) {
    return showSitePopup({
        title: title || 'input',
        detail: detail || '',
        input: true,
        inputValue: value || '',
        okText: 'ok',
        cancelText: 'cancel'
    });
}

window.showSiteNotice = showSiteNotice;
window.showSitePrompt = showSitePrompt;

const fridg3DebugLogs = { client: [], server: [] };
let fridg3AccessLogs = [];
const FRIDG3_DEBUG_LOG_LIMIT = 1000;
const FRIDG3_ACCESS_LOG_LIMIT = 10000;
let fridg3ProcessLogTimer = null;
let fridg3AccessLogTimer = null;
let fridg3AccessLogRequestActive = false;
let fridg3ProcessLogCursor = { identity: '', offset: 0 };
let fridg3ProcessLogRequestActive = false;
let fridg3DebugEnabled = false;
let fridg3DebugStartupSeeded = false;
let fridg3DebugListenersActive = false;
let fridg3OriginalFetch = null;
let fridg3OriginalConsoleError = null;
let fridg3OriginalConsoleWarn = null;
let fridg3DebugHistoryRestored = false;
let fridg3ServerHistoryRestored = false;
let fridg3ServerDebugAuthorized = false;
let fridg3DebugPersistTimer = null;
const fridg3DeferredOutputUpdates = new Map();
let fridg3SelectionUpdateListenerBound = false;

function fridg3OutputHasActiveSelection(output) {
    const selection = window.getSelection ? window.getSelection() : null;
    if (!selection || selection.isCollapsed || selection.rangeCount === 0) return false;
    return output.contains(selection.anchorNode) || output.contains(selection.focusNode);
}

function fridg3FlushDeferredOutputUpdates() {
    fridg3DeferredOutputUpdates.forEach((update, output) => {
        if (fridg3OutputHasActiveSelection(output)) return;
        fridg3DeferredOutputUpdates.delete(output);
        update();
    });
    if (fridg3DeferredOutputUpdates.size === 0 && fridg3SelectionUpdateListenerBound) {
        document.removeEventListener('selectionchange', fridg3FlushDeferredOutputUpdates);
        fridg3SelectionUpdateListenerBound = false;
    }
}

function fridg3RunAfterOutputSelection(output, update) {
    if (!fridg3OutputHasActiveSelection(output)) {
        update();
        return;
    }
    fridg3DeferredOutputUpdates.set(output, update);
    if (!fridg3SelectionUpdateListenerBound) {
        document.addEventListener('selectionchange', fridg3FlushDeferredOutputUpdates);
        fridg3SelectionUpdateListenerBound = true;
    }
}

function fridg3PersistDebugHistory() {
    if (fridg3DebugPersistTimer) window.clearTimeout(fridg3DebugPersistTimer);
    fridg3DebugPersistTimer = null;
    try {
        sessionStorage.setItem('fridg3DebugClientHistory', JSON.stringify(fridg3DebugLogs.client));
        if (fridg3ServerDebugAuthorized) {
            sessionStorage.setItem('fridg3DebugServerHistory', JSON.stringify(fridg3DebugLogs.server));
        }
    } catch (_) { /* storage may be unavailable or full */ }
}

function fridg3ScheduleDebugHistoryPersist() {
    if (fridg3DebugPersistTimer) return;
    fridg3DebugPersistTimer = window.setTimeout(fridg3PersistDebugHistory, 100);
}

function fridg3ReadDebugHistory(key) {
    try {
        const parsed = JSON.parse(sessionStorage.getItem(key) || '[]');
        if (!Array.isArray(parsed)) return [];
        return parsed.filter(entry => entry && typeof entry.timestamp === 'string' && typeof entry.message === 'string')
            .slice(-FRIDG3_DEBUG_LOG_LIMIT)
            .map(entry => {
                entry.channel = key.includes('Server') ? 'server' : 'client';
                if (/^\[PHP\]\s+warning:/i.test(entry.message)) {
                    entry.isError = false;
                    entry.isWarning = true;
                }
                if (/^\[PHP\]\s+(?:loaded\s+|.*\brequest (?:initialized|completed)(?:\b|$))/i.test(entry.message)) {
                    entry.category = 'loaded';
                }
                return entry;
            });
    } catch (_) {
        return [];
    }
}

function fridg3RestoreClientDebugHistory() {
    if (fridg3DebugHistoryRestored) return;
    fridg3DebugHistoryRestored = true;
    fridg3DebugLogs.client.push(...fridg3ReadDebugHistory('fridg3DebugClientHistory'));
}

function fridg3RestoreServerDebugHistory() {
    if (fridg3ServerHistoryRestored) return;
    fridg3ServerHistoryRestored = true;
    const restored = fridg3ReadDebugHistory('fridg3DebugServerHistory');
    if (restored.length) {
        fridg3DebugLogs.server.unshift(...restored);
        if (fridg3DebugLogs.server.length > FRIDG3_DEBUG_LOG_LIMIT) {
            fridg3DebugLogs.server.splice(0, fridg3DebugLogs.server.length - FRIDG3_DEBUG_LOG_LIMIT);
        }
    }
}

function fridg3DebugAppend(channel, value, processLog = false) {
    if (!fridg3DebugEnabled) return;
    const target = channel === 'server' ? 'server' : 'client';
    const now = new Date();
    const timestamp = [now.getHours(), now.getMinutes(), now.getSeconds()]
        .map(part => String(part).padStart(2, '0'))
        .join(':');
    const message = typeof value === 'string' ? value : String(value);
    const networkStatusMatch = message.match(/^\[(?:network|upload)\]\s+[A-Z]+\s+\S+\s+(\d{3})$/);
    const networkStatus = networkStatusMatch ? Number(networkStatusMatch[1]) : 0;
    const explicitPhpWarning = /^\[PHP\]\s+warning:/i.test(message);
    const entry = {
        timestamp,
        message,
        processLog,
        channel: target,
        category: /^\[PHP\]\s+(?:loaded\s+|.*\brequest (?:initialized|completed)(?:\b|$))/i.test(message)
            ? 'loaded'
            : /^\[(?:network|upload)\](?:\s|$)/i.test(message)
            ? 'network'
            : /^\[settings\](?:\s|$)/i.test(message)
            || message === '[sidebar/player] sidebar and shared content initialized'
            ? 'settings'
            : '',
        isError: !explicitPhpWarning && (networkStatus >= 400 || /(?:\berror\b|\bfailed\b|\bfailure\b|\bfatal\b|\bexception\b|\brejected\b|\bblocked\b|\binvalid\b|\bunavailable\b|HTTP\s+[45]\d\d)/i.test(message)),
        isWarning: explicitPhpWarning || (networkStatus >= 300 && networkStatus < 400) || /(?:\bwarning\b|\bwarn(?:ed|ing)?\b)/i.test(message),
        isSuccess: (networkStatus >= 200 && networkStatus < 300) || /(?:SPA form submission completed:\s*\/(?:feed|journal)\/create\b|(?:post|data|media|image|attachment|paste|file)[^\n]*(?:upload(?:ed)?|created|queued|saved(?: successfully)?)|(?:upload|save)[^\n]*(?:completed|succeeded|successful|saved))/i.test(message),
    };
    fridg3DebugLogs[target].push(entry);
    const trimmed = fridg3DebugLogs[target].length > FRIDG3_DEBUG_LOG_LIMIT;
    if (trimmed) fridg3DebugLogs[target].splice(0, fridg3DebugLogs[target].length - FRIDG3_DEBUG_LOG_LIMIT);
    fridg3ScheduleDebugHistoryPersist();
    const output = target === 'server'
        ? document.querySelector('.debug-console-server-output')
        : document.querySelector('.debug-console-client-output');
    if (output) {
        if (trimmed || !fridg3DebugEntryVisible(entry) || !fridg3DebugSearchMatches(target, entry.message)) {
            fridg3RenderDebugOutput(output, fridg3DebugLogs[target]);
        } else {
            fridg3UpdateDebugOutputWithoutScrollJump(output, () => {
                output.append(fridg3CreateDebugLogLine(entry));
            });
        }
    }
}

function fridg3UpdateDebugOutputWithoutScrollJump(output, update) {
    const wasAtBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 20;
    const previousScrollTop = output.scrollTop;
    update();
    output.scrollTop = wasAtBottom ? output.scrollHeight : previousScrollTop;
}

function fridg3RenderDebugOutput(output, entries) {
    const channel = output.classList.contains('debug-console-server-output') ? 'server' : 'client';
    fridg3RunAfterOutputSelection(output, () => {
        fridg3UpdateDebugOutputWithoutScrollJump(output, () => {
            output.replaceChildren();
            entries
                .filter(entry => fridg3DebugEntryVisible(entry) && fridg3DebugSearchMatches(channel, entry.message))
                .forEach(entry => output.append(fridg3CreateDebugLogLine(entry)));
        });
    });
}

function fridg3DebugEntryVisible(entry) {
    if (entry.processLog) {
        const toggle = document.getElementById('debug-process-logs-toggle');
        if (toggle && !toggle.checked) return false;
    }
    if (entry.category === 'settings') {
        const toggle = document.getElementById('debug-settings-logs-toggle');
        if (toggle && !toggle.checked) return false;
    }
    if (entry.category === 'network') {
        const toggle = document.getElementById('debug-network-logs-toggle');
        if (toggle && !toggle.checked) return false;
    }
    if (entry.category === 'loaded') {
        const toggle = document.getElementById('debug-loaded-logs-toggle');
        if (toggle && !toggle.checked) return false;
    }
    const channel = entry.channel === 'server' || entry.processLog ? 'server' : 'client';
    if (entry.isError) {
        const toggle = document.getElementById(`debug-${channel}-errors-toggle`);
        if (toggle && !toggle.checked) return false;
    } else if (entry.isWarning) {
        const toggle = document.getElementById(`debug-${channel}-warnings-toggle`);
        if (toggle && !toggle.checked) return false;
    }
    return true;
}

function fridg3CreateDebugLogLine(entry) {
    const line = document.createElement('span');
    line.className = 'debug-log-entry';
    const timestamp = document.createElement('span');
    timestamp.className = 'debug-log-timestamp';
    timestamp.textContent = `[${entry.timestamp}]`;
    line.append(timestamp, document.createTextNode(' '));

    if (entry.processLog) {
        const processTag = document.createElement('span');
        processTag.className = 'debug-log-source';
        processTag.textContent = '[PROCESS]';
        line.append(processTag, document.createTextNode(' '));
    }

    const sourceMatch = entry.message.match(/^(\[[^\]]+\])(?:\s+|$)(.*)$/s);
    const message = document.createElement('span');
    message.className = 'debug-log-message';
    if (entry.isError) message.classList.add('is-error');
    else if (entry.isWarning) message.classList.add('is-warning');
    else if (entry.isSuccess) message.classList.add('is-success');
    if (sourceMatch) {
        const source = document.createElement('span');
        source.className = 'debug-log-source';
        source.textContent = sourceMatch[1];
        line.append(source, document.createTextNode(' '));
        message.textContent = sourceMatch[2];
    } else {
        message.textContent = entry.message;
    }
    line.append(message);
    fridg3HighlightDebugLine(line, entry.channel === 'server' || entry.processLog ? 'server' : 'client');
    return line;
}

function fridg3HighlightDebugLine(line, channel) {
    const input = document.querySelector(`[data-debug-search="${channel}"]`);
    const query = input ? input.value.trim() : '';
    if (!query) return;
    const lowerQuery = query.toLocaleLowerCase();
    const walker = document.createTreeWalker(line, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(node => {
        const text = node.nodeValue || '';
        const lowerText = text.toLocaleLowerCase();
        let cursor = 0;
        let match = lowerText.indexOf(lowerQuery);
        if (match === -1) return;
        const fragment = document.createDocumentFragment();
        while (match !== -1) {
            fragment.append(document.createTextNode(text.slice(cursor, match)));
            const mark = document.createElement('mark');
            mark.className = 'debug-log-highlight';
            mark.textContent = text.slice(match, match + query.length);
            fragment.append(mark);
            cursor = match + query.length;
            match = lowerText.indexOf(lowerQuery, cursor);
        }
        fragment.append(document.createTextNode(text.slice(cursor)));
        node.replaceWith(fragment);
    });
}

function fridg3DebugSearchMatches(channel, text) {
    const input = document.querySelector(`[data-debug-search="${channel}"]`);
    const query = input ? input.value.trim().toLocaleLowerCase() : '';
    return !query || String(text || '').toLocaleLowerCase().includes(query);
}

function fridg3EnsureDebugConsole() {
    let panel = document.getElementById('debug-console');
    if (panel) return panel;
    panel = document.createElement('aside');
    panel.id = 'debug-console';
    panel.hidden = true;
    panel.setAttribute('aria-label', 'debug console');
    panel.innerHTML = '<div class="debug-console-resize-handle" role="separator" tabindex="0" aria-label="resize debug console" aria-orientation="vertical"></div>'
        + '<div class="debug-console-inner"><div class="debug-console-tabs" role="tablist">'
        + '<button type="button" class="is-active" data-debug-tab="client" role="tab">client</button>'
        + '<button type="button" class="is-disabled" data-admin-debug-tab data-debug-tab="server" role="tab" aria-disabled="true" '
        + 'data-tooltip="These logs are unavailable to non-admins due to security concerns.">server</button>'
        + '<button type="button" class="is-disabled" data-admin-debug-tab data-debug-tab="access" role="tab" aria-disabled="true" hidden>access</button></div>'
        + '<div class="debug-console-client-panel is-active" data-debug-output="client" role="tabpanel">'
        + '<div class="checkbox-group debug-client-log-options"><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-settings-logs-toggle" checked>'
        + '<span>settings</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-network-logs-toggle" checked>'
        + '<span>network</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-client-warnings-toggle" checked>'
        + '<span>warnings</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-client-errors-toggle" checked>'
        + '<span>errors</span></label>'
        + '<button type="button" class="debug-log-clear-button" data-debug-clear="client" aria-label="clear client log" data-tooltip="clear client log">'
        + '<i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>'
        + '<div class="debug-log-search"><input type="search" data-debug-search="client" aria-label="search client log" placeholder="search client log"></div>'
        + '<pre class="debug-console-output debug-console-client-output"></pre></div>'
        + '<div class="debug-console-server-panel" data-debug-output="server" role="tabpanel">'
        + '<div class="checkbox-group debug-server-log-options" hidden><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-loaded-logs-toggle" checked>'
        + '<span>loaded</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-process-logs-toggle">'
        + '<span>process</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-server-warnings-toggle">'
        + '<span>warnings</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-server-errors-toggle">'
        + '<span>errors</span></label>'
        + '<button type="button" class="debug-log-clear-button" data-debug-clear="server" aria-label="clear server log" data-tooltip="clear server log">'
        + '<i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>'
        + '<div class="debug-log-search debug-admin-log-search" hidden><input type="search" data-debug-search="server" aria-label="search server log" placeholder="search server log"></div>'
        + '<span class="debug-process-log-status" hidden></span>'
        + '<pre class="debug-console-output debug-console-server-output"></pre></div>'
        + '<div class="debug-console-access-panel" data-debug-output="access" role="tabpanel">'
        + '<div class="checkbox-group debug-access-log-options" hidden><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-access-guests-toggle" checked><span>guests</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-access-users-toggle" checked><span>users</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-access-admins-toggle" checked><span>admins</span></label><label class="checkbox-label">'
        + '<input class="checkbox" type="checkbox" id="debug-access-hard-banned-toggle" checked><span>hard-banned</span></label>'
        + '<button type="button" class="debug-log-clear-button" data-debug-clear="access" aria-label="clear access log" data-tooltip="clear access log">'
        + '<i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>'
        + '<div class="debug-log-search debug-admin-log-search" hidden><input type="search" data-debug-search="access" aria-label="search access log" placeholder="search access log"></div>'
        + '<pre class="debug-console-output debug-console-access-output"></pre></div></div>';
    panel.addEventListener('click', event => {
        const button = event.target.closest('[data-debug-tab]');
        if (!button) return;
        if (button.getAttribute('aria-disabled') === 'true') return;
        fridg3SelectDebugTab(panel, button.dataset.debugTab, true);
    });
    document.body.append(panel);
    fridg3InitDebugConsoleResize(panel);
    if (typeof initTooltips === 'function') initTooltips();
    fridg3InitClientLogControls(panel);
    fridg3InitAccessLogControls(panel);
    fridg3InitDebugSearch(panel);
    fridg3InitDebugClearControls(panel);
    Object.keys(fridg3DebugLogs).forEach(channel => {
        const output = channel === 'server'
            ? panel.querySelector('.debug-console-server-output')
            : panel.querySelector('.debug-console-client-output');
        if (output) fridg3RenderDebugOutput(output, fridg3DebugLogs[channel]);
    });
    fridg3InitProcessLogControl(panel);
    return panel;
}

function fridg3InitAccessLogControls(panel) {
    [
        ['#debug-access-guests-toggle', 'debugIncludeAccessGuests'],
        ['#debug-access-users-toggle', 'debugIncludeAccessUsers'],
        ['#debug-access-admins-toggle', 'debugIncludeAccessAdmins'],
        ['#debug-access-hard-banned-toggle', 'debugIncludeAccessHardBanned'],
    ].forEach(([selector, storageKey]) => {
        const toggle = panel.querySelector(selector);
        if (!toggle) return;
        try { toggle.checked = localStorage.getItem(storageKey) !== 'false'; } catch (_) { /* ignore */ }
        toggle.addEventListener('change', () => {
            try { localStorage.setItem(storageKey, toggle.checked ? 'true' : 'false'); } catch (_) { /* ignore */ }
            fridg3RenderAccessLogs(fridg3AccessLogs);
        });
    });
    const output = panel.querySelector('.debug-console-access-output');
    if (output) {
        output.addEventListener('contextmenu', event => {
            const ipElement = event.target.closest('.debug-access-ip[data-access-ip]');
            if (!ipElement) return;
            event.preventDefault();
            fridg3OpenAccessIpMenu(ipElement, event.clientX, event.clientY);
        });
    }
}

function fridg3OpenAccessIpMenu(ipElement, clientX, clientY) {
    document.querySelectorAll('.debug-access-context-menu').forEach(menu => menu.remove());
    const ip = ipElement.dataset.accessIp || '';
    const hardBanned = ipElement.classList.contains('is-hard-banned');
    const action = hardBanned ? 'whitelist' : 'hard-ban';
    const menu = document.createElement('div');
    menu.className = 'debug-access-context-menu';
    menu.setAttribute('role', 'menu');
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('role', 'menuitem');
    button.innerHTML = hardBanned
        ? '<i class="fa-solid fa-check" aria-hidden="true"></i><span>whitelist IP</span>'
        : '<i class="fa-solid fa-ban" aria-hidden="true"></i><span>hard-ban IP</span>';
    menu.append(button);
    document.body.append(menu);

    const bounds = menu.getBoundingClientRect();
    menu.style.left = `${Math.max(8, Math.min(clientX, window.innerWidth - bounds.width - 8))}px`;
    menu.style.top = `${Math.max(8, Math.min(clientY, window.innerHeight - bounds.height - 8))}px`;

    const close = () => {
        menu.remove();
        document.removeEventListener('pointerdown', closeOnOutsideClick);
        document.removeEventListener('keydown', closeOnEscape);
    };
    const closeOnOutsideClick = event => {
        if (!menu.contains(event.target)) close();
    };
    const closeOnEscape = event => {
        if (event.key === 'Escape') close();
    };
    document.addEventListener('pointerdown', closeOnOutsideClick);
    document.addEventListener('keydown', closeOnEscape);
    button.addEventListener('click', async () => {
        close();
        const confirmed = await showSitePopup({
            title: hardBanned ? `whitelist ${ip}?` : `hard-ban ${ip}?`,
            detail: hardBanned
                ? 'this IP will bypass manual hard bans, source banlists, and identity-based hard bans.'
                : 'this IP will be added to the custom hard-ban list.',
            okText: hardBanned ? 'whitelist IP' : 'hard-ban IP',
            cancelText: 'cancel',
        });
        if (!confirmed) return;
        try {
            const params = new URLSearchParams({ ip });
            const response = await fetch('/api/debug-access-logs', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Fridg3-Debug-Action': action,
                },
                body: params.toString(),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'hard-ban update failed');
            fridg3AccessLogs.forEach(entry => {
                if (entry.ip === ip) entry.hardBanned = data.hardBanned === true;
            });
            fridg3RenderAccessLogs(fridg3AccessLogs);
        } catch (_) {
            await showSiteNotice('unable to update hard bans', `the hard-ban state for ${ip} could not be saved.`);
        }
    });
    button.focus();
}

function fridg3InitDebugSearch(panel) {
    panel.querySelectorAll('[data-debug-search]').forEach(input => {
        const channel = input.dataset.debugSearch;
        try { input.value = sessionStorage.getItem(`fridg3DebugSearch:${channel}`) || ''; } catch (_) { /* ignore */ }
        input.addEventListener('input', () => {
            try { sessionStorage.setItem(`fridg3DebugSearch:${channel}`, input.value); } catch (_) { /* ignore */ }
            if (channel === 'access') {
                fridg3RenderAccessLogs(fridg3AccessLogs);
                return;
            }
            const output = panel.querySelector(`.debug-console-${channel}-output`);
            if (output) fridg3RenderDebugOutput(output, fridg3DebugLogs[channel]);
        });
    });
}

function fridg3InitDebugClearControls(panel) {
    panel.querySelectorAll('[data-debug-clear]').forEach(button => {
        button.addEventListener('click', async () => {
            const channel = button.dataset.debugClear;
            const confirmed = await showSitePopup({
                title: `clear ${channel} log?`,
                detail: `this will remove all entries from the ${channel} log.`,
                okText: 'clear log',
                cancelText: 'cancel',
            });
            if (!confirmed) return;

            if (channel === 'access') {
                button.disabled = true;
                try {
                    const response = await fetch('/api/debug-access-logs', {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-Fridg3-Debug-Action': 'clear',
                        },
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) throw new Error(data.error || 'clear failed');
                    fridg3AccessLogs = [];
                    fridg3RenderAccessLogs([]);
                } catch (_) {
                    await showSiteNotice('unable to clear access log', 'the access log could not be cleared.');
                } finally {
                    button.disabled = false;
                }
                return;
            }

            fridg3DebugLogs[channel].length = 0;
            if (channel === 'client') {
                fridg3DebugHistoryRestored = true;
                try { sessionStorage.removeItem('fridg3DebugClientHistory'); } catch (_) { /* ignore */ }
            } else {
                fridg3ServerHistoryRestored = true;
                try { sessionStorage.removeItem('fridg3DebugServerHistory'); } catch (_) { /* ignore */ }
            }
            const output = panel.querySelector(`.debug-console-${channel}-output`);
            if (output) fridg3RenderDebugOutput(output, fridg3DebugLogs[channel]);
            fridg3ScheduleDebugHistoryPersist();
        });
    });
}

function fridg3InitDebugConsoleResize(panel) {
    const handle = panel.querySelector('.debug-console-resize-handle');
    if (!handle || handle.dataset.bound === '1') return;
    handle.dataset.bound = '1';
    try {
        const savedWidth = Number(localStorage.getItem('fridg3DebugConsoleWidth'));
        if (savedWidth >= 260 && savedWidth <= window.innerWidth * 0.9) panel.style.width = `${savedWidth}px`;
    } catch (_) { /* ignore */ }
    handle.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        event.preventDefault();
        const startX = event.clientX;
        const startWidth = panel.getBoundingClientRect().width;
        handle.setPointerCapture(event.pointerId);
        const move = moveEvent => {
            const width = Math.max(260, Math.min(window.innerWidth * 0.9, startWidth + startX - moveEvent.clientX));
            panel.style.width = `${Math.round(width)}px`;
        };
        const stop = stopEvent => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', stop);
            handle.removeEventListener('pointercancel', stop);
            try { localStorage.setItem('fridg3DebugConsoleWidth', String(Math.round(panel.getBoundingClientRect().width))); } catch (_) { /* ignore */ }
            if (handle.hasPointerCapture(stopEvent.pointerId)) handle.releasePointerCapture(stopEvent.pointerId);
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', stop);
        handle.addEventListener('pointercancel', stop);
    });
    handle.addEventListener('keydown', event => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        const direction = event.key === 'ArrowLeft' ? 1 : -1;
        const width = Math.max(260, Math.min(window.innerWidth * 0.9, panel.getBoundingClientRect().width + direction * 20));
        panel.style.width = `${Math.round(width)}px`;
        try { localStorage.setItem('fridg3DebugConsoleWidth', String(Math.round(width))); } catch (_) { /* ignore */ }
    });
}

function fridg3SelectDebugTab(panel, channel, persist = false) {
    const button = panel.querySelector(`[data-debug-tab="${channel}"]`);
    if (!button || button.getAttribute('aria-disabled') === 'true') return false;
    panel.querySelectorAll('[data-debug-tab]').forEach(tab => tab.classList.toggle('is-active', tab === button));
    panel.querySelectorAll('[data-debug-output]').forEach(output => output.classList.toggle('is-active', output.dataset.debugOutput === channel));
    if (persist) {
        try { sessionStorage.setItem('fridg3DebugSelectedTab', channel); } catch (_) { /* ignore */ }
    }
    if (channel === 'access' && fridg3ServerDebugAuthorized) fridg3StartAccessLogPolling();
    else fridg3StopAccessLogPolling();
    return true;
}

function fridg3InitClientLogControls(panel) {
    const controls = [
        ['#debug-settings-logs-toggle', 'debugIncludeSettingsLogs'],
        ['#debug-network-logs-toggle', 'debugIncludeNetworkLogs'],
        ['#debug-client-warnings-toggle', 'debugIncludeClientWarnings'],
        ['#debug-client-errors-toggle', 'debugIncludeClientErrors'],
    ];
    controls.forEach(([selector, storageKey]) => {
        const toggle = panel.querySelector(selector);
        if (!toggle) return;
        try { toggle.checked = localStorage.getItem(storageKey) !== 'false'; } catch (_) { /* ignore */ }
        toggle.addEventListener('change', () => {
            try { localStorage.setItem(storageKey, toggle.checked ? 'true' : 'false'); } catch (_) { /* ignore */ }
            const output = panel.querySelector('.debug-console-client-output');
            if (output) fridg3RenderDebugOutput(output, fridg3DebugLogs.client);
        });
    });
}

function fridg3SetDebugMode(enabled) {
    const mobile = document.body.classList.contains('mobile-template')
        || (window.matchMedia && window.matchMedia('(max-width: 700px)').matches);
    const shouldEnable = enabled === true && !mobile;
    fridg3DebugEnabled = shouldEnable;
    if (!shouldEnable) {
        fridg3DeactivateDebugRuntime();
        const existingPanel = document.getElementById('debug-console');
        if (existingPanel) existingPanel.hidden = true;
        return;
    }
    fridg3ActivateDebugRuntime();
    fridg3EnsureDebugConsole().hidden = false;
}

window.fridg3DebugClientLog = value => fridg3DebugAppend('client', value);
window.fridg3DebugServerLog = value => fridg3DebugAppend('server', value);
window.fridg3DebugProcessLog = value => fridg3DebugAppend('server', value, true);
window.fridg3SetDebugMode = fridg3SetDebugMode;

function fridg3DebugWindowError(event) {
    window.fridg3DebugClientLog(`uncaught JavaScript error: ${event.message || 'unknown error'}`);
}

function fridg3DebugUnhandledRejection(event) {
    const reason = event.reason && event.reason.message ? event.reason.message : 'unknown rejection';
    window.fridg3DebugClientLog(`unhandled promise rejection: ${reason}`);
}

function fridg3DebugOnline() { window.fridg3DebugClientLog('browser network connection restored'); }
function fridg3DebugOffline() { window.fridg3DebugClientLog('warning: browser network connection lost'); }
function fridg3DebugVisibilityChange() {
    window.fridg3DebugClientLog(`page visibility changed to ${document.visibilityState}`);
}

function fridg3ActivateDebugRuntime() {
    fridg3RestoreClientDebugHistory();
    if (!fridg3DebugListenersActive) {
        window.addEventListener('error', fridg3DebugWindowError);
        window.addEventListener('unhandledrejection', fridg3DebugUnhandledRejection);
        window.addEventListener('online', fridg3DebugOnline);
        window.addEventListener('offline', fridg3DebugOffline);
        document.addEventListener('visibilitychange', fridg3DebugVisibilityChange);
        window.addEventListener('pagehide', fridg3PersistDebugHistory);
        fridg3DebugListenersActive = true;
    }
    fridg3EnableFetchTracing();
    fridg3EnableConsoleTracing();
    if (!fridg3DebugStartupSeeded) {
        fridg3DebugStartupSeeded = true;
        window.fridg3DebugClientLog('(JS) debug log loaded successfully');
        window.fridg3DebugServerLog('(PHP) debug log loaded successfully');
    }
    fridg3CollectServerDebugLogs(document);
}

function fridg3DeactivateDebugRuntime() {
    fridg3StopProcessLogPolling();
    fridg3StopAccessLogPolling();
    fridg3PersistDebugHistory();
    if (fridg3DebugListenersActive) {
        window.removeEventListener('error', fridg3DebugWindowError);
        window.removeEventListener('unhandledrejection', fridg3DebugUnhandledRejection);
        window.removeEventListener('online', fridg3DebugOnline);
        window.removeEventListener('offline', fridg3DebugOffline);
        document.removeEventListener('visibilitychange', fridg3DebugVisibilityChange);
        window.removeEventListener('pagehide', fridg3PersistDebugHistory);
        fridg3DebugListenersActive = false;
    }
    if (fridg3OriginalFetch) {
        window.fetch = fridg3OriginalFetch;
        fridg3OriginalFetch = null;
    }
    if (fridg3OriginalConsoleError) {
        console.error = fridg3OriginalConsoleError;
        fridg3OriginalConsoleError = null;
    }
    if (fridg3OriginalConsoleWarn) {
        console.warn = fridg3OriginalConsoleWarn;
        fridg3OriginalConsoleWarn = null;
    }
}

function fridg3EnableFetchTracing() {
    if (!window.fetch || fridg3OriginalFetch) return;
    fridg3OriginalFetch = window.fetch;
    window.fetch = async function debugFetch(input, init) {
        const method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        let path = 'request';
        try { path = new URL(typeof input === 'string' ? input : input.url, window.location.href).pathname; } catch (_) { /* keep generic path */ }
        const quiet = path === '/api/debug-process-logs' || path === '/api/debug-access-logs' || path === '/api/system/usage/' || path === '/api/feed-notifications';
        const requestBody = init && Object.prototype.hasOwnProperty.call(init, 'body')
            ? init.body
            : typeof Request !== 'undefined' && input instanceof Request && input.body ? input.body : null;
        const isUpload = method !== 'GET' && method !== 'HEAD' && requestBody != null;
        if (!quiet) window.fridg3DebugClientLog(`[network] ${method} ${path} started`);
        if (isUpload) window.fridg3DebugClientLog(`[upload] ${method} ${path} started`);
        try {
            const tracedInit = Object.assign({}, init || {});
            tracedInit.headers = new Headers((init && init.headers) || (input && input.headers) || undefined);
            tracedInit.headers.set('X-Fridg3-Debug', '1');
            const response = await fridg3OriginalFetch.call(this, input, tracedInit);
            fridg3ImportPhpDebugHeader(response);
            if (!quiet || !response.ok) {
                window.fridg3DebugClientLog(`[network] ${method} ${path} ${response.status}`);
            }
            if (isUpload) window.fridg3DebugClientLog(`[upload] ${method} ${path} ${response.status}`);
            return response;
        } catch (error) {
            window.fridg3DebugClientLog(`[network] ${method} ${path} failed: ${error.message || 'network error'}`);
            if (isUpload) window.fridg3DebugClientLog(`[upload] ${method} ${path} failed: ${error.message || 'network error'}`);
            throw error;
        }
    };
}

function fridg3ImportPhpDebugHeader(response) {
    if (!fridg3DebugEnabled || !response || !response.headers) return;
    const encoded = response.headers.get('X-Fridg3-Debug-Logs');
    if (!encoded) return;
    try {
        const binary = window.atob(encoded);
        const bytes = Uint8Array.from(binary, character => character.charCodeAt(0));
        const json = new TextDecoder('utf-8').decode(bytes);
        const logs = JSON.parse(json);
        if (Array.isArray(logs)) logs.forEach(window.fridg3DebugServerLog);
    } catch (error) {
        window.fridg3DebugClientLog(`PHP debug header failed to decode: ${error.message || 'unknown error'}`);
    }
}

function fridg3EnableConsoleTracing() {
    if (!window.console) return;
    if (!fridg3OriginalConsoleError) {
        fridg3OriginalConsoleError = console.error;
        console.error = function debugConsoleError(...args) {
            const summary = typeof args[0] === 'string' ? args[0] : 'console error';
            window.fridg3DebugClientLog(`console error: ${summary}`);
            return fridg3OriginalConsoleError.apply(this, args);
        };
    }
    if (!fridg3OriginalConsoleWarn) {
        fridg3OriginalConsoleWarn = console.warn;
        console.warn = function debugConsoleWarn(...args) {
            const summary = typeof args[0] === 'string' ? args[0] : 'console warning';
            window.fridg3DebugClientLog(`console warning: ${summary}`);
            return fridg3OriginalConsoleWarn.apply(this, args);
        };
    }
}

function fridg3CollectServerDebugLogs(sourceDocument) {
    if (!fridg3DebugEnabled) return;
    const source = sourceDocument || document;
    const payload = source.querySelector('[data-fridg3-server-debug-logs]');
    if (!payload || payload.dataset.debugCollected === '1') return;
    payload.dataset.debugCollected = '1';
    try {
        const logs = JSON.parse(payload.textContent || '[]');
        if (Array.isArray(logs)) logs.forEach(window.fridg3DebugServerLog);
    } catch (_) { /* ignore malformed debug payloads */ }
}

try {
    const initialPrefs = JSON.parse(localStorage.getItem('accessibilityPrefs') || '{}');
    if (initialPrefs.debugMode === true) fridg3SetDebugMode(true);
} catch (_) { /* debug mode stays dormant */ }

function fridg3StopProcessLogPolling() {
    if (fridg3ProcessLogTimer) window.clearInterval(fridg3ProcessLogTimer);
    fridg3ProcessLogTimer = null;
    fridg3ProcessLogCursor = { identity: '', offset: 0 };
}

async function fridg3PollProcessLogs() {
    const toggle = document.getElementById('debug-process-logs-toggle');
    if (!toggle || !toggle.checked || fridg3ProcessLogRequestActive) return;
    fridg3ProcessLogRequestActive = true;
    const params = new URLSearchParams();
    if (fridg3ProcessLogCursor.identity) {
        params.set('identity', fridg3ProcessLogCursor.identity);
        params.set('offset', String(fridg3ProcessLogCursor.offset));
    }
    try {
        const response = await fetch('/api/debug-process-logs?' + params.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'process log unavailable');
        fridg3ProcessLogCursor = { identity: data.identity || '', offset: Number(data.offset) || 0 };
        (data.lines || []).forEach(window.fridg3DebugProcessLog);
    } catch (_) {
        const status = document.querySelector('.debug-process-log-status');
        if (status) {
            status.hidden = false;
            status.textContent = 'process log unavailable';
        }
        toggle.checked = false;
        try { localStorage.setItem('debugIncludeProcessLogs', 'false'); } catch (_) { /* ignore */ }
        fridg3StopProcessLogPolling();
        const output = document.querySelector('.debug-console-server-output');
        if (output) fridg3RenderDebugOutput(output, fridg3DebugLogs.server);
    } finally {
        fridg3ProcessLogRequestActive = false;
    }
}

function fridg3StartProcessLogPolling() {
    fridg3StopProcessLogPolling();
    fridg3PollProcessLogs();
    fridg3ProcessLogTimer = window.setInterval(fridg3PollProcessLogs, 2000);
    window.fridg3DebugClientLog('PHP process-log polling enabled');
}

function fridg3StopAccessLogPolling() {
    if (fridg3AccessLogTimer) window.clearInterval(fridg3AccessLogTimer);
    fridg3AccessLogTimer = null;
}

function fridg3RenderAccessLogs(entries) {
    const output = document.querySelector('.debug-console-access-output');
    if (!output) return;
    fridg3AccessLogs = entries.slice(-FRIDG3_ACCESS_LOG_LIMIT);
    fridg3RunAfterOutputSelection(output, () => {
        const firstRender = output.dataset.rendered !== '1';
        const wasAtBottom = firstRender || output.scrollHeight - output.scrollTop - output.clientHeight < 20;
        const previousScrollTop = output.scrollTop;
        output.replaceChildren();
        fridg3AccessLogs.filter(entry => {
        const role = ['guest', 'user', 'admin'].includes(entry.role) ? entry.role : (entry.username ? 'user' : 'guest');
        const roleToggle = document.getElementById(`debug-access-${role}s-toggle`);
        if (roleToggle && !roleToggle.checked) return false;
        const bannedToggle = document.getElementById('debug-access-hard-banned-toggle');
        if (entry.hardBanned && bannedToggle && !bannedToggle.checked) return false;
        return fridg3DebugSearchMatches(
            'access',
            `${entry.ip || ''} ${entry.username ? `@${entry.username}` : ''} ${entry.status || ''} ${entry.path || '/'}`
        );
        }).forEach(entry => {
        const line = document.createElement('span');
        line.className = 'debug-log-entry';
        const date = new Date(entry.timestamp);
        const time = Number.isNaN(date.getTime())
            ? '--:--:--'
            : [date.getHours(), date.getMinutes(), date.getSeconds()].map(part => String(part).padStart(2, '0')).join(':');
        const timestamp = document.createElement('span');
        timestamp.className = 'debug-log-timestamp';
        timestamp.textContent = `[${time}]`;
        const status = Number(entry.status) || 0;
        const statusElement = document.createElement('span');
        statusElement.className = 'debug-access-status';
        if (status >= 200 && status < 300) statusElement.classList.add('is-success');
        else if (status >= 300 && status < 400) statusElement.classList.add('is-warning');
        else if (status >= 400) statusElement.classList.add('is-error');
        statusElement.textContent = String(status || '---');
        const ip = document.createElement('a');
        ip.className = 'debug-access-ip' + (entry.hardBanned ? ' is-hard-banned' : '');
        ip.textContent = entry.ip || 'unknown';
        ip.dataset.accessIp = entry.ip || '';
        ip.href = `https://whatismyipaddress.com/ip/${encodeURIComponent(entry.ip || 'unknown')}`;
        ip.target = '_blank';
        ip.rel = 'noopener noreferrer';
        ip.setAttribute('data-no-external-popup', '');
        line.append(
            timestamp,
            document.createTextNode(' ['),
            ip,
            document.createTextNode(']')
        );
        if (entry.username) {
            const username = document.createElement('span');
            username.className = 'debug-access-username';
            username.textContent = `@${entry.username}`;
            line.append(document.createTextNode(' ['), username, document.createTextNode(']'));
        }
        line.append(
            document.createTextNode(' ['),
            statusElement,
            document.createTextNode(`] ${entry.path || '/'}`)
        );
        fridg3HighlightDebugLine(line, 'access');
            output.append(line);
        });
        output.dataset.rendered = '1';
        output.scrollTop = wasAtBottom ? output.scrollHeight : previousScrollTop;
    });
}

async function fridg3PollAccessLogs() {
    const accessPanel = document.querySelector('.debug-console-access-panel');
    if (
        !fridg3DebugEnabled
        || !fridg3ServerDebugAuthorized
        || !accessPanel
        || !accessPanel.classList.contains('is-active')
        || fridg3AccessLogRequestActive
    ) return;
    fridg3AccessLogRequestActive = true;
    try {
        const response = await fetch('/api/debug-access-logs', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (response.status === 403) {
            fridg3ServerDebugAuthorized = false;
            fridg3StopAccessLogPolling();
            return;
        }
        const data = await response.json();
        if (!response.ok || !data.ok || !Array.isArray(data.entries)) return;
        if (!accessPanel.classList.contains('is-active')) return;
        fridg3RenderAccessLogs(data.entries);
    } catch (_) { /* retain the last successful access-log view */ }
    finally { fridg3AccessLogRequestActive = false; }
}

function fridg3StartAccessLogPolling() {
    fridg3StopAccessLogPolling();
    fridg3PollAccessLogs();
    fridg3AccessLogTimer = window.setInterval(fridg3PollAccessLogs, 1000);
}

async function fridg3InitProcessLogControl(panel) {
    const option = panel.querySelector('.debug-server-log-options');
    const loadedToggle = panel.querySelector('#debug-loaded-logs-toggle');
    const toggle = panel.querySelector('#debug-process-logs-toggle');
    const status = panel.querySelector('.debug-process-log-status');
    if (!option || !loadedToggle || !toggle || toggle.dataset.bound === '1') return;
    toggle.dataset.bound = '1';
    try {
        const response = await fetch('/api/debug-process-logs', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json();
        if (response.status === 403 || !data.isAdmin && !data.ok) {
            fridg3ServerDebugAuthorized = false;
            fridg3DebugLogs.server.length = 0;
            try { sessionStorage.removeItem('fridg3DebugServerHistory'); } catch (_) { /* ignore */ }
            return;
        }
        fridg3ServerDebugAuthorized = true;
        fridg3RestoreServerDebugHistory();
        fridg3ScheduleDebugHistoryPersist();
        panel.querySelectorAll('[data-admin-debug-tab]').forEach(tab => {
            tab.removeEventListener('mouseenter', tab._tooltipMouseEnter);
            tab.removeEventListener('mousemove', tab._tooltipMouseMove);
            tab.removeEventListener('mouseleave', tab._tooltipMouseLeave);
            tab.classList.remove('is-disabled');
            tab.removeAttribute('aria-disabled');
            tab.removeAttribute('data-tooltip');
            tab.hidden = false;
        });
        panel.querySelectorAll('.debug-admin-log-search').forEach(search => { search.hidden = false; });
        const accessOptions = panel.querySelector('.debug-access-log-options');
        if (accessOptions) accessOptions.hidden = false;
        document.querySelectorAll('.tooltip').forEach(tooltip => tooltip.remove());
        option.hidden = false;
        [
            ['#debug-server-warnings-toggle', 'debugIncludeServerWarnings'],
            ['#debug-server-errors-toggle', 'debugIncludeServerErrors'],
        ].forEach(([selector, storageKey]) => {
            const filterToggle = panel.querySelector(selector);
            if (!filterToggle) return;
            try { filterToggle.checked = localStorage.getItem(storageKey) === 'true'; } catch (_) { /* ignore */ }
            filterToggle.addEventListener('change', () => {
                try { localStorage.setItem(storageKey, filterToggle.checked ? 'true' : 'false'); } catch (_) { /* ignore */ }
                const output = panel.querySelector('.debug-console-server-output');
                if (output) fridg3RenderDebugOutput(output, fridg3DebugLogs.server);
            });
        });
        let selectedTab = 'client';
        try { selectedTab = sessionStorage.getItem('fridg3DebugSelectedTab') || 'client'; } catch (_) { /* ignore */ }
        fridg3SelectDebugTab(panel, ['server', 'access'].includes(selectedTab) ? selectedTab : 'client');
        try { loadedToggle.checked = localStorage.getItem('debugIncludeLoadedLogs') !== 'false'; } catch (_) { /* ignore */ }
        loadedToggle.addEventListener('change', () => {
            try { localStorage.setItem('debugIncludeLoadedLogs', loadedToggle.checked ? 'true' : 'false'); } catch (_) { /* ignore */ }
            const output = panel.querySelector('.debug-console-server-output');
            if (output) fridg3RenderDebugOutput(output, fridg3DebugLogs.server);
        });
        const serverOutput = panel.querySelector('.debug-console-server-output');
        if (serverOutput) fridg3RenderDebugOutput(serverOutput, fridg3DebugLogs.server);
        if (!data.ok) {
            toggle.disabled = true;
            status.hidden = false;
            status.textContent = 'process log unavailable';
            return;
        }
        fridg3ProcessLogCursor = { identity: data.identity || '', offset: Number(data.offset) || 0 };
        window.fridg3DebugProcessLog(`monitoring ${data.source || 'PHP process log'}`);
        (data.lines || []).forEach(window.fridg3DebugProcessLog);
        try { toggle.checked = localStorage.getItem('debugIncludeProcessLogs') === 'true'; } catch (_) { /* ignore */ }
        const renderProcessLogs = () => {
            const output = panel.querySelector('.debug-console-server-output');
            if (output) fridg3RenderDebugOutput(output, fridg3DebugLogs.server);
        };
        renderProcessLogs();
        toggle.addEventListener('change', () => {
            try { localStorage.setItem('debugIncludeProcessLogs', toggle.checked ? 'true' : 'false'); } catch (_) { /* ignore */ }
            status.hidden = true;
            if (toggle.checked) fridg3StartProcessLogPolling();
            else {
                fridg3StopProcessLogPolling();
                window.fridg3DebugClientLog('PHP process-log polling disabled');
            }
            renderProcessLogs();
        });
        if (toggle.checked) fridg3StartProcessLogPolling();
    } catch (_) { /* keep the admin-only control hidden */ }
}

let activeSiteNoticePopupId = '';

function siteNoticeStorageKey(type, id) {
    return `fridg3_site_notice_${type}_${id}`;
}

function siteNoticeWasSeen(type, id) {
    try {
        return localStorage.getItem(siteNoticeStorageKey(type, id)) === '1';
    } catch (_) {
        return false;
    }
}

function markSiteNoticeSeen(type, id) {
    try {
        localStorage.setItem(siteNoticeStorageKey(type, id), '1');
    } catch (_) { /* Storage can be unavailable. */ }
}

function readSiteNoticePopup(sourceDocument) {
    const runtime = (sourceDocument || document).getElementById('site-notice-runtime');
    if (!runtime) return null;
    try {
        const value = JSON.parse(runtime.textContent || 'null');
        return value && typeof value === 'object' ? value : null;
    } catch (_) {
        return null;
    }
}

function initSiteNotices(sourceDocument) {
    const source = sourceDocument || document;
    if (source !== document) {
        const incomingBanner = source.getElementById('site-notice-banner-region');
        const currentBanner = document.getElementById('site-notice-banner-region');
        if (incomingBanner && currentBanner) {
            currentBanner.replaceWith(incomingBanner.cloneNode(true));
        }
    }

    const banner = document.querySelector('.site-notice-banner[data-site-notice-id]');
    if (banner) {
        const id = banner.dataset.siteNoticeId || '';
        const dismissible = banner.dataset.dismissible === '1';
        if (dismissible && id && siteNoticeWasSeen('banner', id)) {
            banner.remove();
        } else if (dismissible && id) {
            const dismissButton = banner.querySelector('[data-site-notice-dismiss]');
            if (dismissButton) {
                dismissButton.addEventListener('click', () => {
                    markSiteNoticeSeen('banner', id);
                    banner.remove();
                    window.fridg3DebugClientLog('site notice banner dismissed');
                }, { once: true });
            }
        }
    }

    const popup = readSiteNoticePopup(source);
    if (!popup || typeof popup.id !== 'string' || !/^[a-f0-9]{32}$/.test(popup.id) || typeof popup.message !== 'string') {
        return;
    }
    if (activeSiteNoticePopupId === popup.id || siteNoticeWasSeen('popup', popup.id)) {
        return;
    }

    activeSiteNoticePopupId = popup.id;
    window.fridg3DebugClientLog('site notice popup displayed');
    showSitePopup({
        title: typeof popup.title === 'string' && popup.title ? popup.title : 'notice',
        detail: popup.message,
        okText: 'ok',
        customText: typeof popup.buttonLabel === 'string' ? popup.buttonLabel : ''
    }).then(result => {
        markSiteNoticeSeen('popup', popup.id);
        activeSiteNoticePopupId = '';
        if (result === 'custom' && typeof popup.buttonUrl === 'string' && /^\/(?!\/)/.test(popup.buttonUrl)) {
            window.location.assign(popup.buttonUrl);
        }
    });
}

window.fridg3InitSiteNotices = initSiteNotices;
window.addEventListener('DOMContentLoaded', () => initSiteNotices());

let activeMissingDevDataPopupId = '';

function readMissingDevDataPopup(sourceDocument) {
    const source = sourceDocument || document;
    const runtime = source.getElementById('missing-dev-data-runtime');
    if (!runtime) return null;

    try {
        const data = JSON.parse(runtime.textContent || 'null');
        return data && typeof data === 'object' ? data : null;
    } catch (_) {
        return null;
    }
}

function initMissingDevDataPopup(sourceDocument) {
    const popup = readMissingDevDataPopup(sourceDocument || document);
    if (!popup || typeof popup.id !== 'string' || typeof popup.message !== 'string') {
        return;
    }

    const storageKey = `fridg3_missing_dev_data_popup_${popup.id}`;
    try {
        if (sessionStorage.getItem(storageKey) === '1') return;
    } catch (_) {
        /* storage can be blocked */
    }

    if (activeMissingDevDataPopupId === popup.id) {
        return;
    }

    activeMissingDevDataPopupId = popup.id;
    window.fridg3DebugClientLog('missing dev data popup displayed');
    showSitePopup({
        title: typeof popup.title === 'string' && popup.title ? popup.title : 'dev data is missing',
        detail: popup.message,
        okText: 'later',
        customText: typeof popup.buttonLabel === 'string' ? popup.buttonLabel : 'open settings'
    }).then(result => {
        activeMissingDevDataPopupId = '';
        try {
            sessionStorage.setItem(storageKey, '1');
        } catch (_) {
            /* no-op */
        }
        if (result === 'custom' && typeof popup.buttonUrl === 'string' && /^\/(?!\/)/.test(popup.buttonUrl)) {
            window.location.assign(popup.buttonUrl);
        }
    });
}

window.fridg3InitMissingDevDataPopup = initMissingDevDataPopup;
window.addEventListener('DOMContentLoaded', () => initMissingDevDataPopup());

function consumeLegacyDomainRedirectNotice() {
    try {
        const currentUrl = new URL(window.location.href);
        const marker = currentUrl.searchParams.get('legacy_domain');
        if (marker !== 'fridg3.org') return;

        currentUrl.searchParams.delete('legacy_domain');
        window.history.replaceState(window.history.state, document.title, currentUrl.toString());

        showSitePopup({
            title: 'heads up!',
            html: '<p>you came here through fridg3.org, which now redirects to fridge.dev.</p><p>fridg3.org will expire on 13/01/2027. make sure you update any links or bookmarks!</p>',
            okText: 'got it'
        });
    } catch (_) {
        /* no-op */
    }
}

window.addEventListener('DOMContentLoaded', consumeLegacyDomainRedirectNotice);

async function fetchAdminStatus() {
    try {
        const res = await fetch('/api/account/is-admin', { credentials: 'include' });
        if (!res.ok) return false;
        const data = await res.json();
        return data && data.isAdmin === true;
    } catch (_) {
        return false;
    }
}

/* ==========================================================================
   ASCII widgets and responsive sizing
   ========================================================================== */

// Dynamically scale #ascii font size to fit container
function autoScaleAsciiFont() {
    const asciiBlocks = document.querySelectorAll('#ascii');
    let scaled = false;
    asciiBlocks.forEach(ascii => {
        const parent = ascii.parentElement;
        if (!parent) return;
        const containerWidth = parent.offsetWidth;
        const defaultFontSize = 12;
        const minFontSize = 9.5;
        // Always reset to default before measuring
        ascii.style.fontSize = defaultFontSize + 'px';
        // Measure natural width at default font size
        const naturalWidth = ascii.scrollWidth;
        if (naturalWidth > containerWidth) {
            // Calculate proportional scale factor
            const scale = containerWidth / naturalWidth;
            const newFontSize = Math.max(minFontSize, defaultFontSize * scale);
            ascii.style.fontSize = newFontSize + 'px';
            if (newFontSize < defaultFontSize) scaled = true;
        } else {
            ascii.style.fontSize = defaultFontSize + 'px';
        }
    });

    // Offer switching to the mobile host instead of showing the old
    // "screen too small" bubble on cramped screens.
    let tooltip = document.getElementById('ascii-scale-tooltip');
    const TOOLTIP_KEY = 'mobileSitePromptDismissed';
    if (isMobileTemplateActive()) {
        if (tooltip) {
            tooltip.style.display = 'none';
            tooltip.style.opacity = '0';
            if (tooltip.fadeTimeout) clearTimeout(tooltip.fadeTimeout);
        }
        return;
    }
    if (localStorage.getItem(TOOLTIP_KEY) === '1') {
        if (tooltip) { tooltip.style.display = 'none'; tooltip.style.opacity = '0'; }
        return;
    }
    if (scaled && isMobileDevice()) {
        localStorage.setItem(TOOLTIP_KEY, '1');
        showSitePopup({
            title: 'screen feels cramped',
            detail: 'switch to the mobile site?',
            okText: 'switch',
            cancelText: 'stay here'
        }).then(function(shouldSwitch) {
            if (!shouldSwitch) return;
            try {
                const targetUrl = new URL(window.location.href);
                targetUrl.hostname = 'm.fridge.dev';
                setMobileViewCookie(true);
                hostRedirectInProgress = true;
                hideSpaLoading();
                window.setTimeout(() => {
                    window.location.href = targetUrl.toString();
                }, 0);
                return;
            } catch (_) {
                /* no-op */
            }
        });
    } else if (tooltip) {
        tooltip.style.display = 'none';
        tooltip.style.opacity = '0';
        if (tooltip.fadeTimeout) clearTimeout(tooltip.fadeTimeout);
    }
}

window.addEventListener('DOMContentLoaded', autoScaleAsciiFont);
window.addEventListener('resize', autoScaleAsciiFont);
window.addEventListener('DOMContentLoaded', initAsciiTime);
window.addEventListener('resize', initAsciiTime);
window.addEventListener('DOMContentLoaded', initAsciiUsage);
window.addEventListener('DOMContentLoaded', initHourlyBeep);

// ASCII time initializer (safe for SPA reloads)
function initAsciiTime() {
    try {
        const el = document.getElementById('ascii-time');
        const labelEl = document.getElementById('ascii-time-label');
        if (!el) return;
        if (el.dataset.asciiTimeBound === '1') {
            if (typeof el._asciiTimeRender === 'function') {
                el._asciiTimeRender();
            }
            return;
        }
        el.dataset.asciiTimeBound = '1';
        el.style.width = 'max-content';
        el.style.marginInline = 'auto';
        el.style.alignSelf = 'center';

        let fontMap = {};
        let maxLines = 0;
        let glyphWidth = 8;
        const charGap = 1;

        const pad = (str, width) => (typeof str === 'string' ? str : '').padEnd(width, ' ');

        const glyphWidthFor = (glyph) => {
            if (!Array.isArray(glyph) || !glyph.length) return glyphWidth;
            return glyph.reduce((m, l) => Math.max(m, l.length), 0);
        };

        const buildMap = (rawFont) => {
            const characters = '0123456789:?'.split('');
            const normalized = typeof rawFont === 'string' ? rawFont.replace(/\r\n?/g, '\n').replace(/\n$/, '') : '';
            const glyphs = normalized.split('\n----------------\n');
            if (glyphs.length !== characters.length) {
                throw new Error(`Expected ${characters.length} ASCII time glyphs, received ${glyphs.length}`);
            }
            const map = {};
            characters.forEach((character, index) => {
                map[character] = glyphs[index].split('\n');
            });
            const lineHeights = Object.values(map).map((lines) => lines.length || 0);
            const widths = Object.values(map).map((lines) => lines.reduce((m, l) => Math.max(m, l.length), 0));
            maxLines = lineHeights.length ? Math.max(...lineHeights) : 0;
            glyphWidth = widths.length ? Math.max(...widths) : glyphWidth;
            return map;
        };

        const londonTzAbbrev = (date) => {
            try {
                const parts = new Intl.DateTimeFormat('en-GB', {
                    timeZone: 'Europe/London',
                    timeZoneName: 'short'
                }).formatToParts(date);
                const tzPart = parts.find((p) => p.type === 'timeZoneName');
                return tzPart && tzPart.value ? tzPart.value.toUpperCase() : 'GMT';
            } catch (_) {
                return 'GMT';
            }
        };

        const renderAsciiRows = (timeStr) => {
            const rows = Array.from({ length: maxLines }, () => '');
            const characters = timeStr.split('');
            characters.forEach((ch, characterIndex) => {
                const glyph = fontMap[ch] || [];
                const width = ch === ':' ? glyphWidthFor(glyph) : glyphWidth;
                const isLastCharacter = characterIndex === characters.length - 1;
                // Keep one explicit cell between every clock glyph, including
                // immediately after a colon. Spacing inside the colon glyph is
                // still preserved as part of the external font.
                const gap = isLastCharacter ? 0 : charGap;
                for (let i = 0; i < maxLines; i += 1) {
                    rows[i] += pad(glyph[i] || '', width + gap);
                }
            });
            return rows.join('\n');
        };

        const render = () => {
            if (!maxLines || !Object.keys(fontMap).length) return;
            const now = new Date();
            const timeOptions = {
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                timeZone: 'Europe/London'
            };
            if (labelEl) {
                const tz = londonTzAbbrev(now);
                labelEl.textContent = `fridge.dev Server Time (${tz})`;
            }

            const shortTime = now.toLocaleTimeString('en-GB', timeOptions);
            const fullTime = now.toLocaleTimeString('en-GB', Object.assign({}, timeOptions, { second: '2-digit' }));
            el.textContent = isMobileTemplateActive() ? renderAsciiRows(shortTime) : renderAsciiRows(fullTime);

            if (!isMobileTemplateActive()) {
                const container = document.getElementById('content-main') || el.parentElement;
                const containerWidth = container ? (container.clientWidth || container.offsetWidth || 0) : 0;
                const rect = el.getBoundingClientRect();
                const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
                const visibleWidth = rect.left && viewportWidth ? viewportWidth - rect.left - 16 : 0;
                const availableWidth = Math.max(containerWidth, visibleWidth);
                if (availableWidth && el.scrollWidth > availableWidth) {
                    el.textContent = renderAsciiRows(shortTime);
                }
            }

            fitMobileAsciiLayout();
        };

        const loadFonts = async () => {
            try {
                const response = await fetch('/resources/ascii-time.txt', { cache: 'no-cache' });
                if (!response.ok) throw new Error(`ASCII time font request failed with ${response.status}`);
                fontMap = buildMap(await response.text());
                if (!maxLines) throw new Error('No glyphs loaded');
                render();
                el._asciiTimeRender = render;
                el._asciiTimeInterval = window.setInterval(render, 1000);
            } catch (err) {
                console.error('Failed to load ASCII time:', err);
                window.fridg3DebugClientLog(`ASCII clock failed: ${err.message || 'unknown error'}`);
                el.textContent = 'time unavailable';
            }
        };

        loadFonts();
    } catch (_) { /* no-op */ }
}

function millisUntilNextHour(timeZone) {
    const now = new Date();
    let minute = now.getMinutes();
    let second = now.getSeconds();

    try {
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone,
            hour12: false,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        }).formatToParts(now);
        minute = Number.parseInt((parts.find((p) => p.type === 'minute') || {}).value, 10) || minute;
        second = Number.parseInt((parts.find((p) => p.type === 'second') || {}).value, 10) || second;
    } catch (_) {
        /* fall back to local time */
    }

    const msIntoHour = ((minute * 60) + second) * 1000 + now.getMilliseconds();
    const msPerHour = 3600 * 1000;
    return msPerHour - (msIntoHour % msPerHour);
}

function initHourlyBeep() {
    const TIMER_KEY = '__hourlyBeepTimer';
    if (window[TIMER_KEY]) return;

    const audio = new Audio('/resources/beepbeep.ogg');
    audio.preload = 'auto';

    const playBeep = () => {
        try { audio.currentTime = 0; } catch (_) { /* no-op */ }
        audio.play().catch(() => {});
    };

    const schedule = () => {
        const delay = millisUntilNextHour('Europe/London');
        window[TIMER_KEY] = window.setTimeout(() => {
            playBeep();
            schedule();
        }, delay);
    };

    schedule();
}

// ASCII percentage renderer for system usage
function initAsciiUsage() {
    try {
        const cpuEl = document.getElementById('usage-cpu-ascii');
        const memEl = document.getElementById('usage-mem-ascii');
        const diskEl = document.getElementById('usage-disk-ascii');
        const diskAvailEl = document.getElementById('usage-disk-free-ascii');
        const targets = [cpuEl, memEl, diskEl, diskAvailEl];
        if (!targets.some(Boolean)) return;
        if (cpuEl && cpuEl.dataset.asciiUsageBound === '1') return;
        if (cpuEl) cpuEl.dataset.asciiUsageBound = '1';
        if (memEl) memEl.dataset.asciiUsageBound = '1';
        if (diskEl) diskEl.dataset.asciiUsageBound = '1';
        if (diskAvailEl) diskAvailEl.dataset.asciiUsageBound = '1';

        let fontMap = {};
        let maxLines = 0;
        let glyphWidth = 8;
        const charGap = 1;
        let scrambleInterval = null;
        let scrambleTimeout = null;
        let previousReadings = null;
        const scrambleDurationMs = 420;
        const scrambleFrameMs = 60;

        const percentageGlyphs = [
            { number: '1', font: " d888      \nd8888      \n  888      \n  888      \n  888      \n  888      \n  888      \n8888888    " },
            { number: '2', font: " .d8888b.  \nd88P  Y88b \n       888 \n     .d88P \n .od888P\"  \nd88P\"      \n888\"       \n888888888  " },
            { number: '3', font: " .d8888b.  \nd88P  Y88b \n     .d88P \n    8888\"  \n     \"Y8b. \n888    888 \nY88b  d88P \n \"Y8888P\"  " },
            { number: '4', font: "    d8888  \n   d8P888  \n  d8P 888  \n d8P  888  \nd88   888  \n8888888888 \n      888  \n      888  " },
            { number: '5', font: "888888888  \n888        \n888        \n8888888b.  \n     \"Y88b \n       888 \nY88b  d88P \n \"Y8888P\"  " },
            { number: '6', font: " .d8888b.  \nd88P  Y88b \n888        \n888d888b.  \n888P \"Y88b \n888    888 \nY88b  d88P \n \"Y8888P\"  " },
            { number: '7', font: "8888888888 \n      d88P \n     d88P  \n    d88P   \n 88888888  \n  d88P     \n d88P      \nd88P       " },
            { number: '8', font: " .d8888b.  \nd88P  Y88b \nY88b. d88P \n \"Y88888\"  \n.d8P\"\"Y8b. \n888    888 \nY88b  d88P \n \"Y8888P\"  " },
            { number: '9', font: " .d8888b.  \nd88P  Y88b \n888    888 \nY88b. d888 \n \"Y888P888 \n       888 \nY88b  d88P \n \"Y8888P\"  " },
            { number: '0', font: " .d8888b.  \nd88P  Y88b \n888    888 \n888    888 \n888    888 \n888    888 \nY88b  d88P \n \"Y8888P\"  " },
            { question: " .sSSs.   \nS%%%%%S   \n    S%%   \n   S%%    \n  S%%     \n  S       \n          \n  S%%     \n  `:;'    " },
            { percent: "d88b   d88P\nY88P  d88P \n     d88P  \n    d88P   \n   d88P    \n  d88P     \n d88P  d88b\nd88P   Y88P" }
        ];

        const pad = (str, width) => (typeof str === 'string' ? str : '').padEnd(width, ' ');

        const buildMap = (entries) => {
            const map = {};
            if (!Array.isArray(entries)) return map;
            entries.forEach((item) => {
                if (item && typeof item === 'object') {
                    if (typeof item.number === 'string' && typeof item.font === 'string') {
                        map[item.number] = item.font.split(/\r?\n/);
                    } else if (Object.prototype.hasOwnProperty.call(item, 'percent') && typeof item.percent === 'string') {
                        map['%'] = item.percent.split(/\r?\n/);
                    } else if (Object.prototype.hasOwnProperty.call(item, 'question') && typeof item.question === 'string') {
                        map['?'] = item.question.split(/\r?\n/);
                    }
                }
            });
            const lineHeights = Object.values(map).map((lines) => lines.length || 0);
            const widths = Object.values(map).map((lines) => lines.reduce((m, l) => Math.max(m, l.length), 0));
            maxLines = lineHeights.length ? Math.max(...lineHeights) : 0;
            glyphWidth = widths.length ? Math.max(...widths) : glyphWidth;
            return map;
        };

        const renderValue = (value, digitOverride = null) => {
            if (!maxLines || !Object.keys(fontMap).length) return null;
            const safeVal = Number.isFinite(value) ? Math.max(0, Math.min(100, Math.round(value))) : null;
            const numStr = typeof digitOverride === 'string'
                ? digitOverride
                : (safeVal === null ? '??' : String(safeVal).padStart(2, '0'));
            const str = `${numStr}%`;
            const rows = Array.from({ length: maxLines }, () => '');
            str.split('').forEach((ch) => {
                const glyph = fontMap[ch] || [];
                const width = glyphWidth;
                for (let i = 0; i < maxLines; i += 1) {
                    rows[i] += pad(glyph[i] || '', width + charGap);
                }
            });
            return rows.join('\n');
        };

        const normalizeUsageValue = (value) => Number.isFinite(value)
            ? Math.max(0, Math.min(100, Math.round(value)))
            : null;

        const renderReadings = (data, scrambledMetrics = new Set()) => {
            const randomDigits = (value) => {
                const safeVal = normalizeUsageValue(value);
                const digitCount = safeVal === 100 ? 3 : 2;
                return Array.from({ length: digitCount }, () => Math.floor(Math.random() * 10)).join('');
            };
            const cpu = renderValue(data.cpu, scrambledMetrics.has('cpu') ? randomDigits(data.cpu) : null);
            const mem = renderValue(data.memory, scrambledMetrics.has('memory') ? randomDigits(data.memory) : null);
            const disk = renderValue(data.disk, scrambledMetrics.has('disk') ? randomDigits(data.disk) : null);
            const diskAvail = renderValue(data.diskAvailable, scrambledMetrics.has('diskAvailable') ? randomDigits(data.diskAvailable) : null);
            if (cpuEl) cpuEl.textContent = cpu || '??%';
            if (memEl) memEl.textContent = mem || '??%';
            if (diskEl) diskEl.textContent = disk || '??%';
            if (diskAvailEl) diskAvailEl.textContent = diskAvail || '??%';
            fitMobileAsciiLayout();
        };

        const applyReadings = (data) => {
            if (scrambleInterval) window.clearInterval(scrambleInterval);
            if (scrambleTimeout) window.clearTimeout(scrambleTimeout);

            const currentReadings = {
                cpu: normalizeUsageValue(data.cpu),
                memory: normalizeUsageValue(data.memory),
                disk: normalizeUsageValue(data.disk),
                diskAvailable: normalizeUsageValue(data.diskAvailable),
            };
            const changedMetrics = new Set(previousReadings === null
                ? []
                : Object.keys(currentReadings).filter((key) => previousReadings[key] !== currentReadings[key]));
            previousReadings = currentReadings;

            if (changedMetrics.size === 0) {
                scrambleInterval = null;
                scrambleTimeout = null;
                renderReadings(data);
                return;
            }

            renderReadings(data, changedMetrics);
            scrambleInterval = window.setInterval(() => renderReadings(data, changedMetrics), scrambleFrameMs);
            scrambleTimeout = window.setTimeout(() => {
                window.clearInterval(scrambleInterval);
                scrambleInterval = null;
                scrambleTimeout = null;
                renderReadings(data);
            }, scrambleDurationMs);
        };

        const loadFonts = async () => {
            fontMap = buildMap(percentageGlyphs);
            if (!maxLines) throw new Error('No glyphs loaded');
        };

        const fetchUsage = async () => {
            const tryFetch = async (url) => {
                const res = await fetch(url, { cache: 'no-store' });
                const text = await res.text();
                if (!text || !text.trim()) return {};
                try {
                    return JSON.parse(text) || {};
                } catch (parseErr) {
                    console.error('Invalid usage JSON from', url, parseErr, text);
                    window.fridg3DebugClientLog('system usage endpoint returned invalid JSON');
                    return {};
                }
            };

            try {
                const primary = await tryFetch('/api/system/usage/');
                const ua = (navigator.userAgent || '').toLowerCase();
                const isWinUa = ua.includes('windows');

                // If we're on Windows and any metric is missing, attempt a Windows-targeted retry and merge missing fields
                if (isWinUa && ([primary.cpu, primary.memory, primary.disk].some((v) => v == null))) {
                    try {
                        const winData = await tryFetch('/api/system/usage/?os=windows');
                        const merged = {
                            cpu: primary.cpu ?? winData.cpu,
                            memory: primary.memory ?? winData.memory,
                            disk: primary.disk ?? winData.disk,
                            os: winData.os || primary.os,
                            timestamp: winData.timestamp || primary.timestamp
                        };
                        applyReadings(merged);
                        return;
                    } catch (_) {
                        /* fall through to primary */
                    }
                }

                // derive disk available if we have used
                const derived = { ...primary };
                if (Number.isFinite(primary.disk)) {
                    derived.diskAvailable = Math.max(0, Math.min(100, 100 - primary.disk));
                }

                applyReadings(derived);
            } catch (err) {
                console.error('Failed to load system usage:', err);
                window.fridg3DebugClientLog(`system usage refresh failed: ${err.message || 'unknown error'}`);
                if (cpuEl) cpuEl.textContent = 'usage unavailable';
                if (memEl) memEl.textContent = 'usage unavailable';
                if (diskEl) diskEl.textContent = 'usage unavailable';
                if (diskAvailEl) diskAvailEl.textContent = 'usage unavailable';
            }
        };

        (async () => {
            try {
                await loadFonts();
                await fetchUsage();
                const interval = window.setInterval(fetchUsage, 5000);
                if (cpuEl) cpuEl._usageInterval = interval;
            } catch (err) {
                console.error('Failed to init ASCII usage:', err);
                window.fridg3DebugClientLog(`system usage widget initialization failed: ${err.message || 'unknown error'}`);
                if (cpuEl) cpuEl.textContent = 'usage unavailable';
                if (memEl) memEl.textContent = 'usage unavailable';
                if (diskEl) diskEl.textContent = 'usage unavailable';
                if (diskAvailEl) diskAvailEl.textContent = 'usage unavailable';
            }
        })();
    } catch (_) { /* no-op */ }
}

// Responsive ASCII art scaling
function scaleAsciiBlocks() {
    document.querySelectorAll('.ascii-scale-container').forEach(container => {
        const inner = container.querySelector('.ascii-scale-inner');
        // Support both #ascii and #ascii-gradient
        const ascii = inner ? (inner.querySelector('#ascii') || inner.querySelector('#ascii-gradient')) : null;
        if (!ascii) return;
        // Reset any previous scaling
        ascii.style.transform = '';
        ascii.style.transformOrigin = 'left top';
        ascii.style.width = '';
        // Measure actual width
        const containerWidth = container.offsetWidth;
        const asciiWidth = ascii.scrollWidth;
        if (asciiWidth > containerWidth) {
            const scale = containerWidth / asciiWidth;
            ascii.style.transform = `scale(${scale})`;
            ascii.style.width = asciiWidth + 'px'; // preserve layout height
        }
    });
}

window.addEventListener('DOMContentLoaded', scaleAsciiBlocks);
window.addEventListener('resize', scaleAsciiBlocks);
// If SPA navigation or content loads, re-run scaling
function rerunAsciiScalingAfterContent() {
    setTimeout(scaleAsciiBlocks, 0);
}

function refreshAsciiLayoutAfterFontLoad() {
    try {
        if (!document.fonts || typeof document.fonts.load !== 'function') {
            autoScaleAsciiFont();
            fitMobileAsciiLayout();
            scaleAsciiBlocks();
            return;
        }

        document.fonts.load('12px IBM_VGA').then(() => {
            window.requestAnimationFrame(() => {
                autoScaleAsciiFont();
                fitMobileAsciiLayout();
                scaleAsciiBlocks();
            });
        }).catch(() => {
            autoScaleAsciiFont();
            fitMobileAsciiLayout();
            scaleAsciiBlocks();
        });
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', refreshAsciiLayoutAfterFontLoad);

/* ==========================================================================
   Syntax highlighting and mobile behavior
   ========================================================================== */

// Highlight.js initialization
if (typeof hljs !== 'undefined') {
    hljs.highlightAll();
}


function isMobileDevice() {
    const ua = (navigator.userAgent || '').toLowerCase();
    const uaLooksMobile = /android|webos|iphone|ipad|ipod|blackberry|bb10|iemobile|opera mini|mobile/.test(ua);
    const iPadDesktopUA = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    const coarsePointer = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    const noHover = !!(window.matchMedia && window.matchMedia('(hover: none)').matches);
    const screenMin = Math.min(
        window.screen && window.screen.width ? window.screen.width : window.innerWidth,
        window.screen && window.screen.height ? window.screen.height : window.innerHeight
    );

    return (
        uaLooksMobile ||
        iPadDesktopUA ||
        (coarsePointer && noHover && screenMin <= 900)
    );
}

function redirectMobileVisitorsToMobileHost() {
    try {
        const currentUrl = new URL(window.location.href);
        const host = (currentUrl.hostname || '').toLowerCase();
        const mobile = isMobileDevice();
        syncMobileViewCookieWithCurrentHost();
        const mobileViewPreference = readMobileViewCookie();

        if (host === 'fridge.dev' && mobile && mobileViewPreference !== false) {
            currentUrl.hostname = 'm.fridge.dev';
            window.location.replace(currentUrl.toString());
            return;
        }

        if (host === 'm.fridge.dev' && !mobile) {
            currentUrl.hostname = 'fridge.dev';
            window.location.replace(currentUrl.toString());
        }
    } catch (_) {
        /* no-op */
    }
}

redirectMobileVisitorsToMobileHost();

const tooltips = document.querySelectorAll('[data-tooltip]');
let activeTooltip = null;

function isMobileTemplateActive() {
    try {
        return !!(document.body && document.body.classList && document.body.classList.contains('mobile-template'));
    } catch (_) {
        return false;
    }
}

function clearTooltips() {
    document.querySelectorAll('.tooltip').forEach(el => el.remove());
    activeTooltip = null;
}

function applyResponsiveScale() {
    try {
        // Remove any inline scaling so the layout uses pure CSS
        // sizing and behaves like a normal static page.
        const wrapper = document.getElementById('page-wrapper');
        if (wrapper) {
            wrapper.style.transform = '';
            wrapper.style.width = '';
            wrapper.style.height = '';
        }

        const container = document.getElementById('container');
        if (container) {
            container.style.width = '';
            container.style.height = '';
        }
    } catch (_) { /* no-op */ }
}

function fitAsciiTextElement(el, options = {}) {
    try {
        if (!el) return;
        const container = options.container || el.parentElement;
        if (!container) return;

        const maxFontSize = options.maxFontSize ?? 12;
        const minFontSize = options.minFontSize ?? 4;
        const availableWidth = container.clientWidth || container.offsetWidth || 0;
        if (!availableWidth) return;

        el.style.fontSize = maxFontSize + 'px';

        const naturalWidth = el.scrollWidth || 0;
        if (!naturalWidth) return;
        if (naturalWidth <= availableWidth) return;

        const scale = availableWidth / naturalWidth;
        const newFontSize = Math.max(minFontSize, maxFontSize * scale);
        el.style.fontSize = newFontSize + 'px';
    } catch (_) { /* no-op */ }
}

function fitMobileAsciiLayout() {
    try {
        if (!isMobileTemplateActive()) return;

        const contentMain = document.getElementById('content-main');
        document.querySelectorAll('#ascii').forEach((el) => {
            fitAsciiTextElement(el, {
                container: el.parentElement,
                maxFontSize: 12,
                minFontSize: 4
            });
        });

        const asciiTime = document.getElementById('ascii-time');
        if (asciiTime) {
            fitAsciiTextElement(asciiTime, {
                container: contentMain || asciiTime.parentElement,
                maxFontSize: 12,
                minFontSize: 4
            });
        }

        document.querySelectorAll('.usage-ascii').forEach((el) => {
            fitAsciiTextElement(el, {
                container: el.closest('.usage-card') || el.parentElement,
                maxFontSize: 11,
                minFontSize: 4
            });
        });
    } catch (_) { /* no-op */ }
}

window.addEventListener('resize', applyResponsiveScale);
window.addEventListener('DOMContentLoaded', applyResponsiveScale);
window.addEventListener('resize', fitMobileAsciiLayout);
window.addEventListener('DOMContentLoaded', function() {
    setTimeout(fitMobileAsciiLayout, 0);
});
window.addEventListener('load', function() {
    setTimeout(fitMobileAsciiLayout, 0);
});

/* ==========================================================================
   SPA navigation and page lifecycle
   ========================================================================== */

// Load internal pages into #content
// so the sidebar and mini player stay mounted (continuous audio).
function getSpaLoadingEl() {
    let el = document.getElementById('spa-loading');
    if (!el) {
        el = document.createElement('div');
        el.id = 'spa-loading';
        el.textContent = 'loading...';
        document.body.appendChild(el);
    }
    return el;
}

function showSpaLoading() {
    try {
        getSpaLoadingEl().classList.add('visible');
    } catch (_) { /* no-op */ }
}

function hideSpaLoading() {
    try {
        const el = document.getElementById('spa-loading');
        if (el) el.classList.remove('visible');
    } catch (_) { /* no-op */ }
}

function isSpaEligibleLink(anchor) {
    if (!anchor) return false;
    const href = anchor.getAttribute('href') || '';
    if (!href || href === '#' || href.startsWith('mailto:') || href.startsWith('tel:')) return false;
    if (anchor.target && anchor.target === '_blank') return false;
    if (!href.startsWith('/')) return false; // same-origin path only
    // login should always be a full navigation so session cookies + redirects work
    if (href.startsWith('/account/login')) return false;
    // Always perform a full navigation for logout so that
    // server redirects (e.g. ?logged_out=1) are reflected in
    // the browser URL immediately.
    if (href.startsWith('/account/logout')) return false;
    if (href.startsWith('/api/')) return false; // API endpoints are not pages
    return true;
}

function isInternalWebsiteUrl(url) {
    try {
        const parsed = new URL(url, window.location.href);
        const host = parsed.hostname.toLowerCase();
        if (parsed.origin === window.location.origin) return true;
        return host === 'fridge.dev' || host === 'www.fridge.dev' || host === 'm.fridge.dev';
    } catch (_) {
        return true;
    }
}

function isExternalWebsiteLink(anchor) {
    if (!anchor || anchor.dataset.externalConfirmed === '1') return false;
    if (anchor.hasAttribute('data-no-external-popup')) return false;
    const href = anchor.getAttribute('href') || '';
    if (!href || href === '#') return false;
    if (/^(mailto|tel|sms|javascript):/i.test(href)) return false;
    if (href.startsWith('#')) return false;

    try {
        const parsed = new URL(href, window.location.href);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return false;
        return !isInternalWebsiteUrl(parsed.href);
    } catch (_) {
        return false;
    }
}

function openExternalWebsiteLink(anchor, event) {
    const href = anchor.href;
    const shouldOpenNewTab = anchor.target === '_blank' || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
    anchor.dataset.externalConfirmed = '1';

    if (shouldOpenNewTab) {
        window.open(href, '_blank', 'noopener,noreferrer');
        window.setTimeout(function() {
            delete anchor.dataset.externalConfirmed;
        }, 0);
        return;
    }

    window.location.href = href;
}

function syncSpaPageAssets(doc) {
    try {
        if (!doc || !doc.head || !document.head) return;
        doc.head.querySelectorAll('link[rel~="stylesheet"][href]').forEach((link) => {
            const href = link.href || link.getAttribute('href') || '';
            if (!href) return;
            const exists = Array.from(document.head.querySelectorAll('link[rel~="stylesheet"][href]'))
                .some(existing => existing.href === href);
            if (exists) return;
            document.head.appendChild(link.cloneNode(true));
        });

    } catch (_) { /* no-op */ }
}

const SPA_SHARED_SCRIPT_PATHS = new Set([
    '/main.js',
    '/js/settings.js',
    '/js/sidebar-player.js',
    '/js/bookmarks.js',
    '/js/bbcode.js',
    'cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js'
]);

function normalizeSpaScriptKey(src) {
    try {
        const url = new URL(src, window.location.href);
        if (url.hostname === 'cdnjs.cloudflare.com') {
            return url.hostname + url.pathname;
        }
        return url.pathname;
    } catch (_) {
        return String(src || '').split('?')[0].split('#')[0];
    }
}

function isAlreadyLoadedSharedScript(script) {
    const src = script && (script.src || script.getAttribute('src') || '');
    if (!src) return false;
    const key = normalizeSpaScriptKey(src);
    if (!SPA_SHARED_SCRIPT_PATHS.has(key)) return false;

    return Array.from(document.scripts).some(existing => {
        if (!existing || existing === script) return false;
        const existingSrc = existing.src || existing.getAttribute('src') || '';
        return existingSrc && normalizeSpaScriptKey(existingSrc) === key;
    });
}

function executeContentScripts(rootEl) {
    try {
        if (!rootEl) return;
        const scripts = rootEl.querySelectorAll('script');
        scripts.forEach((oldScript) => {
            if (!oldScript) return;
            if (isAlreadyLoadedSharedScript(oldScript)) {
                oldScript.remove();
                return;
            }
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach((attr) => {
                newScript.setAttribute(attr.name, attr.value);
            });
            if (oldScript.type) {
                newScript.type = oldScript.type;
            }
            if (!oldScript.src) {
                newScript.textContent = oldScript.textContent || '';
            }
            oldScript.replaceWith(newScript);
        });
    } catch (_) { /* no-op */ }
}

function updateContentFooterSpacing() {
    try {
        const containerEl = document.getElementById('container');
        const contentEl = document.getElementById('content');
        if (!containerEl || !contentEl) return;
        const isScrollable = containerEl.scrollHeight > (containerEl.clientHeight + 1);
        contentEl.classList.toggle('content-scrolls', isScrollable);
    } catch (_) { /* no-op */ }
}

let lastPageViewPathRequested = null;

function initContentPagination(root = document) {
    const scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('.content-pagination[data-pagination-total]').forEach(nav => {
        const current = Number(nav.dataset.paginationCurrent || 1);
        const total = Number(nav.dataset.paginationTotal || 1);
        const route = nav.dataset.paginationRoute || '';
        const search = nav.dataset.paginationSearch || '';
        if (!route || !Number.isInteger(current) || !Number.isInteger(total) || total < 2) return;

        const pageUrl = page => {
            const params = new URLSearchParams({ page: String(page) });
            if (search) params.set('q', search);
            return route + '?' + params.toString() + '#content-footer';
        };
        const tokenCount = pages => {
            let count = pages.length;
            for (let index = 1; index < pages.length; index++) {
                if (pages[index] - pages[index - 1] > 1) count++;
            }
            return count;
        };
        const render = () => {
            const width = nav.parentElement?.clientWidth || nav.clientWidth || 320;
            const capacity = Math.max(5, Math.floor((width + 4) / 34));
            nav.classList.toggle('is-filled', total + 2 >= capacity);
            const pageCapacity = Math.max(3, capacity - 2); // previous + next
            const selected = new Set([1, current, total]);
            const candidates = [];
            for (let page = 2; page < total; page++) {
                if (page !== current) candidates.push(page);
            }
            candidates.sort((a, b) => Math.abs(a - current) - Math.abs(b - current) || a - b);
            for (const page of candidates) {
                const trial = Array.from(new Set([...selected, page])).sort((a, b) => a - b);
                if (tokenCount(trial) > pageCapacity) continue;
                selected.add(page);
            }
            const pages = Array.from(selected).sort((a, b) => a - b);
            const parts = [];
            const arrow = (direction, page, disabled, label) => disabled
                ? `<span class="guestbook-page-btn pagination-arrow disabled" aria-hidden="true">${direction}</span>`
                : `<a class="guestbook-page-btn pagination-arrow" href="${pageUrl(page)}" aria-label="${label}">${direction}</a>`;
            parts.push(arrow('&lsaquo;', current - 1, current <= 1, 'previous page'));
            pages.forEach((page, index) => {
                if (index > 0 && page - pages[index - 1] > 1) parts.push('<span class="pagination-ellipsis" aria-hidden="true">&hellip;</span>');
                parts.push(page === current
                    ? `<span class="guestbook-page-btn current" aria-current="page">${page}</span>`
                    : `<a class="guestbook-page-btn" href="${pageUrl(page)}" aria-label="page ${page}">${page}</a>`);
            });
            parts.push(arrow('&rsaquo;', current + 1, current >= total, 'next page'));
            nav.innerHTML = parts.join('');
        };
        render();
        if (nav.dataset.paginationResizeBound !== '1' && typeof ResizeObserver === 'function') {
            nav.dataset.paginationResizeBound = '1';
            const resizeTarget = nav.parentElement || nav;
            let lastWidth = resizeTarget.clientWidth;
            new ResizeObserver(entries => {
                const width = Math.round(entries[0]?.contentRect?.width || resizeTarget.clientWidth);
                if (width === lastWidth) return;
                lastWidth = width;
                render();
            }).observe(resizeTarget);
        }
    });
}

function pinContentToBottomWhileMediaLoads() {
    const container = document.getElementById('container');
    const content = document.getElementById('content');
    if (!container || !content) return;
    let active = true;
    let pendingImages = 0;
    let finishTimer = 0;
    const pin = () => {
        if (!active) return;
        container.scrollTop = container.scrollHeight;
    };
    const finish = () => {
        window.clearTimeout(finishTimer);
        finishTimer = window.setTimeout(() => {
            if (pendingImages > 0) return;
            pin();
            active = false;
            observer?.disconnect();
        }, 500);
    };
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(pin) : null;
    if (observer) observer.observe(content);
    content.querySelectorAll('img').forEach(image => {
        if (!image.complete) {
            pendingImages++;
            const settled = () => {
                pendingImages = Math.max(0, pendingImages - 1);
                pin();
                if (pendingImages === 0) finish();
            };
            image.addEventListener('load', settled, { once: true });
            image.addEventListener('error', settled, { once: true });
        }
    });
    pin();
    window.setTimeout(pin, 50);
    window.setTimeout(pin, 250);
    if (pendingImages === 0) finish();
    window.setTimeout(() => {
        if (!active) return;
        pin();
        active = false;
        observer?.disconnect();
    }, 15000);
}

function normalizePageViewPath(rawUrl) {
    try {
        const base = window.location && window.location.origin ? window.location.origin : undefined;
        const urlObj = new URL(rawUrl || window.location.href, base);
        let path = urlObj.pathname || '/';
        path = path.replace(/\/+$/, '') || '/';
        path = path.replace(/\/index\.php$/i, '') || '/';
        if (!path.startsWith('/')) path = '/' + path;
        return path;
    } catch (_) {
        return '/';
    }
}

function updatePageViewFooter(rawUrl) {
    try {
        const footerViewsEl = document.getElementById('content-footer-views');
        if (!footerViewsEl) return;
        const path = normalizePageViewPath(rawUrl);
        if (!path || path.startsWith('/api/')) return;
        if (lastPageViewPathRequested === path) return;
        lastPageViewPathRequested = path;

        fetch('/api/page-view/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ path: path })
        })
            .then(resp => {
                if (!resp.ok) throw new Error('view count request failed');
                return resp.json();
            })
            .then(data => {
                const count = (data && typeof data.count === 'number') ? data.count : null;
                if (count === null) return;
                if (count === 1) {
                    footerViewsEl.textContent = "you're the first person to view this page!";
                } else {
                    footerViewsEl.textContent = count + ' views';
                }
            })
            .catch(() => {
                footerViewsEl.textContent = 'couldn\'t load page views';
            });
    } catch (_) { /* no-op */ }
}

function loadPageIntoContent(url, addToHistory = true) {
    try {
        const debugPath = (() => { try { return new URL(url, window.location.href).pathname; } catch (_) { return 'internal page'; } })();
        window.fridg3DebugClientLog(`SPA navigation started: ${debugPath}`);
        const contentEl = document.getElementById('content');
        if (!contentEl || !window.fetch || !window.DOMParser) {
            window.location.href = url;
            return;
        }

        closeMobileMenu();

        // Remove any currently visible tooltips when navigating
        clearTooltips();

        showSpaLoading();

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Fridg3-Page-Navigation': '1',
            },
        })
            .then(resp => {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                return resp.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.getElementById('content');
                if (!newContent) {
                    window.location.href = url;
                    return;
                }

                syncSpaPageAssets(doc);
                fridg3CollectServerDebugLogs(doc);
                contentEl.innerHTML = newContent.innerHTML;
                executeContentScripts(contentEl);

                const newTitle = doc.querySelector('title');
                if (newTitle) {
                    document.title = newTitle.textContent;
                }

                // Keep sidebar user greeting and footer buttons in sync
                // when navigating (e.g., after login/logout redirects).
                try {
                    const newGreeting = doc.getElementById('user-greeting');
                    const sidebarEl = document.getElementById('sidebar');
                    if (sidebarEl) {
                        const existingGreeting = sidebarEl.querySelector('#user-greeting');
                        const spacer = sidebarEl.querySelector('#sidebar-spacer');
                        if (newGreeting) {
                            const clonedGreeting = newGreeting.cloneNode(true);
                            if (existingGreeting) {
                                existingGreeting.replaceWith(clonedGreeting);
                            } else if (spacer && spacer.parentNode) {
                                spacer.parentNode.insertBefore(clonedGreeting, spacer);
                            }
                        } else if (existingGreeting) {
                            existingGreeting.remove();
                        }
                    }
                } catch (_) { /* no-op */ }

                try {
                    const newFooterButtons = doc.getElementById('footer-buttons');
                    const currentFooterButtons = document.getElementById('footer-buttons');
                    if (newFooterButtons && currentFooterButtons) {
                        currentFooterButtons.innerHTML = newFooterButtons.innerHTML;
                    }
                } catch (_) { /* no-op */ }

                syncAccountFooterButton();
                syncActiveChatSidebarButton();
                initSiteNotices(doc);
                initMissingDevDataPopup(doc);

                if (addToHistory && window.history && window.history.pushState) {
                    window.history.pushState({ spa: true, url: url }, '', url);
                }

                // Re-run per-page initializers for the new content.
                applyResponsiveScale();
                initSidebarAndBBCode();
                initFooterActiveState();
                initSidebarActiveState();
                initScrollAndBookmarkIcons();
                enhanceBookmarksPage();
                initMiniPlayer();
                if (typeof window.fridg3InitToastDiscordBotPage === 'function') {
                    window.fridg3InitToastDiscordBotPage();
                }
                initBBCodeEditor();
                initToastFeedGenerator();
                initAsciiUsage();
                setupSpaForms();
                if (typeof window.fridg3InitOffTopicArchive === 'function') {
                    window.fridg3InitOffTopicArchive();
                }
                initSettingsPage();
                syncOnekoPreference();
                initAsciiTime();
                autoScaleAsciiFont();
                rerunAsciiScalingAfterContent();
                refreshAsciiLayoutAfterFontLoad();
                initTooltips();
                updateContentFooterSpacing();
                initContentPagination(contentEl);
                fitMobileAsciiLayout();
                updatePageViewFooter(url);

                // Pagination is used from the bottom of feed/journal listings.
                // Keep that reading position after the SPA swaps in the next page.
                let requestedHash = '';
                try { requestedHash = new URL(url, window.location.href).hash; } catch (_) { /* no-op */ }
                if (requestedHash === '#content-footer') {
                    window.requestAnimationFrame(() => window.requestAnimationFrame(pinContentToBottomWhileMediaLoads));
                }

                // Re-run syntax highlighting on newly loaded content
                if (typeof hljs !== 'undefined') {
                    contentEl.querySelectorAll('pre code').forEach((block) => {
                        hljs.highlightElement(block);
                    });
                }
                window.fridg3DebugClientLog(`SPA navigation completed: ${debugPath}`);
            })
            .catch((error) => {
                window.fridg3DebugClientLog(`SPA navigation failed (${debugPath}): ${error.message || 'unknown error'}; using full navigation`);
                window.location.href = url;
            })
            .finally(() => {
                hideSpaLoading();
            });
    } catch (error) {
        window.fridg3DebugClientLog(`SPA navigation setup failed: ${error.message || 'unknown error'}`);
        hideSpaLoading();
        window.location.href = url;
    }
}

function setupSpaNavigation() {
    document.addEventListener('click', function(e) {
        if (e.target.closest('.feed-audio-note')) return;
        // Let dedicated handlers take over for feed edit icons
        if (e.target.closest('#post-edit-feed')) return;

        const anchor = e.target.closest('a');
        if (!anchor) return;
        if (isExternalWebsiteLink(anchor)) {
            e.preventDefault();
            const url = anchor.href;
            showSitePopup({
                title: 'leaving fridge.dev',
                html: '<p>this link opens an external site.</p><p>' + siteEscapeHtml(url) + '</p>',
                okText: 'open link',
                cancelText: 'stay here'
            }).then(function(confirmed) {
                if (!confirmed) return;
                openExternalWebsiteLink(anchor, e);
            });
            return;
        }
        if (!isSpaEligibleLink(anchor)) return;
        // Allow default for modifier keys (open in new tab/window)
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        const href = anchor.getAttribute('href');
        if (!href) return;

        e.preventDefault();
        loadPageIntoContent(href, true);
    });

    window.addEventListener('popstate', function(ev) {
        try {
            const state = ev.state || {};
            const url = state.url || (window.location.pathname + window.location.search);
            loadPageIntoContent(url, false);
        } catch (_) { /* no-op */ }
    });
}

window.addEventListener('DOMContentLoaded', setupSpaNavigation);
window.addEventListener('DOMContentLoaded', () => initContentPagination(document));
window.addEventListener('DOMContentLoaded', updateContentFooterSpacing);
window.addEventListener('DOMContentLoaded', function() { updatePageViewFooter(window.location.href); });
window.addEventListener('load', updateContentFooterSpacing);
window.addEventListener('resize', updateContentFooterSpacing);

document.addEventListener('submit', function(e) {
    const form = e.target && e.target.closest ? e.target.closest('.chat-delete-form, [data-site-confirm]') : null;
    if (!form || form.dataset.confirmed === '1') return;
    e.preventDefault();

    const isChatDelete = form.classList.contains('chat-delete-form');
    const html = form.getAttribute('data-confirm-html') || (isChatDelete
        ? '<p>this deletes the encrypted conversation file and its attachments from the server immediately.</p><p>there is no undo button hiding in the couch.</p>'
        : '');
    showSitePopup({
        title: form.getAttribute('data-confirm-title') || (isChatDelete ? 'end chat?' : 'are you sure?'),
        html: html || undefined,
        detail: html ? undefined : (form.getAttribute('data-confirm-detail') || ''),
        okText: form.getAttribute('data-confirm-text') || (isChatDelete ? 'end chat' : 'delete'),
        cancelText: form.getAttribute('data-cancel-text') || (isChatDelete ? 'keep chat' : 'cancel')
    }).then(function(confirmed) {
        if (!confirmed) return;
        const submitConfirmedForm = function() {
            if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
        };

        const continueAfterPassword = function() {
            form.dataset.confirmed = '1';

            if (form.getAttribute('data-delete-animation') === 'account-rip') {
                playAccountDeleteRipAnimation(form).then(submitConfirmedForm).catch(submitConfirmedForm);
                return;
            }

            submitConfirmedForm();
        };

        if (form.getAttribute('data-admin-password-confirm') === '1') {
            showSitePopup({
                title: form.getAttribute('data-password-title') || 'confirm destructive action',
                detail: form.getAttribute('data-password-detail') || 'enter your admin password to continue.',
                input: true,
                inputType: 'password',
                okText: 'continue',
                cancelText: 'cancel'
            }).then(function(password) {
                if (password === null) return;
                let input = form.querySelector('input[name="admin_password"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'admin_password';
                    form.appendChild(input);
                }
                input.value = password;
                continueAfterPassword();
            });
            return;
        }

        continueAfterPassword();
    });
});

/* ==========================================================================
   Account, feed, and chat interactions
   ========================================================================== */

function playAccountDeleteRipAnimation(form) {
    return new Promise(function(resolve) {
        try {
            if (!form || document.querySelector('.account-rip-overlay')) {
                resolve();
                return;
            }

            const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const username = form.getAttribute('data-account-username') || 'unknown';
            const name = form.getAttribute('data-account-name') || '';
            const isAdmin = form.getAttribute('data-account-is-admin') === '1';
            const pages = (form.getAttribute('data-account-pages') || '').split(',').filter(Boolean);
            const overlay = document.createElement('div');
            overlay.className = 'account-rip-overlay';
            overlay.setAttribute('aria-hidden', 'true');

            const stage = document.createElement('div');
            stage.className = 'account-rip-stage';

            const buildCard = function(extraClass) {
                const card = document.createElement('div');
                card.className = extraClass + ' account-admin-card';

                const strong = document.createElement('strong');
                strong.textContent = '@' + username;
                card.appendChild(strong);

                const nameEl = document.createElement('span');
                nameEl.textContent = name;
                card.appendChild(nameEl);

                const meta = document.createElement('span');
                meta.className = 'account-admin-meta';
                if (isAdmin) {
                    const badge = document.createElement('span');
                    badge.className = 'account-admin-badge';
                    badge.textContent = 'admin';
                    meta.appendChild(badge);
                }
                pages.forEach(function(page) {
                    const badge = document.createElement('span');
                    badge.className = 'account-page-badge';
                    badge.textContent = page;
                    meta.appendChild(badge);
                });
                if (!meta.children.length) {
                    meta.textContent = 'no extra perms set';
                }
                card.appendChild(meta);
                return card;
            };

            const wholeCard = buildCard('account-rip-card account-rip-card-whole');
            const leftHalf = buildCard('account-rip-card account-rip-half account-rip-left');
            const rightHalf = buildCard('account-rip-card account-rip-half account-rip-right');
            const tearLine = document.createElement('div');
            tearLine.className = 'account-rip-tear-line';

            const scrapWrap = document.createElement('div');
            scrapWrap.className = 'account-rip-scraps';
            for (let i = 0; i < 28; i++) {
                const scrap = document.createElement('i');
                scrap.style.setProperty('--x', String(Math.round((Math.random() - 0.5) * 280)) + 'px');
                scrap.style.setProperty('--y', String(Math.round(110 + Math.random() * 210)) + 'px');
                scrap.style.setProperty('--r', String(Math.round((Math.random() - 0.5) * 520)) + 'deg');
                scrap.style.setProperty('--d', String((0.44 + Math.random() * 0.48).toFixed(2)) + 's');
                scrap.style.setProperty('--s', String((0.55 + Math.random() * 1.05).toFixed(2)));
                scrapWrap.appendChild(scrap);
            }

            stage.appendChild(wholeCard);
            stage.appendChild(leftHalf);
            stage.appendChild(rightHalf);
            stage.appendChild(tearLine);
            stage.appendChild(scrapWrap);
            overlay.appendChild(stage);
            document.body.appendChild(overlay);

            if (prefersReducedMotion) {
                window.setTimeout(resolve, 420);
                return;
            }

            window.setTimeout(function() {
                overlay.classList.add('is-ripping');
            }, 80);
            window.setTimeout(resolve, 2600);
        } catch (_) {
            resolve();
        }
    });
}

// Dedicated handler for feed edit icons so clicking the pencil goes
// to the edit view without breaking SPA navigation or bubbling to the
// outer feed-post link.
document.addEventListener('click', function(e) {
    const editEl = e.target.closest('#post-edit-feed');
    if (!editEl) return;
    const href = editEl.getAttribute('data-edit-href');
    if (!href) return;
    e.preventDefault();
    e.stopPropagation();
    if (typeof loadPageIntoContent === 'function') {
        loadPageIntoContent(href, true);
    } else {
        window.location.href = href;
    }
});

document.addEventListener('click', function(e) {
    const copyEl = e.target.closest('.chat-copy-link[data-copy-url]');
    if (!copyEl) return;

    e.preventDefault();
    const url = copyEl.getAttribute('data-copy-url') || copyEl.textContent.trim();
    const originalText = copyEl.textContent;

    const markCopied = function() {
        copyEl.textContent = 'copied';
        copyEl.classList.add('copied');
        window.setTimeout(function() {
            copyEl.textContent = originalText;
            copyEl.classList.remove('copied');
        }, 1400);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(markCopied).catch(function() {
            showSitePrompt('copy chat link', 'clipboard access failed. grab the link below.', url);
        });
        return;
    }

    showSitePrompt('copy chat link', 'grab the link below.', url);
});

/* ==========================================================================
   SPA-aware forms and page initializers
   ========================================================================== */

// Intercept specific forms (login, create post) and submit via fetch
// so the page content updates without a full reload, keeping the
// mini player and sidebar alive.
function bindSpaForm(form) {
    if (!form || form.dataset.spaBound === '1') return;
    form.dataset.spaBound = '1';

    form.addEventListener('submit', function(e) {
        if (e.defaultPrevented) return;

        const method = (form.getAttribute('method') || 'GET').toUpperCase();
        const action = form.getAttribute('action') || (window.location.pathname + window.location.search);

        // Let the browser handle non-POST/GET or external actions.
        if ((method !== 'POST' && method !== 'GET') || !action.startsWith('/')) {
            return;
        }

        e.preventDefault();
        const debugAction = (() => { try { return new URL(action, window.location.href).pathname; } catch (_) { return 'internal form'; } })();
        window.fridg3DebugClientLog(`SPA form submission started: ${method} ${debugAction}`);

        const contentEl = document.getElementById('content');
        if (!contentEl || !window.fetch || !window.DOMParser) {
            form.submit();
            return;
        }

        let waitPopup = null;
        if (e.submitter && e.submitter.hasAttribute('data-post-submit-wait')) {
            showSitePopup({
                title: 'please wait...',
                detail: "your post is being uploaded to the website, this shouldn't take long.",
                noButtons: true
            });
            waitPopup = document.querySelector('.site-popup-overlay:last-of-type');
        }

        const formData = new FormData(form);
        if (typeof window.fridg3AppendBBCodeUploadFiles === 'function') {
            window.fridg3AppendBBCodeUploadFiles(formData, form);
        }

        // Ensure the clicked submit button's name/value (e.g., delete=1)
        // are included in the payload so multi-action forms work.
        if (e.submitter && e.submitter.name) {
            formData.append(e.submitter.name, e.submitter.value != null ? e.submitter.value : '');
        }

        fetch(action, {
            method,
            body: method === 'GET' ? null : formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Fridg3-Page-Navigation': '1',
            },
            credentials: 'same-origin',
        })
            .then(resp => {
                if (!resp.ok) {
                    window.fridg3DebugClientLog(`SPA form returned HTTP ${resp.status}; using full navigation`);
                    // Fallback to normal navigation on error
                    window.location.href = action;
                    return null;
                }
                return resp.text().then(html => ({ html, finalUrl: resp.url || action }));
            })
            .then(payload => {
                if (!payload) return;
                const { html, finalUrl } = payload;

                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.getElementById('content');
                if (!newContent) {
                    window.location.href = finalUrl;
                    return;
                }

                syncSpaPageAssets(doc);
                fridg3CollectServerDebugLogs(doc);
                contentEl.innerHTML = newContent.innerHTML;
                executeContentScripts(contentEl);

                const newTitle = doc.querySelector('title');
                if (newTitle) {
                    document.title = newTitle.textContent;
                }

                // Update user greeting based on new HTML (for login/logout).
                try {
                    const newGreeting = doc.getElementById('user-greeting');
                    const sidebarEl = document.getElementById('sidebar');
                    if (sidebarEl) {
                        const existingGreeting = sidebarEl.querySelector('#user-greeting');
                        const spacer = sidebarEl.querySelector('#sidebar-spacer');
                        if (newGreeting) {
                            const clonedGreeting = newGreeting.cloneNode(true);
                            if (existingGreeting) {
                                existingGreeting.replaceWith(clonedGreeting);
                            } else if (spacer && spacer.parentNode) {
                                spacer.parentNode.insertBefore(clonedGreeting, spacer);
                            }
                        } else if (existingGreeting) {
                            existingGreeting.remove();
                        }
                    }
                } catch (_) { /* no-op */ }

                // Update footer buttons (Account → Logout, etc.).
                try {
                    const newFooterButtons = doc.getElementById('footer-buttons');
                    const currentFooterButtons = document.getElementById('footer-buttons');
                    if (newFooterButtons && currentFooterButtons) {
                        currentFooterButtons.innerHTML = newFooterButtons.innerHTML;
                    }
                } catch (_) { /* no-op */ }

                syncAccountFooterButton();
                syncActiveChatSidebarButton();
                initSiteNotices(doc);
                initMissingDevDataPopup(doc);

                if (window.history && window.history.pushState) {
                    window.history.pushState({ spa: true, url: finalUrl }, '', finalUrl);
                }

                // Re-run initializers for new content
                applyResponsiveScale();
                initSidebarAndBBCode();
                initFooterActiveState();
                initSidebarActiveState();
                initScrollAndBookmarkIcons();
                enhanceBookmarksPage();
                setupSpaForms();
                initMiniPlayer();
                ensureToastLiveControlsOnLoad();
                initBBCodeEditor();
                initToastFeedGenerator();
                initAsciiUsage();
                initAsciiTime();
                if (typeof window.fridg3InitOffTopicArchive === 'function') {
                    window.fridg3InitOffTopicArchive();
                }
                initSettingsPage();
                syncOnekoPreference();
                rerunAsciiScalingAfterContent();
                initTooltips();
                fitMobileAsciiLayout();

                // Re-run syntax highlighting on newly loaded content
                if (typeof hljs !== 'undefined') {
                    contentEl.querySelectorAll('pre code').forEach((block) => {
                        hljs.highlightElement(block);
                    });
                }
                window.fridg3DebugClientLog(`SPA form submission completed: ${debugAction}`);
            })
            .catch((error) => {
                window.fridg3DebugClientLog(`SPA form submission failed (${debugAction}): ${error.message || 'unknown error'}`);
                window.location.href = action;
            })
            .finally(() => {
                if (waitPopup) waitPopup.remove();
            });
    });
}

function showToastAdminLoginPopup() {
    return new Promise(function(resolve) {
        const overlay = document.createElement('div');
        overlay.className = 'site-popup-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        const dialog = document.createElement('div');
        dialog.className = 'site-popup-dialog';

        const title = document.createElement('div');
        title.className = 'site-popup-title';
        title.textContent = 'toast login';

        const detail = document.createElement('div');
        detail.className = 'site-popup-detail';
        detail.textContent = 'enter an admin account to wake toast up.';

        const username = document.createElement('input');
        username.className = 'site-popup-input';
        username.type = 'text';
        username.placeholder = 'admin username';
        username.autocomplete = 'username';

        const password = document.createElement('input');
        password.className = 'site-popup-input';
        password.type = 'password';
        password.placeholder = 'admin password';
        password.autocomplete = 'current-password';

        const actions = document.createElement('div');
        actions.className = 'site-popup-actions';

        const cancel = document.createElement('button');
        cancel.className = 'site-popup-button site-popup-cancel';
        cancel.type = 'button';
        cancel.textContent = 'cancel';

        const ok = document.createElement('button');
        ok.className = 'site-popup-button site-popup-ok';
        ok.type = 'button';
        ok.textContent = 'login';

        actions.append(cancel, ok);
        dialog.append(title, detail, username, password, actions);
        overlay.append(dialog);

        const close = function(value) {
            document.removeEventListener('keydown', onKeydown);
            overlay.classList.add('is-closing');
            window.setTimeout(function() { overlay.remove(); }, 160);
            resolve(value);
        };

        const submit = function() {
            close({
                username: username.value.trim(),
                password: password.value
            });
        };

        const onKeydown = function(event) {
            if (event.key === 'Escape') close(null);
            if (event.key === 'Enter') submit();
        };

        cancel.addEventListener('click', function() { close(null); });
        ok.addEventListener('click', submit);
        overlay.addEventListener('click', function(event) {
            if (event.target === overlay) close(null);
        });
        document.addEventListener('keydown', onKeydown);
        document.body.append(overlay);
        username.focus();
    });
}

function initLoginPage() {
    const form = document.getElementById('login-form');
    if (!form || form.dataset.loginBound === '1') return;
    form.dataset.loginBound = '1';

    const usernameInput = form.querySelector('input[name="username"]');
    const errorSpan = document.getElementById('error');
    const contentDiv = document.getElementById('content');
    if (!usernameInput || !errorSpan) return;

    if (contentDiv && contentDiv.getAttribute('data-login-info')) {
        const msg = contentDiv.getAttribute('data-login-info');
        if (msg) {
            errorSpan.textContent = msg;
            errorSpan.style.color = 'rgb(120, 200, 120)';
        }
        contentDiv.removeAttribute('data-login-info');
    }

    if (contentDiv && contentDiv.getAttribute('data-login-error')) {
        const loginError = contentDiv.getAttribute('data-login-error');
        errorSpan.textContent = loginError;
        errorSpan.style.color = 'red';
        contentDiv.removeAttribute('data-login-error');

        if (contentDiv.getAttribute('data-login-maintenance-denied') === '1') {
            showSitePopup({
                title: 'maintenance mode',
                detail: loginError || 'you must be an administrator to log in while the website is undergoing maintenance.',
                okText: 'got it'
            });
        }
        contentDiv.removeAttribute('data-login-maintenance-denied');
    }

    form.addEventListener('submit', function(e) {
        errorSpan.textContent = '';

        if (!usernameInput.value.trim()) {
            e.preventDefault();
            errorSpan.textContent = 'Please enter a username.';
            errorSpan.style.color = 'red';
            return;
        }

        if (usernameInput.value.trim().toLowerCase() === 'toast' && form.dataset.toastAdminReady !== '1') {
            e.preventDefault();
            showToastAdminLoginPopup().then(function(credentials) {
                if (!credentials) return;
                if (!credentials.username) {
                    errorSpan.textContent = 'admin username required.';
                    errorSpan.style.color = 'red';
                    return;
                }

                const adminUsernameInput = form.querySelector('input[name="toast_admin_username"]');
                const adminPasswordInput = form.querySelector('input[name="toast_admin_password"]');
                if (adminUsernameInput) adminUsernameInput.value = credentials.username;
                if (adminPasswordInput) adminPasswordInput.value = credentials.password;
                form.dataset.toastAdminReady = '1';
                form.submit();
            });
        }
    });
}

window.initLoginPage = initLoginPage;

function setupSpaForms() {
    try {
        initLoginPage();

        const loginForm = document.getElementById('login-form');
        // Login should perform a full POST + redirect so that
        // session cookies and redirects behave exactly as the
        // server expects. Forms marked with data-no-spa are
        // intentionally excluded from SPA interception.
        if (loginForm && !loginForm.dataset.noSpa) bindSpaForm(loginForm);

        const createPostForm = document.getElementById('create-post-form');
        if (createPostForm) {
            const rawPath = (window.location && window.location.pathname) ? window.location.pathname : '/';
            const path = rawPath.replace(/\/+$/, '') || '/';
            if (path === '/feed/create' || path === '/feed/create/index.php') {
                bindFeedNotificationSubmitPrompt(createPostForm, 'post');
            }
            bindSpaForm(createPostForm);
        }

        const feedReplyForm = document.getElementById('feed-reply-form');
        if (feedReplyForm) {
            bindFeedNotificationSubmitPrompt(feedReplyForm, 'comment');
            bindSpaForm(feedReplyForm);
        }
        fillGuestBrowserIdInputs();
        initFeedReplyTargets();
        startFeedNotificationPolling();
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', setupSpaForms);

function initFeedReplyTargets() {
    const form = document.getElementById('feed-reply-form');
    if (!form || form.dataset.replyTargetsInitialized === '1') return;
    form.dataset.replyTargetsInitialized = '1';

    const parentInput = form.querySelector('[data-feed-reply-parent-input]');
    const targetBox = form.querySelector('[data-feed-reply-target]');
    const textbox = form.querySelector('.feed-reply-textbox');
    if (!parentInput || !targetBox) return;

    const postPath = window.location.pathname || form.getAttribute('action') || '';
    const clearTarget = () => {
        parentInput.value = '';
        targetBox.hidden = true;
        targetBox.innerHTML = '';
        if (window.history && window.history.replaceState) {
            window.history.replaceState(window.history.state, '', postPath);
        }
    };

    document.querySelectorAll('[data-feed-reply-to]').forEach(button => {
        if (button.dataset.replyTargetBound === '1') return;
        button.dataset.replyTargetBound = '1';
        button.addEventListener('click', event => {
            event.preventDefault();
            const replyId = button.getAttribute('data-feed-reply-to') || '';
            const username = button.getAttribute('data-feed-reply-user') || 'Anonymous';
            if (!replyId) return;
            parentInput.value = replyId;
            targetBox.hidden = false;
            targetBox.innerHTML = `replying to <strong>${siteEscapeHtml(username)}</strong> <a href="${siteEscapeHtml(postPath)}" data-feed-reply-cancel>cancel</a>`;
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (textbox) textbox.focus();
            if (window.history && window.history.replaceState) {
                window.history.replaceState(window.history.state, '', `${postPath}?reply_to=${encodeURIComponent(replyId)}#feed-reply-form`);
            }
        });
    });

    targetBox.addEventListener('click', event => {
        const cancel = event.target.closest('[data-feed-reply-cancel]');
        if (!cancel) return;
        event.preventDefault();
        clearTarget();
    });
}
