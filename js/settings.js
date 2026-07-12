// Toggleable glow settings
const GLOW_DEFAULT_INTENSITY = 'none';
const GLOW_INTENSITY_KEY = 'glowIntensity';
const GLOW_RADIUS_DEFAULT = '8px';
const GLOW_CLASS = 'glow-enabled';
const GLOW_STYLE_ID = 'glow-style-tag';
const THEME_PREF_KEY = 'themePref';
const THEME_COOKIE = 'theme_pref';
const COLOR_PREFS_KEY = 'colorPrefs';
const COLOR_FIELDS = ['bg', 'fg', 'border', 'subtle', 'links'];
const THEME_COLOR_FIELDS = {
    classic: COLOR_FIELDS,
    ambercrt: ['links'],
};
const COLOR_DEFAULTS = {
    bg: '#000000',
    fg: '#EEEEEE',
    border: '#3C7895',
    subtle: '#917DAA',
    links: '#415FAD',
};
const THEME_COLOR_DEFAULTS = {
    classic: COLOR_DEFAULTS,
    ambercrt: {
        links: '#FFB84D',
    },
};
const MOBILE_VIEW_COOKIE = 'mobile_friendly_view';
const MOBILE_VIEW_DOMAIN = '.fridge.dev';
const ONEKO_ENABLED_KEY = 'onekoEnabled';
const BROWSER_NOTIFICATIONS_ENABLED_KEY = 'browserNotificationsEnabled';
const JOURNAL_BROWSER_NOTIFICATIONS_ENABLED_KEY = 'journalBrowserNotificationsEnabled';
const FEED_NOTIFICATION_SEEN_KEY = 'feedNotificationSeenKeys';
const FEED_GUEST_BROWSER_ID_KEY = 'feedGuestBrowserId';
const FEED_NOTIFICATION_PROMPT_SEEN_KEY = 'feedNotificationPromptSeen';
const FEED_NOTIFICATION_POLL_MS = 30000;
const ACCESSIBILITY_PREFS_KEY = 'accessibilityPrefs';
const ACCESSIBILITY_DEFAULTS = {
    reduceMotion: false,
};
const ONEKO_ASSET_URL = '/resources/oneko.gif';
const ONEKO_SIZE = 32;
const ONEKO_SPEED = 10;
const ONEKO_FRAME_MS = 100;
const ONEKO_SLEEP_AFTER_MS = 15000;
const ONEKO_SLEEP_FRAME_TICKS = 4;
let onekoController = null;
let feedNotificationPollTimer = null;

// Apply saved color prefs early on page load for themes that expose color controls.
(() => {
    const activeTheme = loadLocalThemePref();
    if (!themeSupportsColorPrefs(activeTheme)) {
        clearColorVars();
        return;
    }
    const localColors = loadLocalColorPrefs();
    if (localColors) {
        const merged = Object.assign({}, getThemeColorDefaults(activeTheme), localColors);
        applyColorVars(merged, getThemeColorFields(activeTheme));
    }
})();

function themeSupportsColorPrefs(theme) {
    return Object.prototype.hasOwnProperty.call(THEME_COLOR_FIELDS, normalizeTheme(theme));
}

function getThemeColorFields(theme) {
    return THEME_COLOR_FIELDS[normalizeTheme(theme)] || [];
}

function getThemeColorDefaults(theme) {
    return THEME_COLOR_DEFAULTS[normalizeTheme(theme)] || {};
}

function applyColorVars(colors, fields = COLOR_FIELDS) {
    if (!colors) return;
    const root = document.documentElement;
    fields.forEach(key => {
        if (colors[key]) {
            root.style.setProperty(`--${key}`, colors[key]);
        }
    });
}

function clearColorVars() {
    const root = document.documentElement;
    COLOR_FIELDS.forEach(key => {
        root.style.removeProperty(`--${key}`);
    });
}

function normalizeTheme(theme) {
    if (typeof theme !== 'string') return 'default';
    const normalized = theme.trim().toLowerCase();
    if (normalized === '' || normalized === 'default') return 'default';
    if (normalized === 'blackprint') return 'default';
    if (normalized === 'crt') return 'ambercrt';
    if (normalized === 'liminal') return 'default';
    if (normalized === 'custom') return 'classic';
    if (normalized === 'newsprint') return 'whiteprint';
    if (/^[a-z0-9_-]+$/.test(normalized)) return normalized;
    return 'default';
}

function loadLocalThemePref() {
    try {
        const stored = normalizeTheme(localStorage.getItem(THEME_PREF_KEY));
        if (stored !== 'default') return stored;
    } catch (_) {
        /* ignore */
    }
    return normalizeTheme(getCookie(THEME_COOKIE));
}

function saveLocalThemePref(theme) {
    try {
        localStorage.setItem(THEME_PREF_KEY, normalizeTheme(theme));
    } catch (_) { /* ignore */ }
}

function normalizeColor(hex) {
    if (typeof hex !== 'string') return null;
    const v = hex.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(v)) return v.toUpperCase();
    return null;
}

function loadLocalColorPrefs() {
    try {
        const raw = localStorage.getItem(COLOR_PREFS_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return null;
        const out = {};
        COLOR_FIELDS.forEach(k => {
            const n = normalizeColor(parsed[k]);
            if (n) out[k] = n;
        });
        return Object.keys(out).length ? out : null;
    } catch (_) {
        return null;
    }
}

function saveLocalColorPrefs(colors) {
    try {
        localStorage.setItem(COLOR_PREFS_KEY, JSON.stringify(colors));
    } catch (_) { /* ignore */ }
}

function getCookie(name) {
    try {
        const parts = document.cookie ? document.cookie.split('; ') : [];
        for (const part of parts) {
            const eqIndex = part.indexOf('=');
            const key = eqIndex >= 0 ? part.slice(0, eqIndex) : part;
            if (key === name) {
                return eqIndex >= 0 ? decodeURIComponent(part.slice(eqIndex + 1)) : '';
            }
        }
    } catch (_) { /* ignore */ }
    return null;
}

function shouldUseSharedFridg3CookieDomain() {
    const host = ((window.location && window.location.hostname) ? window.location.hostname : '').toLowerCase();
    return host === 'fridge.dev' || host === 'm.fridge.dev' || host.endsWith('.fridge.dev');
}

function setMobileViewCookie(enabled) {
    try {
        const maxAge = 60 * 60 * 24 * 365;
        const value = enabled ? '1' : '0';
        const secure = (window.location && window.location.protocol === 'https:') ? '; Secure' : '';
        const domain = shouldUseSharedFridg3CookieDomain() ? `; Domain=${MOBILE_VIEW_DOMAIN}` : '';
        document.cookie = `${MOBILE_VIEW_COOKIE}=${value}; Max-Age=${maxAge}; Path=/; SameSite=Lax${domain}${secure}`;
    } catch (_) { /* ignore */ }
}

function readLocalOnekoEnabled() {
    try {
        const value = localStorage.getItem(ONEKO_ENABLED_KEY);
        if (value === null) return false;
        return ['1', 'true', 'yes', 'y', 'on', 'enabled'].includes(String(value).trim().toLowerCase());
    } catch (_) {
        return false;
    }
}

function saveLocalOnekoEnabled(enabled) {
    try {
        localStorage.setItem(ONEKO_ENABLED_KEY, enabled ? '1' : '0');
    } catch (_) { /* ignore */ }
}

function readLocalBrowserNotificationsEnabled() {
    try {
        const value = localStorage.getItem(BROWSER_NOTIFICATIONS_ENABLED_KEY);
        if (value === null) return false;
        return ['1', 'true', 'yes', 'y', 'on', 'enabled'].includes(String(value).trim().toLowerCase());
    } catch (_) {
        return false;
    }
}

function saveLocalBrowserNotificationsEnabled(enabled) {
    try {
        localStorage.setItem(BROWSER_NOTIFICATIONS_ENABLED_KEY, enabled ? '1' : '0');
    } catch (_) { /* ignore */ }
}

function readLocalJournalBrowserNotificationsEnabled() {
    try {
        const value = localStorage.getItem(JOURNAL_BROWSER_NOTIFICATIONS_ENABLED_KEY);
        if (value === null) return false;
        return ['1', 'true', 'yes', 'y', 'on', 'enabled'].includes(String(value).trim().toLowerCase());
    } catch (_) {
        return false;
    }
}

function saveLocalJournalBrowserNotificationsEnabled(enabled) {
    try {
        localStorage.setItem(JOURNAL_BROWSER_NOTIFICATIONS_ENABLED_KEY, enabled ? '1' : '0');
    } catch (_) { /* ignore */ }
}

function hasAnyBrowserNotificationChannelEnabled() {
    return readLocalBrowserNotificationsEnabled() || readLocalJournalBrowserNotificationsEnabled();
}

function readFeedNotificationPromptSeen(kind) {
    try {
        const parsed = JSON.parse(localStorage.getItem(FEED_NOTIFICATION_PROMPT_SEEN_KEY) || '{}');
        return !!(parsed && parsed[kind]);
    } catch (_) {
        return false;
    }
}

function saveFeedNotificationPromptSeen(kind) {
    try {
        const parsed = JSON.parse(localStorage.getItem(FEED_NOTIFICATION_PROMPT_SEEN_KEY) || '{}');
        const next = parsed && typeof parsed === 'object' ? parsed : {};
        next[kind] = true;
        localStorage.setItem(FEED_NOTIFICATION_PROMPT_SEEN_KEY, JSON.stringify(next));
    } catch (_) { /* ignore */ }
}

function getFeedGuestBrowserId() {
    try {
        let id = localStorage.getItem(FEED_GUEST_BROWSER_ID_KEY);
        if (/^[a-f0-9]{32}$/i.test(id || '')) {
            return String(id).toLowerCase();
        }
        const bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
            id = Array.from(bytes).map(byte => byte.toString(16).padStart(2, '0')).join('');
        } else {
            id = Array.from({ length: 32 }, () => Math.floor(Math.random() * 16).toString(16)).join('');
        }
        localStorage.setItem(FEED_GUEST_BROWSER_ID_KEY, id);
        return id;
    } catch (_) {
        return '';
    }
}

function readFeedNotificationSeenKeys() {
    try {
        const parsed = JSON.parse(localStorage.getItem(FEED_NOTIFICATION_SEEN_KEY) || '[]');
        return new Set(Array.isArray(parsed) ? parsed.map(String) : []);
    } catch (_) {
        return new Set();
    }
}

function saveFeedNotificationSeenKeys(keys) {
    try {
        localStorage.setItem(FEED_NOTIFICATION_SEEN_KEY, JSON.stringify(Array.from(keys).slice(-1000)));
    } catch (_) { /* ignore */ }
}

async function fetchFeedNotificationEvents() {
    if (!window.fetch) return [];
    const params = new URLSearchParams();
    if (!document.getElementById('user-greeting')) {
        const guestBrowserId = getFeedGuestBrowserId();
        if (guestBrowserId) params.append('guestBrowserId', guestBrowserId);
    }
    const url = params.toString() ? `/api/feed-notifications?${params.toString()}` : '/api/feed-notifications';
    const res = await fetch(url, {
        method: 'GET',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) return [];
    const data = await res.json().catch(() => null);
    return data && data.ok && Array.isArray(data.events) ? data.events : [];
}

async function baselineFeedNotifications() {
    const events = await fetchFeedNotificationEvents();
    const seen = readFeedNotificationSeenKeys();
    events.forEach(event => {
        if (event && event.key) seen.add(String(event.key));
    });
    saveFeedNotificationSeenKeys(seen);
}

function showFeedBrowserNotification(event) {
    if (!event || !event.key || !('Notification' in window) || Notification.permission !== 'granted') return;
    try {
        const notification = new Notification(event.title || 'fridge.dev', {
            body: event.body || '',
            tag: event.key,
        });
        notification.onclick = () => {
            window.focus();
            if (event.url) window.location.href = event.url;
            notification.close();
        };
    } catch (_) { /* ignore */ }
}

async function pollFeedNotifications() {
    if (!hasAnyBrowserNotificationChannelEnabled()) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const events = await fetchFeedNotificationEvents();
    const seen = readFeedNotificationSeenKeys();
    let changed = false;
    events.forEach(event => {
        const key = event && event.key ? String(event.key) : '';
        const type = event && event.type ? String(event.type) : 'feed';
        if (type === 'journal' && !readLocalJournalBrowserNotificationsEnabled()) return;
        if (type !== 'journal' && !readLocalBrowserNotificationsEnabled()) return;
        if (!key || seen.has(key)) return;
        seen.add(key);
        changed = true;
        showFeedBrowserNotification(event);
    });
    if (changed) saveFeedNotificationSeenKeys(seen);
}

function startFeedNotificationPolling() {
    if (feedNotificationPollTimer !== null) {
        window.clearInterval(feedNotificationPollTimer);
        feedNotificationPollTimer = null;
    }
    if (!hasAnyBrowserNotificationChannelEnabled()) return;
    pollFeedNotifications();
    feedNotificationPollTimer = window.setInterval(pollFeedNotifications, FEED_NOTIFICATION_POLL_MS);
}

async function setBrowserNotificationsEnabled(enabled, opts = {}) {
    if (!enabled) {
        if (feedNotificationPollTimer !== null) {
            window.clearInterval(feedNotificationPollTimer);
            feedNotificationPollTimer = null;
        }
        return false;
    }
    if (!('Notification' in window)) {
        if (opts.report !== false) await showSiteNotice('browser notifications unavailable', 'this browser does not support notifications.');
        return false;
    }
    let permission = Notification.permission;
    if (permission === 'default') {
        permission = await Notification.requestPermission();
    }
    if (permission !== 'granted') {
        if (opts.report !== false) await showSiteNotice('browser notifications blocked', 'enable notifications in your browser to use this.');
        return false;
    }
    if (opts.baseline !== false) {
        await baselineFeedNotifications();
    }
    startFeedNotificationPolling();
    return true;
}

function fillGuestBrowserIdInputs() {
    if (document.getElementById('user-greeting')) return;
    const inputs = document.querySelectorAll('[data-feed-guest-browser-id]');
    if (!inputs.length) return;
    const guestBrowserId = getFeedGuestBrowserId();
    inputs.forEach(input => {
        input.value = guestBrowserId;
    });
}

function shouldPromptForFeedNotifications(kind) {
    if (readLocalBrowserNotificationsEnabled()) return false;
    return !readFeedNotificationPromptSeen(kind);

}

function saveBrowserNotificationsAccountPreference(feedEnabled, journalEnabled = null) {
    if (!document.getElementById('user-greeting') || !window.fetch) {
        return Promise.resolve(false);
    }
    const params = new URLSearchParams();
    params.append('browserNotificationsEnabled', feedEnabled ? 'on' : 'off');
    if (journalEnabled !== null) {
        params.append('journalBrowserNotificationsEnabled', journalEnabled ? 'on' : 'off');
    }
    return fetch('/api/settings', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: params.toString(),
    }).then(response => response.ok).catch(() => false);
}

function bindFeedNotificationSubmitPrompt(form, kind) {
    if (!form || form.dataset.notificationPromptBound === '1') return;
    form.dataset.notificationPromptBound = '1';

    form.addEventListener('submit', function(event) {
        if (event.defaultPrevented || form.dataset.notificationPromptReady === '1') return;
        if (!shouldPromptForFeedNotifications(kind)) return;
        const textBox = form.querySelector('textarea[name="content"], textarea[name="reply_content"]');
        if (textBox && !String(textBox.value || '').trim()) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const submitter = event.submitter || null;
        const isPost = kind === 'post';
        showSitePopup({
            title: 'notify you of replies?',
            detail: isPost
                ? 'fridge.dev can send browser notifications when people reply to your feed post.'
                : 'fridge.dev can send browser notifications when people reply to your comment.',
            okText: 'notify me',
            cancelText: 'not now',
        }).then(async (accepted) => {
            saveFeedNotificationPromptSeen(kind);
            if (accepted) {
                const enabled = await setBrowserNotificationsEnabled(true, { baseline: true });
                if (enabled) {
                    saveLocalBrowserNotificationsEnabled(true);
                    await saveBrowserNotificationsAccountPreference(true);
                }
            }
            form.dataset.notificationPromptReady = '1';
            if (form.requestSubmit) {
                form.requestSubmit(submitter);
            } else {
                form.submit();
            }
            window.setTimeout(() => {
                delete form.dataset.notificationPromptReady;
            }, 0);
        });
    });
}

function normalizeAccessibilityPrefs(prefs) {
    return Object.assign({}, ACCESSIBILITY_DEFAULTS, {
        reduceMotion: !!(prefs && prefs.reduceMotion),
    });
}

function readLocalAccessibilityPrefs() {
    try {
        const raw = localStorage.getItem(ACCESSIBILITY_PREFS_KEY);
        if (!raw) return normalizeAccessibilityPrefs(null);
        return normalizeAccessibilityPrefs(JSON.parse(raw));
    } catch (_) {
        return normalizeAccessibilityPrefs(null);
    }
}

function saveLocalAccessibilityPrefs(prefs) {
    try {
        localStorage.setItem(ACCESSIBILITY_PREFS_KEY, JSON.stringify(normalizeAccessibilityPrefs(prefs)));
    } catch (_) { /* ignore */ }
}

function applyAccessibilityPrefs(prefs, opts = {}) {
    const normalized = normalizeAccessibilityPrefs(prefs);
    const root = document.documentElement;
    root.classList.toggle('access-reduced-motion', normalized.reduceMotion);
    root.classList.remove('access-high-contrast');
    if (opts.persistLocal !== false) {
        saveLocalAccessibilityPrefs(normalized);
    }
    return normalized;
}

function setOnekoSprite(el, frame) {
    if (!el || !frame) return;
    el.style.backgroundPosition = `${frame[0] * ONEKO_SIZE}px ${frame[1] * ONEKO_SIZE}px`;
}

function startOneko() {
    if (onekoController && onekoController.el && document.body.contains(onekoController.el)) {
        return;
    }

    stopOneko();

    const el = document.createElement('div');
    el.id = 'oneko';
    el.setAttribute('aria-hidden', 'true');
    el.style.position = 'fixed';
    el.style.width = `${ONEKO_SIZE}px`;
    el.style.height = `${ONEKO_SIZE}px`;
    el.style.left = '0';
    el.style.top = '0';
    el.style.zIndex = '2147483647';
    el.style.pointerEvents = 'none';
    el.style.backgroundImage = `url("${ONEKO_ASSET_URL}")`;
    el.style.imageRendering = 'pixelated';
    el.style.transform = 'translate(-16px, -16px)';

    const state = {
        x: Math.max(ONEKO_SIZE, Math.round(window.innerWidth / 2)),
        y: Math.max(ONEKO_SIZE, Math.round(window.innerHeight / 2)),
        targetX: Math.max(ONEKO_SIZE, Math.round(window.innerWidth / 2)),
        targetY: Math.max(ONEKO_SIZE, Math.round(window.innerHeight / 2)),
        frameCount: 0,
        idleTime: 0,
        idleAnimation: null,
        idleAnimationFrame: 0,
    };

    const spriteSets = {
        idle: [[-3, -3]],
        alert: [[-7, -3]],
        scratchSelf: [[-5, 0], [-6, 0], [-7, 0]],
        tired: [[-3, -2]],
        sleeping: [[-2, 0], [-2, -1]],
        N: [[-1, -2], [-1, -3]],
        NE: [[0, -2], [0, -3]],
        E: [[-3, 0], [-3, -1]],
        SE: [[-5, -1], [-5, -2]],
        S: [[-6, -3], [-7, -2]],
        SW: [[-5, -3], [-6, -1]],
        W: [[-4, -2], [-4, -3]],
        NW: [[-1, 0], [-1, -1]],
    };

    const setPosition = () => {
        state.x = Math.min(Math.max(ONEKO_SIZE / 2, state.x), window.innerWidth - ONEKO_SIZE / 2);
        state.y = Math.min(Math.max(ONEKO_SIZE / 2, state.y), window.innerHeight - ONEKO_SIZE / 2);
        el.style.left = `${state.x}px`;
        el.style.top = `${state.y}px`;
    };

    const setSprite = (name, frameIndex = 0) => {
        const frames = spriteSets[name] || spriteSets.idle;
        setOnekoSprite(el, frames[frameIndex % frames.length]);
    };

    const pickDirection = (diffX, diffY) => {
        const angle = Math.atan2(diffY, diffX) * 180 / Math.PI;
        if (angle > -22.5 && angle <= 22.5) return 'E';
        if (angle > 22.5 && angle <= 67.5) return 'SE';
        if (angle > 67.5 && angle <= 112.5) return 'S';
        if (angle > 112.5 && angle <= 157.5) return 'SW';
        if (angle > 157.5 || angle <= -157.5) return 'W';
        if (angle > -157.5 && angle <= -112.5) return 'NW';
        if (angle > -112.5 && angle <= -67.5) return 'N';
        return 'NE';
    };

    const mouseMove = (event) => {
        state.targetX = event.clientX;
        state.targetY = event.clientY;
        state.idleAnimation = null;
        state.idleAnimationFrame = 0;
    };

    const tick = () => {
        const diffX = state.targetX - state.x;
        const diffY = state.targetY - state.y;
        const distance = Math.hypot(diffX, diffY);

        if (distance < ONEKO_SPEED || distance < 48) {
            state.idleTime += ONEKO_FRAME_MS;
            if (state.idleTime >= ONEKO_SLEEP_AFTER_MS) {
                setSprite('sleeping', Math.floor(state.idleAnimationFrame / ONEKO_SLEEP_FRAME_TICKS));
                state.idleAnimationFrame++;
            } else if (state.idleTime >= ONEKO_SLEEP_AFTER_MS - 2000) {
                state.idleAnimationFrame = 0;
                setSprite('tired');
            } else {
                state.idleAnimationFrame = 0;
                setSprite('idle');
            }
            setPosition();
            return;
        }

        state.idleTime = 0;
        state.idleAnimation = null;
        state.x += diffX / distance * ONEKO_SPEED;
        state.y += diffY / distance * ONEKO_SPEED;
        setPosition();
        setSprite(pickDirection(diffX, diffY), state.frameCount++);
    };

    document.body.appendChild(el);
    setSprite('idle');
    setPosition();

    const interval = window.setInterval(tick, ONEKO_FRAME_MS);
    window.addEventListener('mousemove', mouseMove, { passive: true });
    onekoController = { el, interval, mouseMove, state };
}

function stopOneko() {
    if (!onekoController) {
        document.getElementById('oneko')?.remove();
        return;
    }

    window.clearInterval(onekoController.interval);
    window.removeEventListener('mousemove', onekoController.mouseMove);
    onekoController.el?.remove();
    onekoController = null;
}

function setOnekoEnabled(enabled, opts = {}) {
    if (opts.persistLocal !== false) {
        saveLocalOnekoEnabled(enabled);
    }
    if (enabled) {
        startOneko();
    } else {
        stopOneko();
    }
}

function syncOnekoPreference() {
    applyAccessibilityPrefs(readLocalAccessibilityPrefs(), { persistLocal: false });

    const localEnabled = readLocalOnekoEnabled();
    setOnekoEnabled(localEnabled, { persistLocal: false });

    if (!document.getElementById('user-greeting') || !window.fetch) return;
    fetch('/api/settings', {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(r => r.ok ? r.json() : null).then(data => {
        if (!data || !data.ok || !data.settings) return;
        if (typeof data.settings.onekoEnabled === 'boolean') {
            setOnekoEnabled(data.settings.onekoEnabled);
        }
        if (typeof data.settings.browserNotificationsEnabled === 'boolean') {
            saveLocalBrowserNotificationsEnabled(data.settings.browserNotificationsEnabled);
        }
        if (typeof data.settings.journalBrowserNotificationsEnabled === 'boolean') {
            saveLocalJournalBrowserNotificationsEnabled(data.settings.journalBrowserNotificationsEnabled);
        }
        if (hasAnyBrowserNotificationChannelEnabled()) {
            startFeedNotificationPolling();
        }
        const accessibilityPrefs = {};
        ['reduceMotion'].forEach(key => {
            if (typeof data.settings[key] === 'boolean') {
                accessibilityPrefs[key] = data.settings[key];
            }
        });
        if (Object.keys(accessibilityPrefs).length) {
            applyAccessibilityPrefs(accessibilityPrefs);
        }
    }).catch(() => {});
}

function setThemeCookie(theme) {
    try {
        const maxAge = 60 * 60 * 24 * 365;
        const value = encodeURIComponent(normalizeTheme(theme));
        const secure = (window.location && window.location.protocol === 'https:') ? '; Secure' : '';
        const domain = shouldUseSharedFridg3CookieDomain() ? `; Domain=${MOBILE_VIEW_DOMAIN}` : '';
        document.cookie = `${THEME_COOKIE}=${value}; Max-Age=${maxAge}; Path=/; SameSite=Lax${domain}${secure}`;
    } catch (_) { /* ignore */ }
}

function readMobileViewCookie() {
    const raw = getCookie(MOBILE_VIEW_COOKIE);
    if (raw === null) return null;
    return ['1', 'true', 'yes', 'y', 'on', 'enabled'].includes(String(raw).trim().toLowerCase());
}

function getCurrentHostName() {
    return ((window.location && window.location.hostname) ? window.location.hostname : '').toLowerCase();
}

function syncMobileViewCookieWithCurrentHost() {
    try {
        const host = getCurrentHostName();
        const cookieValue = readMobileViewCookie();
        if (host === 'm.fridge.dev' && cookieValue === null) {
            setMobileViewCookie(true);
        }
    } catch (_) { /* ignore */ }
}

function ensureGlowStyle() {
    if (document.getElementById(GLOW_STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = GLOW_STYLE_ID;
    style.textContent = `
:root.${GLOW_CLASS} *:not(html):not(body):not(style):not(script) {
    text-shadow: 0 0 var(--glow-radius, ${GLOW_RADIUS_DEFAULT}) currentColor !important;
}

/* Do not glow footer button icons */
:root.${GLOW_CLASS} #footer-buttons i {
    text-shadow: none !important;
}

/* Do not glow search button icon */
:root.${GLOW_CLASS} #search-button i {
    text-shadow: none !important;
}

/* Special handling for gradient text (#ascii-gradient) so glow matches gradient */
:root.${GLOW_CLASS} #ascii-gradient {
    position: relative;
}
:root.${GLOW_CLASS} #ascii-gradient::before {
    content: attr(data-text);
    position: absolute;
    inset: 0;
    display: block;
    white-space: pre;
    font: inherit;
    line-height: inherit;
    color: transparent;
    background: inherit;
    -webkit-background-clip: text;
    background-clip: text;
    filter: blur(var(--glow-radius, ${GLOW_RADIUS_DEFAULT}));
    opacity: 0.9;
    pointer-events: none;
}
`;
    document.head.appendChild(style);
}

function getGlowRadiusForIntensity(intensity) {
    return intensity === 'none' ? '0px' : GLOW_RADIUS_DEFAULT;
}

function applyGlowIntensity(intensity) {
    const root = document.documentElement;
    ensureGlowStyle();
    const radius = getGlowRadiusForIntensity(intensity);
    root.style.setProperty('--glow-radius', radius);
    const enabled = intensity !== 'none';
    setGlow(enabled);
}

function setGlow(enabled) {
    const root = document.documentElement;
    ensureGlowStyle();
    if (enabled) {
        root.classList.add(GLOW_CLASS);
    } else {
        root.classList.remove(GLOW_CLASS);
    }
    // Ensure any previous border glow classes are removed
    document.querySelectorAll('.glow-border').forEach(n => n.classList.remove('glow-border'));

    // Sync gradient text glow content
    const asciiGradient = document.getElementById('ascii-gradient');
    if (asciiGradient) {
        if (enabled) {
            asciiGradient.setAttribute('data-text', asciiGradient.textContent);
        } else {
            asciiGradient.removeAttribute('data-text');
        }
    }
}

// Smoother gradient rotation via requestAnimationFrame
const GRADIENT_RAF_ENABLED = true;
const GRADIENT_ROTATION_MS = 12000; // adjust for speed/smoothness

function startGradientRotation(el, durationMs) {
    try {
        const reduceMotion = document.documentElement.classList.contains('access-reduced-motion')
            || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        if (reduceMotion) return;
        let start;
        // disable CSS animation to avoid double updates
        el.style.animation = 'none';
        const tick = (ts) => {
            if (document.documentElement.classList.contains('access-reduced-motion')) return;
            if (!start) start = ts;
            const elapsed = ts - start;
            const angle = (elapsed % durationMs) / durationMs * 360;
            el.style.setProperty('--angle', angle + 'deg');
            requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    } catch (_) { /* no-op */ }
}


function initTooltips() {
    if (isMobileTemplateActive()) {
        clearTooltips();
    }
    document.querySelectorAll('[data-tooltip]').forEach(element => {
        // Remove previous listeners to avoid duplicates
        element.removeEventListener('mouseenter', element._tooltipMouseEnter);
        element.removeEventListener('mousemove', element._tooltipMouseMove);
        element.removeEventListener('mouseleave', element._tooltipMouseLeave);
        // Define handlers
        element._tooltipMouseEnter = function(e) {
            if (isMobileTemplateActive()) return;
            let rawText = this.getAttribute('data-tooltip') || '';
            rawText = rawText.replace(/\\n/g, '<br>');
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.innerHTML = rawText;
            document.body.appendChild(tooltip);
            activeTooltip = { element: tooltip, trigger: this };
            const updateTooltipPosition = (event) => {
                const rect = tooltip.getBoundingClientRect();
                const tooltipWidth = rect.width;
                const tooltipHeight = rect.height;
                const offset = 10;
                let x = event.clientX + offset;
                let y = event.clientY + offset;
                if (x + tooltipWidth > window.innerWidth) {
                    x = event.clientX - tooltipWidth - offset;
                }
                if (y + tooltipHeight > window.innerHeight) {
                    y = event.clientY - tooltipHeight - offset;
                }
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
            };
            updateTooltipPosition(e);
            element._tooltipMouseMove = updateTooltipPosition;
            this.addEventListener('mousemove', updateTooltipPosition);
        };
        element._tooltipMouseLeave = function() {
            if (activeTooltip && activeTooltip.element) {
                activeTooltip.element.remove();
                activeTooltip = null;
            }
            this.removeEventListener('mousemove', element._tooltipMouseMove);
        };
        element.addEventListener('mouseenter', element._tooltipMouseEnter);
        element.addEventListener('mouseleave', element._tooltipMouseLeave);
    });
}

window.addEventListener('DOMContentLoaded', initTooltips);
window.addEventListener('DOMContentLoaded', syncOnekoPreference);

// Settings page: text glow toggle
function initSettingsPage() {
    try {
        const rawPath = (window.location && window.location.pathname) ? window.location.pathname : '/';
        const path = rawPath.replace(/\/+$/, '') || '/';
        const saveBtn = document.getElementById('settings-save');
        if (!path.startsWith('/settings') && !saveBtn) return;

        const glowGroup = document.getElementById('text-glow-group');
        const glowToggle = document.getElementById('text-glow-toggle');
        const maintenanceGroup = document.getElementById('maintenance-mode-group');
        const adminSection = document.getElementById('admin-settings');
        const themeSelect = document.getElementById('theme-select');
        const colorSection = document.getElementById('color-scheme-section');
        const colorGroup = document.getElementById('color-scheme-group');
        const colorResetBtn = document.getElementById('color-reset');
        const mobileViewToggle = document.getElementById('mobile-friendly-toggle');
        const reduceMotionToggle = document.getElementById('reduce-motion-toggle');
        const feedNotificationsToggle = document.getElementById('feed-notifications-toggle');
        const journalNotificationsToggle = document.getElementById('journal-notifications-toggle');
        const onekoToggle = document.getElementById('oneko-toggle');
        const sitemapBtn = document.querySelector('[data-action="generate-sitemap"]');
        const devDataBootstrapBtn = document.querySelector('[data-action="dev-data-bootstrap"]');
        const toastPersonalityTextarea = document.getElementById('toast-personality-json');
        const toastPersonalityHighlight = document.getElementById('toast-personality-highlight');
        const toastPersonalitySaveBtn = document.querySelector('[data-action="save-toast-personality"]');
        const toastPersonalityStatus = document.getElementById('toast-personality-status');
        const toastSettingsSection = document.getElementById('toast-settings');
        if (!glowGroup || !saveBtn) return;
        if (glowGroup.dataset.bound === '1') return;
        glowGroup.dataset.bound = '1';

        const maintenanceRadios = maintenanceGroup ? maintenanceGroup.querySelectorAll('input[type="radio"][name="maintenance-mode"]') : [];
        const colorInputs = colorGroup ? colorGroup.querySelectorAll('input.color-input[data-color-key]') : [];
        const host = getCurrentHostName();
        if (!glowToggle) return;

        let isAdmin = false;
        const isLoggedIn = !!document.getElementById('user-greeting');
        const isToastSession = !!(toastSettingsSection && toastSettingsSection.dataset.toastSession === '1');
        let currentTheme = loadLocalThemePref();
        let lastSavedTheme = currentTheme;
        let themeOptions = [];
        let themePicker = null;
        let themePickerButton = null;
        let themePickerMenu = null;
        let themePickerTitle = null;
        let themePickerDescription = null;
        let themePickerThumb = null;

        const selectMaintenance = (state) => {
            if (!maintenanceRadios.length) return;
            maintenanceRadios.forEach(r => {
                r.checked = (r.value === state);
            });
        };

        const setMobileViewToggle = (enabled) => {
            if (!mobileViewToggle) return;
            mobileViewToggle.checked = enabled === true;
        };

        const setOnekoToggle = (enabled) => {
            if (!onekoToggle) return;
            onekoToggle.checked = enabled === true;
        };

        const setFeedNotificationsToggle = (enabled) => {
            if (!feedNotificationsToggle) return;
            feedNotificationsToggle.checked = enabled === true;
        };

        const setJournalNotificationsToggle = (enabled) => {
            if (!journalNotificationsToggle) return;
            journalNotificationsToggle.checked = enabled === true;
        };

        const getAccessibilityValues = () => {
            return normalizeAccessibilityPrefs({
                reduceMotion: !!(reduceMotionToggle && reduceMotionToggle.checked),
            });
        };

        const setAccessibilityToggles = (prefs) => {
            const normalized = normalizeAccessibilityPrefs(prefs);
            if (reduceMotionToggle) reduceMotionToggle.checked = normalized.reduceMotion;
        };

        const loadMaintenanceState = async () => {
            if (!maintenanceRadios.length) return;
            try {
                const res = await fetch('/data/etc/wip', { cache: 'no-store' });
                if (!res.ok) return;
                const txt = (await res.text()).trim().toLowerCase();
                const truthy = new Set(['1', 'true', 'yes', 'y', 'on', 'enabled', 'wip']);
                selectMaintenance(truthy.has(txt) ? 'on' : 'off');
            } catch (_) {
                /* ignore */
            }
        };

        const getMaintenanceSelection = () => {
            if (!maintenanceRadios.length) return null;
            let val = null;
            maintenanceRadios.forEach(r => { if (r.checked) val = r.value; });
            return val;
        };

        const getColorValuesFromInputs = () => {
            const result = {};
            colorInputs.forEach(inp => {
                const key = inp.dataset.colorKey;
                const n = normalizeColor(inp.value);
                if (key && n) result[key] = n;
            });
            return result;
        };

        const getColorValuesForTheme = (theme) => {
            const allowed = new Set(getThemeColorFields(theme));
            const values = getColorValuesFromInputs();
            const filtered = {};
            Object.entries(values).forEach(([key, value]) => {
                if (allowed.has(key)) filtered[key] = value;
            });
            return filtered;
        };

        const setColorInputs = (colors) => {
            if (!colors) return;
            colorInputs.forEach(inp => {
                const key = inp.dataset.colorKey;
                if (colors[key]) {
                    inp.value = colors[key];
                }
            });
        };

        const getThemeMeta = (theme) => {
            const normalizedTheme = normalizeTheme(theme);
            return themeOptions.find(item => item.id === normalizedTheme) || {
                id: normalizedTheme,
                name: normalizedTheme === 'default' ? 'blackprint' : normalizedTheme,
                description: '',
                thumbnail: '',
            };
        };

        const updateThemePickerButton = () => {
            if (!themePickerButton || !themePickerTitle || !themePickerDescription || !themePickerThumb) return;
            const meta = getThemeMeta(getThemeSelection());
            themePickerTitle.textContent = meta.name || meta.id;
            themePickerDescription.textContent = meta.description || '';
            if (meta.thumbnail) {
                themePickerThumb.style.backgroundImage = `url("${meta.thumbnail}")`;
                themePickerThumb.classList.remove('empty');
            } else {
                themePickerThumb.style.backgroundImage = '';
                themePickerThumb.classList.add('empty');
            }
            if (themePickerMenu) {
                themePickerMenu.querySelectorAll('.theme-picker-option').forEach(option => {
                    const selected = option.dataset.themeId === meta.id;
                    option.classList.toggle('selected', selected);
                    option.setAttribute('aria-selected', selected ? 'true' : 'false');
                });
            }
        };

        const closeThemePicker = () => {
            if (!themePicker) return;
            themePicker.classList.remove('open');
            if (themePickerButton) themePickerButton.setAttribute('aria-expanded', 'false');
        };

        const renderThemePickerOptions = () => {
            if (!themePickerMenu) return;
            themePickerMenu.innerHTML = '';
            themeOptions.forEach(theme => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'theme-picker-option';
                option.dataset.themeId = theme.id;
                option.setAttribute('role', 'option');

                const thumb = document.createElement('span');
                thumb.className = 'theme-picker-thumb';
                if (theme.thumbnail) {
                    thumb.style.backgroundImage = `url("${theme.thumbnail}")`;
                } else {
                    thumb.classList.add('empty');
                }

                const copy = document.createElement('span');
                copy.className = 'theme-picker-copy';
                const title = document.createElement('span');
                title.className = 'theme-picker-option-title';
                title.textContent = theme.name || theme.id;
                const description = document.createElement('span');
                description.className = 'theme-picker-option-description';
                description.textContent = theme.description || '';
                copy.appendChild(title);
                copy.appendChild(description);

                option.appendChild(thumb);
                option.appendChild(copy);
                option.addEventListener('click', () => {
                    setThemeSelection(theme.id);
                    closeThemePicker();
                    themeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                });
                themePickerMenu.appendChild(option);
            });
            updateThemePickerButton();
        };

        const ensureThemePicker = () => {
            if (!themeSelect || themePicker) return;
            themeSelect.classList.add('native-theme-select');
            themePicker = document.createElement('div');
            themePicker.className = 'theme-picker';

            themePickerButton = document.createElement('button');
            themePickerButton.type = 'button';
            themePickerButton.className = 'theme-picker-button';
            themePickerButton.setAttribute('aria-haspopup', 'listbox');
            themePickerButton.setAttribute('aria-expanded', 'false');

            themePickerThumb = document.createElement('span');
            themePickerThumb.className = 'theme-picker-thumb';
            const buttonCopy = document.createElement('span');
            buttonCopy.className = 'theme-picker-copy';
            themePickerTitle = document.createElement('span');
            themePickerTitle.className = 'theme-picker-title';
            themePickerDescription = document.createElement('span');
            themePickerDescription.className = 'theme-picker-description';
            buttonCopy.appendChild(themePickerTitle);
            buttonCopy.appendChild(themePickerDescription);
            const chevron = document.createElement('span');
            chevron.className = 'theme-picker-chevron';
            chevron.setAttribute('aria-hidden', 'true');
            chevron.textContent = '▾';
            themePickerButton.appendChild(themePickerThumb);
            themePickerButton.appendChild(buttonCopy);
            themePickerButton.appendChild(chevron);

            themePickerMenu = document.createElement('div');
            themePickerMenu.className = 'theme-picker-menu';
            themePickerMenu.setAttribute('role', 'listbox');

            themePicker.appendChild(themePickerButton);
            themePicker.appendChild(themePickerMenu);
            themeSelect.insertAdjacentElement('afterend', themePicker);

            themePickerButton.addEventListener('click', () => {
                const open = themePicker.classList.toggle('open');
                themePickerButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', event => {
                if (!themePicker.contains(event.target)) closeThemePicker();
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') closeThemePicker();
            });
        };

        const syncColorSectionForTheme = (theme) => {
            if (!colorSection) return;
            const normalizedTheme = normalizeTheme(theme);
            const fields = getThemeColorFields(normalizedTheme);
            colorSection.style.display = fields.length ? '' : 'none';
            const heading = colorSection.querySelector('h3');
            if (heading) {
                heading.textContent = normalizedTheme === 'ambercrt' ? 'CRT phosphor' : 'color scheme';
            }
            const allowed = new Set(fields);
            colorInputs.forEach(inp => {
                const row = inp.closest('.color-row');
                if (row) row.style.display = allowed.has(inp.dataset.colorKey) ? '' : 'none';
                const label = row ? row.querySelector('span') : null;
                if (label && normalizedTheme === 'ambercrt' && inp.dataset.colorKey === 'links') {
                    label.textContent = 'main';
                } else if (label && inp.dataset.colorKey === 'links') {
                    label.textContent = 'links';
                }
            });
        };

        const populateThemeOptions = (themes) => {
            if (!themeSelect) return;
            const selectedTheme = getThemeSelection();
            ensureThemePicker();
            themeSelect.innerHTML = '';
            themeOptions = [];

            (Array.isArray(themes) ? themes : []).forEach(theme => {
                const id = normalizeTheme(theme && theme.id);
                const name = theme && typeof theme.name === 'string' ? theme.name.trim() : '';
                if (!name) return;
                if ([...themeSelect.options].some(option => option.value === id)) return;
                const description = theme && typeof theme.description === 'string' ? theme.description.trim() : '';
                const thumbnail = theme && typeof theme.thumbnail === 'string' ? theme.thumbnail.trim() : '';
                const option = document.createElement('option');
                option.value = id;
                option.textContent = name;
                themeSelect.appendChild(option);
                themeOptions.push({ id, name, description, thumbnail });
            });

            if (![...themeSelect.options].some(option => option.value === 'default')) {
                const defaultOption = document.createElement('option');
                defaultOption.value = 'default';
                defaultOption.textContent = 'blackprint';
                themeSelect.insertBefore(defaultOption, themeSelect.firstChild);
                themeOptions.unshift({
                    id: 'default',
                    name: 'blackprint',
                    description: 'dark print layout with purple-to-teal accents',
                    thumbnail: '/themes/thumbnails/blackprint.svg',
                });
            }

            setThemeSelection(selectedTheme);
            renderThemePickerOptions();
        };

        const setThemeSelection = (theme) => {
            if (!themeSelect) return;
            const normalizedTheme = normalizeTheme(theme);
            themeSelect.value = normalizedTheme;
            if (themeSelect.value === '' && normalizedTheme !== 'default') {
                const pendingOption = document.createElement('option');
                pendingOption.value = normalizedTheme;
                pendingOption.textContent = normalizedTheme;
                themeSelect.appendChild(pendingOption);
                themeSelect.value = normalizedTheme;
            }
            if (themeSelect.value === '') {
                themeSelect.value = 'default';
            }
            updateThemePickerButton();
        };

        const getThemeSelection = () => {
            return normalizeTheme(themeSelect ? themeSelect.value : 'default');
        };

        const applyThemeSelection = (theme) => {
            const normalizedTheme = normalizeTheme(theme);
            syncColorSectionForTheme(normalizedTheme);
            if (themeSupportsColorPrefs(normalizedTheme)) {
                const fields = getThemeColorFields(normalizedTheme);
                const colors = Object.assign({}, getThemeColorDefaults(normalizedTheme), loadLocalColorPrefs() || getColorValuesForTheme(normalizedTheme));
                setColorInputs(colors);
                clearColorVars();
                applyColorVars(colors, fields);
            } else {
                clearColorVars();
            }
        };

        const postColorsToServer = (colors) => {
            if (!isLoggedIn || !window.fetch) return Promise.resolve();
            const params = new URLSearchParams();
            Object.entries(colors).forEach(([k, v]) => {
                params.append('color' + k.charAt(0).toUpperCase() + k.slice(1), v);
            });
            return fetch('/api/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: params.toString(),
            }).catch(() => {});
        };

        const persistColors = (colors, opts = {}) => {
            const selectedTheme = getThemeSelection();
            if (!themeSupportsColorPrefs(selectedTheme)) {
                clearColorVars();
                return Promise.resolve();
            }
            const fields = getThemeColorFields(selectedTheme);
            const merged = Object.assign({}, getThemeColorDefaults(selectedTheme), colors || {});
            saveLocalColorPrefs(merged);
            clearColorVars();
            applyColorVars(merged, fields);
            if (!opts.skipServer) {
                return postColorsToServer(merged);
            }
            return Promise.resolve();
        };

        const resetColorsToDefault = () => {
            const selectedTheme = getThemeSelection();
            const defaults = getThemeColorDefaults(selectedTheme);
            setColorInputs(defaults);
            persistColors(defaults);
        };

        const bindSitemapButton = () => {
            if (!sitemapBtn || sitemapBtn.dataset.bound === '1') return;
            sitemapBtn.dataset.bound = '1';
            sitemapBtn.addEventListener('click', async () => {
                if (!isAdmin) return;
                const originalText = sitemapBtn.textContent;
                sitemapBtn.disabled = true;
                sitemapBtn.textContent = 'generating...';
                try {
                    const res = await fetch('/api/sitemap', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) throw new Error('http');
                    const data = await res.json().catch(() => ({}));
                    sitemapBtn.textContent = data && data.ok ? 'sitemap generated' : 'failed';
                } catch (_) {
                    sitemapBtn.textContent = 'error';
                } finally {
                    setTimeout(() => {
                        sitemapBtn.textContent = originalText;
                        sitemapBtn.disabled = false;
                    }, 1200);
                }
            });
        };

        const showDevBootstrapProgressPopup = () => {
            const overlay = document.createElement('div');
            overlay.className = 'site-popup-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            const dialog = document.createElement('div');
            dialog.className = 'site-popup-dialog dev-bootstrap-progress-dialog';

            const title = document.createElement('div');
            title.className = 'site-popup-title';
            title.textContent = 'dev bootstrap';

            const detail = document.createElement('div');
            detail.className = 'site-popup-detail';
            detail.textContent = 'starting...';

            const logLine = document.createElement('div');
            logLine.className = 'dev-bootstrap-progress-log';
            logLine.textContent = 'waiting for server...';

            const meter = document.createElement('div');
            meter.className = 'dev-bootstrap-progress';
            const bar = document.createElement('div');
            bar.className = 'dev-bootstrap-progress-bar';
            meter.appendChild(bar);

            const percent = document.createElement('div');
            percent.className = 'dev-bootstrap-progress-percent';
            percent.textContent = '0%';

            dialog.append(title, detail, logLine, meter, percent);
            overlay.append(dialog);
            document.body.append(overlay);

            return {
                setProgress(value, message, isError = false, logMessage = '') {
                    const progress = Math.max(0, Math.min(100, Number(value) || 0));
                    bar.style.width = progress + '%';
                    percent.textContent = Math.round(progress) + '%';
                    detail.textContent = message || '';
                    detail.style.color = isError ? 'red' : 'var(--subtle)';
                    if (logMessage) {
                        logLine.textContent = logMessage;
                    }
                    logLine.style.color = isError ? 'red' : 'var(--subtle)';
                },
                finish(message, isError = false) {
                    this.setProgress(100, message, isError, isError ? 'failed: ' + message : 'done: ' + message);
                },
            };
        };

        const bindDevDataBootstrapButton = () => {
            if (!devDataBootstrapBtn || devDataBootstrapBtn.dataset.bound === '1') return;
            devDataBootstrapBtn.dataset.bound = '1';
            devDataBootstrapBtn.addEventListener('click', async () => {
                const confirmed = await showSitePopup({
                    title: 'replace local data?',
                    detail: 'this action will delete your existing local data directory, download the latest developer copy, and install it. this cannot be undone!',
                    okText: 'download',
                    cancelText: 'cancel',
                });
                if (!confirmed) return;

                const originalText = devDataBootstrapBtn.textContent;
                devDataBootstrapBtn.disabled = true;
                devDataBootstrapBtn.textContent = 'bootstrapping...';
                const progressPopup = showDevBootstrapProgressPopup();

                try {
                    const res = await fetch('/api/dev-bootstrap/', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });

                    if (!res.body || !res.body.getReader) {
                        const text = await res.text();
                        const lines = text.trim().split('\n').filter(Boolean);
                        const last = lines.length ? JSON.parse(lines[lines.length - 1]) : null;
                        if (!res.ok || !last || last.ok === false) {
                            throw new Error(last && last.message ? last.message : 'bootstrap failed');
                        }
                        progressPopup.finish(last.message || 'dev data installed.');
                        return;
                    }

                    const reader = res.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    let lastMessage = '';
                    let failedMessage = '';
                    while (true) {
                        const chunk = await reader.read();
                        if (chunk.done) break;
                        buffer += decoder.decode(chunk.value, { stream: true });
                        const lines = buffer.split('\n');
                        buffer = lines.pop() || '';
                        lines.forEach(line => {
                            if (!line.trim()) return;
                            const event = JSON.parse(line);
                            lastMessage = event.message || lastMessage;
                            progressPopup.setProgress(event.progress, lastMessage, event.ok === false, event.log || ((event.stage ? event.stage + ': ' : '') + lastMessage));
                            if (event.ok === false) {
                                failedMessage = lastMessage || 'bootstrap failed';
                            }
                        });
                    }
                    if (buffer.trim()) {
                        const event = JSON.parse(buffer);
                        lastMessage = event.message || lastMessage;
                        progressPopup.setProgress(event.progress, lastMessage, event.ok === false, event.log || ((event.stage ? event.stage + ': ' : '') + lastMessage));
                        if (event.ok === false) {
                            failedMessage = lastMessage || 'bootstrap failed';
                        }
                    }
                    if (!res.ok || failedMessage) {
                        throw new Error(failedMessage || 'bootstrap failed');
                    }
                    progressPopup.finish(lastMessage || 'dev data installed.');
                } catch (err) {
                    progressPopup.finish((err && err.message) ? err.message : 'bootstrap failed.', true);
                } finally {
                    devDataBootstrapBtn.textContent = originalText;
                    devDataBootstrapBtn.disabled = false;
                }
            });
        };

        const setToastPersonalityStatus = (message, isError) => {
            if (!toastPersonalityStatus) return;
            toastPersonalityStatus.textContent = message || '';
            toastPersonalityStatus.style.color = isError ? 'red' : 'var(--subtle)';
        };

        const renderToastPersonalityHighlight = () => {
            if (!toastPersonalityTextarea || !toastPersonalityHighlight) return;
            const value = toastPersonalityTextarea.value || ' ';
            if (typeof hljs !== 'undefined' && hljs.highlight) {
                try {
                    toastPersonalityHighlight.innerHTML = hljs.highlight(value, {
                        language: 'json',
                        ignoreIllegals: true,
                    }).value;
                    return;
                } catch (_) {
                    /* fall through */
                }
            }
            toastPersonalityHighlight.textContent = value;
        };

        const syncToastPersonalityScroll = () => {
            if (!toastPersonalityTextarea || !toastPersonalityHighlight) return;
            const pre = toastPersonalityHighlight.closest('pre');
            if (!pre) return;
            pre.scrollTop = toastPersonalityTextarea.scrollTop;
            pre.scrollLeft = toastPersonalityTextarea.scrollLeft;
        };

        const setToastPersonalityValue = (value) => {
            if (!toastPersonalityTextarea) return;
            toastPersonalityTextarea.value = value || '';
            renderToastPersonalityHighlight();
            syncToastPersonalityScroll();
        };

        const bindToastPersonalityButton = () => {
            if (!toastPersonalityTextarea || !toastPersonalitySaveBtn || toastPersonalitySaveBtn.dataset.bound === '1') return;
            toastPersonalitySaveBtn.dataset.bound = '1';
            toastPersonalityTextarea.addEventListener('input', () => {
                renderToastPersonalityHighlight();
                syncToastPersonalityScroll();
            });
            toastPersonalityTextarea.addEventListener('scroll', syncToastPersonalityScroll);
            toastPersonalitySaveBtn.addEventListener('click', async () => {
                let formatted = '';
                try {
                    formatted = JSON.stringify(JSON.parse(toastPersonalityTextarea.value || ''), null, 2);
                } catch (_) {
                    setToastPersonalityStatus('invalid json. tiny syntax crime detected.', true);
                    return;
                }

                const originalText = toastPersonalitySaveBtn.textContent;
                toastPersonalitySaveBtn.disabled = true;
                toastPersonalitySaveBtn.textContent = 'saving...';
                setToastPersonalityStatus('', false);
                try {
                    const params = new URLSearchParams();
                    params.append('toastPersonalityJson', formatted);
                    const res = await fetch('/api/settings', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: params.toString(),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data || !data.ok) {
                        throw new Error(data && data.message ? data.message : 'save failed');
                    }

                    setToastPersonalityValue(formatted);
                    setToastPersonalityStatus('saved.', false);
                } catch (err) {
                    setToastPersonalityStatus((err && err.message) ? err.message : 'save failed.', true);
                } finally {
                    toastPersonalitySaveBtn.textContent = originalText;
                    toastPersonalitySaveBtn.disabled = false;
                }
            });
        };

        // Pre-select glow from localStorage if available
        let stored = null;
        try {
            stored = localStorage.getItem(GLOW_INTENSITY_KEY);
        } catch (_) { stored = null; }
        const initial = stored || GLOW_DEFAULT_INTENSITY;
        glowToggle.checked = initial !== 'none';

        // Load theme/color prefs: local first, then server if logged in
        const initialTheme = currentTheme;
        setThemeSelection(initialTheme);
        const initialColors = Object.assign({}, getThemeColorDefaults(initialTheme), loadLocalColorPrefs() || {});
        setColorInputs(initialColors);
        applyThemeSelection(initialTheme);
        syncMobileViewCookieWithCurrentHost();
        setMobileViewToggle(readMobileViewCookie());
        let lastSavedMobileViewEnabled = readMobileViewCookie();
        setAccessibilityToggles(readLocalAccessibilityPrefs());
        setOnekoToggle(readLocalOnekoEnabled());
        setFeedNotificationsToggle(readLocalBrowserNotificationsEnabled());
        setJournalNotificationsToggle(readLocalJournalBrowserNotificationsEnabled());

        if (window.fetch) {
            fetch('/api/themes', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).then(r => r.ok ? r.json() : null).then(data => {
                if (!data || !data.ok) return;
                populateThemeOptions(data.themes || []);
                if (!isLoggedIn && data.selected) {
                    currentTheme = normalizeTheme(data.selected);
                    setThemeSelection(currentTheme);
                    saveLocalThemePref(currentTheme);
                    applyThemeSelection(currentTheme);
                    lastSavedTheme = currentTheme;
                } else {
                    setThemeSelection(currentTheme);
                }
            }).catch(() => {});
        }

        if (isLoggedIn && window.fetch) {
            fetch('/api/settings', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).then(r => r.ok ? r.json() : null).then(data => {
                if (!data || !data.ok || !data.settings) return;

                currentTheme = normalizeTheme(data.settings.theme);
                setThemeSelection(currentTheme);
                saveLocalThemePref(currentTheme);
                setThemeCookie(currentTheme);
                lastSavedTheme = currentTheme;

                if (data.settings.colors) {
                    const serverColors = {};
                    COLOR_FIELDS.forEach(k => {
                        const n = normalizeColor(data.settings.colors[k] ?? '');
                        if (n) serverColors[k] = n;
                    });
                    if (Object.keys(serverColors).length) {
                        const merged = Object.assign({}, getThemeColorDefaults(currentTheme), serverColors);
                        setColorInputs(merged);
                        saveLocalColorPrefs(merged);
                    }
                }

                applyThemeSelection(currentTheme);

                const serverAccessibilityPrefs = {};
                ['reduceMotion'].forEach(key => {
                    if (typeof data.settings[key] === 'boolean') {
                        serverAccessibilityPrefs[key] = data.settings[key];
                    }
                });
                if (Object.keys(serverAccessibilityPrefs).length) {
                    const normalizedAccessibilityPrefs = applyAccessibilityPrefs(serverAccessibilityPrefs);
                    setAccessibilityToggles(normalizedAccessibilityPrefs);
                }
                if (typeof data.settings.onekoEnabled === 'boolean') {
                    setOnekoToggle(data.settings.onekoEnabled);
                    setOnekoEnabled(data.settings.onekoEnabled);
                }
                if (typeof data.settings.browserNotificationsEnabled === 'boolean') {
                    setFeedNotificationsToggle(data.settings.browserNotificationsEnabled);
                    saveLocalBrowserNotificationsEnabled(data.settings.browserNotificationsEnabled);
                }
                if (typeof data.settings.journalBrowserNotificationsEnabled === 'boolean') {
                    setJournalNotificationsToggle(data.settings.journalBrowserNotificationsEnabled);
                    saveLocalJournalBrowserNotificationsEnabled(data.settings.journalBrowserNotificationsEnabled);
                }
                if (hasAnyBrowserNotificationChannelEnabled()) {
                    startFeedNotificationPolling();
                }
                if (typeof data.settings.glowIntensity === 'string') {
                    const serverGlowIntensity = data.settings.glowIntensity === 'none' ? 'none' : 'medium';
                    glowToggle.checked = serverGlowIntensity !== 'none';
                    try {
                        localStorage.setItem(GLOW_INTENSITY_KEY, serverGlowIntensity);
                    } catch (_) { /* ignore */ }
                    applyGlowIntensity(serverGlowIntensity);
                }
                if (toastPersonalityTextarea && typeof data.settings.toastPersonalityJson === 'string') {
                    bindToastPersonalityButton();
                    setToastPersonalityValue(data.settings.toastPersonalityJson);
                }
            }).catch(() => {});
        }

        // Persist colors immediately when changed
        colorInputs.forEach(inp => {
            inp.addEventListener('input', () => {
                const selectedTheme = getThemeSelection();
                if (!themeSupportsColorPrefs(selectedTheme)) return;
                const chosen = getColorValuesForTheme(selectedTheme);
                persistColors(chosen);
            });
        });

        if (themeSelect) {
            themeSelect.addEventListener('change', () => {
                currentTheme = getThemeSelection();
                saveLocalThemePref(currentTheme);
                setThemeCookie(currentTheme);
                applyThemeSelection(currentTheme);
            });
        }

        saveBtn.addEventListener('click', async function() {
            const selected = glowToggle.checked ? 'medium' : 'none';

            const mobileViewEnabled = !!(mobileViewToggle && mobileViewToggle.checked);
            setMobileViewCookie(mobileViewEnabled);
            const shouldReloadForMobileView = mobileViewEnabled !== lastSavedMobileViewEnabled;
            const accessibilityPrefs = applyAccessibilityPrefs(getAccessibilityValues());

            if (isToastSession) {
                if (isLoggedIn && window.fetch) {
                    const params = new URLSearchParams();
                    params.append('reduceMotion', accessibilityPrefs.reduceMotion ? 'on' : 'off');
                    fetch('/api/settings', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: params.toString(),
                    }).catch(() => {}).finally(() => {
                        window.location.reload();
                    });
                } else {
                    window.location.reload();
                }
                return;
            }

            // Persist to localStorage for all users
            try {
                localStorage.setItem(GLOW_INTENSITY_KEY, selected);
            } catch (_) { /* ignore */ }

            // Apply immediately
            applyGlowIntensity(selected);

            const selectedTheme = getThemeSelection();
            const themeChanged = selectedTheme !== lastSavedTheme;
            saveLocalThemePref(selectedTheme);
            setThemeCookie(selectedTheme);

            const chosenColors = getColorValuesForTheme(selectedTheme);
            const mergedColors = Object.assign({}, getThemeColorDefaults(selectedTheme), chosenColors);
            if (themeSupportsColorPrefs(selectedTheme)) {
                persistColors(mergedColors, { skipServer: isLoggedIn });
            } else {
                clearColorVars();
            }
            const onekoEnabled = !!(onekoToggle && onekoToggle.checked);
            setOnekoEnabled(onekoEnabled);
            const feedNotificationsEnabled = !!(feedNotificationsToggle && feedNotificationsToggle.checked);
            const journalNotificationsEnabled = !!(journalNotificationsToggle && journalNotificationsToggle.checked);
            const anyNotificationsEnabled = feedNotificationsEnabled || journalNotificationsEnabled;
            const browserNotificationsReady = await setBrowserNotificationsEnabled(anyNotificationsEnabled);
            const savedFeedNotificationsEnabled = browserNotificationsReady && feedNotificationsEnabled;
            const savedJournalNotificationsEnabled = browserNotificationsReady && journalNotificationsEnabled;
            saveLocalBrowserNotificationsEnabled(savedFeedNotificationsEnabled);
            saveLocalJournalBrowserNotificationsEnabled(savedJournalNotificationsEnabled);
            setFeedNotificationsToggle(savedFeedNotificationsEnabled);
            setJournalNotificationsToggle(savedJournalNotificationsEnabled);
            if (browserNotificationsReady && (savedFeedNotificationsEnabled || savedJournalNotificationsEnabled)) {
                startFeedNotificationPolling();
            }

            if (isLoggedIn && window.fetch) {
                const params = new URLSearchParams();
                params.append('glowIntensity', selected);
                params.append('theme', selectedTheme);
                params.append('reduceMotion', accessibilityPrefs.reduceMotion ? 'on' : 'off');
                params.append('onekoEnabled', onekoEnabled ? 'on' : 'off');
                params.append('browserNotificationsEnabled', savedFeedNotificationsEnabled ? 'on' : 'off');
                params.append('journalBrowserNotificationsEnabled', savedJournalNotificationsEnabled ? 'on' : 'off');

                if (themeSupportsColorPrefs(selectedTheme) && Object.keys(mergedColors).length) {
                    // flatten colors into separate fields (merged so defaults persist server-side)
                    Object.entries(mergedColors).forEach(([k, v]) => {
                        params.append('color' + k.charAt(0).toUpperCase() + k.slice(1), v);
                    });
                }

                if (isAdmin) {
                    const maintenanceSelection = getMaintenanceSelection();
                    if (maintenanceSelection !== null) {
                        params.append('maintenanceMode', maintenanceSelection);
                    }
                }

                try {
                    await fetch('/api/settings', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: params.toString(),
                    });
                    lastSavedTheme = selectedTheme;
                } catch (_) {
                    /* local settings already applied */
                }
            } else {
                /* local settings already applied */
                lastSavedTheme = selectedTheme;
            }

            lastSavedMobileViewEnabled = mobileViewEnabled;
            if (shouldReloadForMobileView || themeChanged) {
                window.location.reload();
            }
        });

        if (colorResetBtn) {
            colorResetBtn.addEventListener('click', (e) => {
                e.preventDefault();
                resetColorsToDefault();
            });
        }

        // Determine admin status to show/hide admin controls and load state
        fetchAdminStatus().then(flag => {
            isAdmin = flag === true;
            if (adminSection) {
                adminSection.style.display = isAdmin ? 'block' : 'none';
            }
            bindDevDataBootstrapButton();
            if (isAdmin) {
                loadMaintenanceState();
                bindSitemapButton();
            }
        }).catch(() => {
            if (adminSection) adminSection.style.display = 'none';
            bindDevDataBootstrapButton();
        });
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', initSettingsPage);
