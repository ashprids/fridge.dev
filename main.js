// Global work-in-progress kill-switch; derived from /data/etc/wip
let workInProgress = false;
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

        const ok = document.createElement('button');
        ok.className = 'site-popup-button site-popup-ok';
        ok.type = 'button';
        ok.textContent = config.okText || 'ok';
        actions.append(ok);

        dialog.append(title, detail);
        if (input) dialog.append(input);
        dialog.append(actions);
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
        ok.addEventListener('click', () => close(input ? input.value : true));
        overlay.addEventListener('click', event => {
            if (event.target === overlay) close(input ? null : false);
        });
        document.addEventListener('keydown', onKeydown);
        document.body.append(overlay);
        (input || ok).focus();
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

function hasAdminCookie() {
    try {
        return document.cookie.split(';').some((cookie) => cookie.trim().startsWith('is_admin=1'));
    } catch (_) {
        return false;
    }
}

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

function enableWipEnforcement() {
    const isAllowedPath = (pathname) => {
        // Remove trailing slash for consistent comparison
        const normalizedPath = pathname.replace(/\/$/, '') || '/';
        return normalizedPath === '/error/wip' || normalizedPath === '/account/login';
    };
    let intervalId = null;

    const cleanupIfAdmin = () => {
        if (!hasAdminCookie()) return false;
        if (intervalId !== null) clearInterval(intervalId);
        window.removeEventListener('popstate', enforceWip);
        document.removeEventListener('click', handleClick, true);
        return true;
    };

    const enforceWip = () => {
        if (cleanupIfAdmin()) return;
        const currentPath = window.location.pathname;
        // Only redirect if not on an allowed path
        if (!isAllowedPath(currentPath)) {
            window.location.replace('/error/wip');
        }
    };

    const handleClick = (event) => {
        if (cleanupIfAdmin()) return;
        if (event.defaultPrevented) return;
        if (event.button !== 0) return; // only left clicks
        const anchor = event.target.closest('a');
        if (!anchor) return;
        const href = anchor.getAttribute('href');
        if (!href) return;
        const targetUrl = new URL(href, window.location.href);
        if (isAllowedPath(targetUrl.pathname)) return;
        event.preventDefault();
        enforceWip();
    };

    enforceWip();

    // If already on an allowed path, don't set up enforcement
    if (isAllowedPath(window.location.pathname)) {
        return;
    }

    // Prevent navigation away while WIP is active
    document.addEventListener('click', handleClick, true);
    window.addEventListener('popstate', enforceWip);

    // Re-check periodically in case navigation slips through; stop if admin detected mid-session
    intervalId = setInterval(enforceWip, 1000);
}

function initWorkInProgressGuard() {
    if (workInProgress !== true) {
        if (window.location.pathname === '/error/wip') {
            window.location.replace('/');
        }
        return;
    }

    if (hasAdminCookie()) return;

    fetchAdminStatus().then((isAdmin) => {
        if (isAdmin) return;
        enableWipEnforcement();
    }).catch(() => {
        enableWipEnforcement();
    });
}

async function loadWorkInProgressFlag() {
    try {
        const res = await fetch('/data/etc/wip', { cache: 'no-store' });
        if (!res.ok) return;
        const raw = await res.text();
        const text = (raw || '').trim().toLowerCase();
        const truthy = new Set(['1', 'true', 'yes', 'y', 'on', 'wip']);
        workInProgress = truthy.has(text);
    } catch (_) {
        workInProgress = false;
    } finally {
        initWorkInProgressGuard();
        toggleMaintenanceBanner();
    }
}

loadWorkInProgressFlag();

function toggleMaintenanceBanner() {
    try {
        const banner = document.getElementById('maintenance-banner');
        if (!banner) return;
        banner.style.display = workInProgress === true ? 'inline' : 'none';
    } catch (_) {
        /* no-op */
    }
}

window.addEventListener('DOMContentLoaded', toggleMaintenanceBanner);

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
                targetUrl.hostname = 'm.fridg3.org';
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
window.addEventListener('DOMContentLoaded', initAsciiUsage);
window.addEventListener('DOMContentLoaded', initHourlyBeep);

// ASCII time initializer (safe for SPA reloads)
function initAsciiTime() {
    try {
        const el = document.getElementById('ascii-time');
        const labelEl = document.getElementById('ascii-time-label');
        if (!el || el.dataset.asciiTimeBound === '1') return;
        el.dataset.asciiTimeBound = '1';

        let fontMap = {};
        let maxLines = 0;
        let glyphWidth = 8;
        const charGap = 1;

        const pad = (str, width) => (typeof str === 'string' ? str : '').padEnd(width, ' ');

        const glyphWidthFor = (glyph) => {
            if (!Array.isArray(glyph) || !glyph.length) return glyphWidth;
            return glyph.reduce((m, l) => Math.max(m, l.length), 0);
        };

        const buildMap = (entries) => {
            const map = {};
            if (!Array.isArray(entries)) return map;
            entries.forEach((item) => {
                if (item && typeof item === 'object') {
                    if (typeof item.number === 'string' && typeof item.font === 'string') {
                        map[item.number] = item.font.split(/\r?\n/);
                    } else if (Object.prototype.hasOwnProperty.call(item, 'colon') && typeof item.colon === 'string') {
                        map[':'] = item.colon.split(/\r?\n/);
                    }
                }
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

        const render = () => {
            if (!maxLines || !Object.keys(fontMap).length) return;
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-GB', {
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                ...(isMobileTemplateActive() ? {} : { second: '2-digit' }),
                timeZone: 'Europe/London'
            });
            if (labelEl) {
                const tz = londonTzAbbrev(now);
                labelEl.textContent = `fridg3.org Server Time (${tz})`;
            }
            const rows = Array.from({ length: maxLines }, () => '');
            timeStr.split('').forEach((ch) => {
                const glyph = fontMap[ch] || [];
                const width = ch === ':' ? glyphWidthFor(glyph) : glyphWidth;
                const gap = ch === ':' ? 0 : charGap;
                for (let i = 0; i < maxLines; i += 1) {
                    rows[i] += pad(glyph[i] || '', width + gap);
                }
            });
            el.textContent = rows.join('\n');
            fitMobileAsciiLayout();
        };

        const loadFonts = async () => {
            const timeGlyphs = [
                { number: '1', font: "SsSSs.     \n  SSSSs    \n  S SSS    \n  S  SS    \n  S..SS    \n  S:::S    \n  S;;;S    \n  S%%%S    \nSsSSSSSsS  " },
                { number: '2', font: ".sSSSSs.   \n`SSSS SSSs.\n      SSSSS\n.sSSSsSSSS'\nS..SS      \nS:::S SSSs.\nS;;;S SSSSS\nS%%%S SSSSS\nSSSSSsSSSSS" },
                { number: '3', font: ".sSSSSSSs. \n`SSSS SSSSs\n      S SSS\n  .sS S  SS\n SSSSsS..SS\n  `:; S:::S\n      S;;;S\n.SSSS S%%%S\n`:;SSsSSSSS" },
                { number: '4', font: ".sSSS s.   \nSSSSS SSSs.\nS SSS SSSSS\nS  SS SSSSS\nS..SSsSSSSS\n      SSSSS\n      SSSSS\n      SSSSS\n      SSSSS" },
                { number: '5', font: "SSSSSSSSSs.\nSSSSS SSSS'\nS SSS      \nSSSSSsSSSs.\n      SSSSS\n.sSSS SSSSS\nS;;;S SSSSS\nS%%%S SSSSS\n`:;SSsSS;:'" },
                { number: '6', font: ".sSSSSs.   \nSSSSSSSSSs.\nS SSS SSSS'\nS  SS      \nS...SsSSSa.\nS:::S SSSSS\nS;;;S SSSSS\nS%%%S SSSSS\n`:;SSsSS;:'" },
                { number: '7', font: "SSSSSSSSSs.\nSSSSSSSSSSS\n     S SSS \n    S  SS  \n   S..SS   \n  S:::S    \n S;;;S     \nS%%%S      \nSSSSS      " },
                { number: '8', font: ".sSSSSs.   \nSSSSS SSSs.\nS SSS SSSSS\nS  SS SSSSS\n`..SSsSSSs'\ns:::S SSSSs\nS;;;S SSSSS\nS%%%S SSSSS\n`:;SSsSS;:'" },
                { number: '9', font: ".sSSSSs.   \nSSSSS SSSs.\nS SSS SSSSS\nS  SS SSSSS\n`..SSsSSSSS\n      SSSSS\n.sSSS SSSSS\nS%%%S SSSSS\n`:;SSsSS;:'" },
                { number: '0', font: ".sSSSSs.   \nSSSSSSSSSs.\nS SSS SSSSS\nS  SS SSSSS\nS..SS\\SSSSS\nS:::S SSSSS\nS;;;S SSSSS\nS%%%S SSSSS\n`:;SSsSS;:'" },
                { colon: " \n.sSs. \nS%%%S \n`:;:' \n      \n.sSs. \nS%%%S \n`:;:' " }
            ];

            try {
                fontMap = buildMap(timeGlyphs);
                if (!maxLines) throw new Error('No glyphs loaded');
                render();
                el._asciiTimeInterval = window.setInterval(render, 1000);
            } catch (err) {
                console.error('Failed to load ASCII time:', err);
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

        const renderValue = (value) => {
            if (!maxLines || !Object.keys(fontMap).length) return null;
            const safeVal = Number.isFinite(value) ? Math.max(0, Math.min(100, Math.round(value))) : null;
            const numStr = safeVal === null ? '??' : String(safeVal).padStart(2, '0');
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

        const applyReadings = (data) => {
            const cpu = renderValue(data.cpu);
            const mem = renderValue(data.memory);
            const disk = renderValue(data.disk);
            const diskAvail = renderValue(data.diskAvailable);
            if (cpuEl) cpuEl.textContent = cpu || '??%';
            if (memEl) memEl.textContent = mem || '??%';
            if (diskEl) diskEl.textContent = disk || '??%';
            if (diskAvailEl) diskAvailEl.textContent = diskAvail || '??%';
            fitMobileAsciiLayout();
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

// this script contains a shit ton of functionality for fridg3.org
// it sucks and i refuse to touch it without the guiding hand of AI
// no code for a website should need to span over 2000 lines of code
// but it works and thats what matters, right?

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

        if (host === 'fridg3.org' && mobile && mobileViewPreference !== false) {
            currentUrl.hostname = 'm.fridg3.org';
            window.location.replace(currentUrl.toString());
            return;
        }

        if (host === 'm.fridg3.org' && !mobile) {
            currentUrl.hostname = 'fridg3.org';
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

// Simple SPA-style navigation: load internal pages into #content
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
        return host === 'fridg3.org' || host === 'www.fridg3.org' || host === 'm.fridg3.org';
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

        doc.head.querySelectorAll('style').forEach((style) => {
            const css = style.textContent || '';
            if (!css.includes('frdgbeats-daw')) return;
            const key = 'spa-style:' + css.trim();
            const exists = Array.from(document.head.querySelectorAll('style[data-spa-style-key]'))
                .some(existing => existing.dataset.spaStyleKey === key);
            if (exists) return;
            const cloned = style.cloneNode(true);
            cloned.dataset.spaStyleKey = key;
            document.head.appendChild(cloned);
        });
    } catch (_) { /* no-op */ }
}

function executeContentScripts(rootEl) {
    try {
        if (!rootEl) return;
        const scripts = rootEl.querySelectorAll('script');
        scripts.forEach((oldScript) => {
            if (!oldScript) return;
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
        const contentEl = document.getElementById('content');
        if (!contentEl || !window.fetch || !window.DOMParser) {
            window.location.href = url;
            return;
        }

        closeMobileMenu();

        // Remove any currently visible tooltips when navigating
        clearTooltips();

        showSpaLoading();

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
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
                initToastDiscordBotPage();
                initBBCodeEditor();
                initToastFeedGenerator();
                initAsciiUsage();
                setupSpaForms();
                initOffTopicArchive();
                initSettingsPage();
                syncOnekoPreference();
                initAsciiTime();
                autoScaleAsciiFont();
                rerunAsciiScalingAfterContent();
                refreshAsciiLayoutAfterFontLoad();
                initTooltips();
                updateContentFooterSpacing();
                fitMobileAsciiLayout();
                updatePageViewFooter(url);

                // Re-run syntax highlighting on newly loaded content
                if (typeof hljs !== 'undefined') {
                    contentEl.querySelectorAll('pre code').forEach((block) => {
                        hljs.highlightElement(block);
                    });
                }
            })
            .catch(() => {
                window.location.href = url;
            })
            .finally(() => {
                hideSpaLoading();
            });
    } catch (_) {
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
                title: 'leaving fridg3.org',
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

        const contentEl = document.getElementById('content');
        if (!contentEl || !window.fetch || !window.DOMParser) {
            form.submit();
            return;
        }

        const formData = new FormData(form);

        // Ensure the clicked submit button's name/value (e.g., delete=1)
        // are included in the payload so multi-action forms work.
        if (e.submitter && e.submitter.name) {
            formData.append(e.submitter.name, e.submitter.value != null ? e.submitter.value : '');
        }

        fetch(action, {
            method,
            body: method === 'GET' ? null : formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(resp => {
                if (!resp.ok) {
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
                initOffTopicArchive();
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
            })
            .catch(() => {
                window.location.href = action;
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
        errorSpan.textContent = contentDiv.getAttribute('data-login-error');
        errorSpan.style.color = 'red';
        contentDiv.removeAttribute('data-login-error');
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

// Render the #off-topic archive in a Discord-like view
function initOffTopicArchive() {
    try {
        const rawPath = (window.location && window.location.pathname) ? window.location.pathname : '/';
        const path = rawPath.replace(/\/+$/, '') || '/';
        if (!path.startsWith('/others/off-topic-archive')) return;

        const root = document.getElementById('offtopic-archive');
        const messagesEl = document.getElementById('offtopic-messages');
        const searchInput = document.getElementById('offtopic-search');
        const statusEl = document.getElementById('offtopic-status');
        const sortBtn = document.getElementById('offtopic-sort');
        const loadMoreBtn = document.getElementById('offtopic-load-more');
        const errorEl = document.getElementById('offtopic-error');

        if (!root || !messagesEl || !searchInput || !statusEl || !sortBtn || !loadMoreBtn || !errorEl) return;
        if (root.dataset.bound === '1') return;
        root.dataset.bound = '1';

        const ARCHIVE_URL = '/data/etc/off-topic-archive.json';
        const PAGE_SIZE = 120;
        const DEFAULT_AVATAR = 'https://cdn.discordapp.com/embed/avatars/0.png';

        let rawMessages = [];
        let allMessages = [];
        let filteredMessages = [];
        let renderedCount = 0;
        let sortOrder = 'desc'; // 'desc' newest → oldest, 'asc' oldest → newest

        // Lazy-load Twemoji once and parse the archive container
        let twemojiLoaded = typeof window.twemoji !== 'undefined';
        function ensureTwemoji(callback) {
            if (typeof window.twemoji !== 'undefined') {
                twemojiLoaded = true;
                if (callback) callback();
                return;
            }
            if (twemojiLoaded === 'loading') {
                if (callback) {
                    document.addEventListener('twemoji-ready', callback, { once: true });
                }
                return;
            }
            twemojiLoaded = 'loading';
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/twemoji.min.js';
            script.async = true;
            script.onload = function() {
                twemojiLoaded = true;
                document.dispatchEvent(new Event('twemoji-ready'));
                if (callback) callback();
            };
            script.onerror = function() {
                twemojiLoaded = false;
            };
            document.head.appendChild(script);
        }

        function applyTwemoji() {
            if (typeof window.twemoji === 'undefined') {
                ensureTwemoji(applyTwemoji);
                return;
            }
            try {
                window.twemoji.parse(document.getElementById('offtopic-archive'));
            } catch (_) { /* no-op */ }
        }

        function sortMessages(list, order) {
            return (list || []).slice().sort(function(a, b) {
                const ta = a && a.timestamp ? new Date(a.timestamp).getTime() : 0;
                const tb = b && b.timestamp ? new Date(b.timestamp).getTime() : 0;
                if (isNaN(tb) && isNaN(ta)) return 0;
                if (isNaN(tb)) return -1;
                if (isNaN(ta)) return 1;
                return order === 'asc' ? ta - tb : tb - ta;
            });
        }

        function safeText(value) {
            return typeof value === 'string' ? value : '';
        }

        function escapeHtml(str) {
            return (str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function formatInlineMarkdown(str) {
            let out = escapeHtml(str);
            out = out.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            out = out.replace(/(^|[^*])\*(?!\*)([^*]+?)\*(?!\*)/g, function(_, prefix, content) {
                return prefix + '<em>' + content + '</em>';
            });
            return out;
        }

        function extractTenorIds(text) {
            const out = [];
            const re = /(https?:\/\/(?:www\.)?tenor\.com\/[^\s]*?-)(\d+)(?=[^\d]|$)/gi;
            let m;
            while ((m = re.exec(text || '')) !== null) {
                const id = m[2];
                if (id && out.indexOf(id) === -1) out.push(id);
            }
            return out;
        }

        function stripTenorLinks(text) {
            if (!text) return '';
            return text.replace(/https?:\/\/(?:www\.)?tenor\.com\/\S+/gi, '').trim();
        }

        function extractGifLinks(text) {
            const out = [];
            const re = /(https?:\/\/\S+?\.gif)(?=\s|$)/gi;
            let m;
            while ((m = re.exec(text || '')) !== null) {
                const url = m[1];
                if (url && out.indexOf(url) === -1) out.push(url);
            }
            return out;
        }

        function stripGifLinks(text) {
            if (!text) return '';
            return text.replace(/https?:\/\/\S+?\.gif(?=\s|$)/gi, '').trim();
        }

        function isImageAttachment(att) {
            const url = safeText(att && (att.url || att.proxyUrl));
            const name = safeText(att && (att.fileName || att.filename || ''));
            const stripQuery = (v) => v.split('?')[0].split('#')[0];
            const candidates = [stripQuery(url), stripQuery(name)].filter(Boolean);
            return candidates.some(function(val) {
                return /\.(png|jpe?g|gif|webp|bmp|tiff)$/i.test(val);
            });
        }

        function isVideoAttachment(att) {
            const url = safeText(att && (att.url || att.proxyUrl));
            const name = safeText(att && (att.fileName || att.filename || ''));
            const stripQuery = (v) => v.split('?')[0].split('#')[0];
            const candidates = [stripQuery(url), stripQuery(name)].filter(Boolean);
            return candidates.some(function(val) {
                return /\.(mp4|mov|webm)$/i.test(val);
            });
        }

        function formatTimestamp(ts) {
            try {
                const d = new Date(ts);
                if (isNaN(d.getTime())) return '';
                return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
            } catch (_) {
                return safeText(ts);
            }
        }

        function makeContentNode(msg) {
            const content = document.createElement('div');
            content.className = 'discord-message-content';
            const lines = safeText(msg && msg.content).split('\n');
            const formattedLines = [];
            lines.forEach(function(line, idx) {
                let cleaned = stripTenorLinks(line);
                cleaned = stripGifLinks(cleaned);
                if (cleaned === '') return; // skip lines that were only Tenor URLs
                formattedLines.push(formatInlineMarkdown(cleaned));
            });
            content.innerHTML = formattedLines.join('<br>');
            return content;
        }

        function appendGifEmbeds(msg, bodyEl) {
            const links = extractGifLinks(safeText(msg && msg.content));
            if (!links.length) return;
            const wrap = document.createElement('div');
            wrap.className = 'discord-attachments';
            links.forEach(function(url) {
                const img = document.createElement('img');
                img.className = 'discord-attachment-image';
                img.src = url;
                img.alt = 'gif';
                img.loading = 'lazy';
                img.referrerPolicy = 'no-referrer';
                img.onerror = function() {
                    img.onerror = null;
                    img.remove();
                };
                wrap.appendChild(img);
            });
            if (wrap.children.length) {
                bodyEl.appendChild(wrap);
            }
        }

        function appendTenorEmbeds(msg, bodyEl) {
            const ids = extractTenorIds(safeText(msg && msg.content));
            if (!ids.length) return;
            const wrap = document.createElement('div');
            wrap.className = 'discord-tenor-wrap';
            ids.forEach(function(id) {
                const outer = document.createElement('div');
                outer.className = 'discord-tenor';
                const iframe = document.createElement('iframe');
                iframe.src = 'https://tenor.com/embed/' + id;
                iframe.allowFullscreen = true;
                iframe.loading = 'lazy';
                iframe.referrerPolicy = 'no-referrer';
                outer.appendChild(iframe);
                wrap.appendChild(outer);
            });
            bodyEl.appendChild(wrap);
        }

        function renderAttachments(msg, bodyEl) {
            if (!msg || !Array.isArray(msg.attachments) || msg.attachments.length === 0) return;
            const wrap = document.createElement('div');
            wrap.className = 'discord-attachments';
            msg.attachments.forEach(function(att) {
                const url = safeText(att && (att.url || att.proxyUrl));
                if (!url) return;
                const displayName = safeText(att && (att.fileName || att.filename)) || url;
                if (isImageAttachment(att)) {
                    const img = document.createElement('img');
                    img.className = 'discord-attachment-image';
                    img.src = url;
                    img.alt = displayName || 'attachment';
                    img.loading = 'lazy';
                    img.referrerPolicy = 'no-referrer';
                    img.onerror = function() {
                        img.onerror = null;
                        img.remove();
                    };
                    wrap.appendChild(img);
                } else if (isVideoAttachment(att)) {
                    const vid = document.createElement('video');
                    vid.className = 'discord-attachment-video';
                    vid.src = url;
                    vid.controls = true;
                    vid.preload = 'metadata';
                    vid.playsInline = true;
                    vid.referrerPolicy = 'no-referrer';
                    vid.onerror = function() {
                        vid.onerror = null;
                        vid.remove();
                    };
                    wrap.appendChild(vid);
                } else {
                    const link = document.createElement('a');
                    link.href = url;
                    link.textContent = displayName;
                    link.target = '_blank';
                    link.rel = 'noreferrer noopener';
                    wrap.appendChild(link);
                }
            });
            if (wrap.children.length) {
                bodyEl.appendChild(wrap);
            }
        }

        function getAuthorName(msg) {
            const author = msg && msg.author ? msg.author : {};
            return safeText(author.nickname || author.name || 'Unknown');
        }

        function normalizeColorValue(val) {
            if (typeof val !== 'string') return null;
            const raw = val.trim();
            if (!raw) return null;
            const lower = raw.toLowerCase();

            // Hex formats
            if (/^#?[0-9a-f]{6}$/.test(lower)) {
                return lower.startsWith('#') ? lower : '#' + lower;
            }

            // rgb(...) formats
            const rgbMatch = lower.match(/^rgb\s*\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/);
            if (rgbMatch) {
                const r = parseInt(rgbMatch[1], 10);
                const g = parseInt(rgbMatch[2], 10);
                const b = parseInt(rgbMatch[3], 10);
                if (Number.isFinite(r) && Number.isFinite(g) && Number.isFinite(b)) {
                    const clamp = (n) => Math.max(0, Math.min(255, n));
                    return `rgb(${clamp(r)}, ${clamp(g)}, ${clamp(b)})`;
                }
            }
            return lower;
        }

        function getAuthorColor(msg) {
            try {
                const roles = (msg && msg.author && Array.isArray(msg.author.roles)) ? msg.author.roles : [];
                const firstColoredRole = roles.find(function(r) {
                    return r && typeof r.color === 'string' && r.color.trim() !== '' && r.color.toLowerCase() !== 'null';
                });
                const color = normalizeColorValue(firstColoredRole ? firstColoredRole.color : null);
                if (!color) return null;
                // Remap the specific blue-gray to neutral gray for readability
                if (color === '#8799ae' || color === 'rgb(135, 153, 174)') {
                    return '#cacaca';
                }
                return color;
            } catch (_) {
                return null;
            }
        }

        function createMessageEl(msg) {
            const row = document.createElement('div');
            row.className = 'discord-message';
            row.dataset.id = msg && msg.id ? msg.id : '';

            const avatar = document.createElement('img');
            avatar.className = 'discord-avatar';
            avatar.src = (msg && msg.author && msg.author.avatarUrl) ? msg.author.avatarUrl : DEFAULT_AVATAR;
            avatar.alt = getAuthorName(msg);
            avatar.loading = 'lazy';
            avatar.referrerPolicy = 'no-referrer';
            avatar.onerror = function() {
                avatar.onerror = null;
                avatar.src = DEFAULT_AVATAR;
            };

            const body = document.createElement('div');
            body.className = 'discord-body';

            const header = document.createElement('div');
            header.className = 'discord-header';

            const authorEl = document.createElement('span');
            authorEl.className = 'discord-author';
            authorEl.textContent = getAuthorName(msg);
            const authorColor = getAuthorColor(msg);
            if (authorColor) {
                authorEl.style.color = authorColor;
            }

            const ts = document.createElement('span');
            ts.className = 'discord-timestamp';
            ts.textContent = formatTimestamp(msg && msg.timestamp);

            header.appendChild(authorEl);
            header.appendChild(ts);
            body.appendChild(header);
            body.appendChild(makeContentNode(msg));
            appendTenorEmbeds(msg, body);
            appendGifEmbeds(msg, body);
            renderAttachments(msg, body);

            row.appendChild(avatar);
            row.appendChild(body);

            return row;
        }

        function updateStatus() {
            if (!filteredMessages.length) {
                statusEl.textContent = 'No messages found';
                return;
            }
            statusEl.textContent = renderedCount + ' of ' + filteredMessages.length + ' messages';
        }

        function renderChunk(reset) {
            if (reset) {
                messagesEl.innerHTML = '';
                renderedCount = 0;
            }
            const slice = filteredMessages.slice(renderedCount, renderedCount + PAGE_SIZE);
            slice.forEach(function(msg) {
                messagesEl.appendChild(createMessageEl(msg));
            });
            renderedCount += slice.length;
            loadMoreBtn.style.display = renderedCount < filteredMessages.length ? 'block' : 'none';
            updateStatus();
            applyTwemoji();
        }

        function applyFilter(term) {
            const needle = safeText(term).trim().toLowerCase();
            const base = (!needle ? rawMessages : rawMessages.filter(function(msg) {
                const content = safeText(msg && msg.content).toLowerCase();
                const authorName = getAuthorName(msg).toLowerCase();
                return content.indexOf(needle) !== -1 || authorName.indexOf(needle) !== -1;
            }));
            filteredMessages = sortMessages(base, sortOrder);
            renderChunk(true);
        }

        const debouncedFilter = (function() {
            let timer = null;
            return function(val) {
                if (timer) clearTimeout(timer);
                timer = setTimeout(function() {
                    applyFilter(val);
                }, 200);
            };
        })();

        searchInput.addEventListener('input', function(e) {
            debouncedFilter((e.target && e.target.value) || '');
        });

        loadMoreBtn.addEventListener('click', function() {
            renderChunk(false);
        });

        sortBtn.addEventListener('click', function() {
            sortOrder = sortOrder === 'desc' ? 'asc' : 'desc';
            sortBtn.textContent = sortOrder === 'desc' ? 'Sort: Newest → Oldest' : 'Sort: Oldest → Newest';
            applyFilter(searchInput.value || '');
        });

        statusEl.textContent = 'Loading archive...';

        fetch(ARCHIVE_URL, { cache: 'default' })
            .then(function(res) {
                if (!res.ok) throw new Error('failed to load archive');
                return res.json();
            })
            .then(function(data) {
                rawMessages = (data && Array.isArray(data.messages)) ? data.messages : [];
                filteredMessages = sortMessages(rawMessages, sortOrder);
                renderChunk(true);
                applyTwemoji();
            })
            .catch(function() {
                errorEl.style.display = 'block';
                errorEl.textContent = 'Could not load archive right now. Please try again later.';
                statusEl.textContent = '';
                loadMoreBtn.style.display = 'none';
            });
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', initOffTopicArchive);

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
const MOBILE_VIEW_DOMAIN = '.fridg3.org';
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
    return host === 'fridg3.org' || host === 'm.fridg3.org' || host.endsWith('.fridg3.org');
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
        const notification = new Notification(event.title || 'fridg3.org', {
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
    if (readFeedNotificationPromptSeen(kind)) return false;
    return true;
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
                ? 'fridg3.org can send browser notifications when people reply to your feed post.'
                : 'fridg3.org can send browser notifications when people reply to your comment.',
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
        if (host === 'm.fridg3.org' && cookieValue === null) {
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

            const actions = document.createElement('div');
            actions.className = 'site-popup-actions';
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'site-popup-button site-popup-ok';
            close.textContent = 'close';
            close.disabled = true;
            actions.appendChild(close);

            close.addEventListener('click', () => overlay.remove());
            dialog.append(title, detail, logLine, meter, percent, actions);
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
                    close.disabled = false;
                    close.focus();
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

// Sidebar toggle functionality
const hideSidebarBtn = document.getElementById('hide-sidebar');
const showSidebarBtn = document.getElementById('show-sidebar');
const sidebar = document.getElementById('sidebar');
const mobileCollapsedHeader = document.getElementById('mobile-collapsed-header');
const SIDEBAR_KEY = 'sidebarVisible';

function setSidebarVisible(visible, persist = true) {
    if (sidebar) {
        sidebar.style.display = 'flex';
    }
    document.body.classList.toggle('sidebar-is-hidden', !visible);
    if (showSidebarBtn) {
        showSidebarBtn.style.display = visible ? 'none' : 'inline-block';
    }
    updateMobileCollapsedHeader(!visible);
    if (persist) {
        try {
            localStorage.setItem(SIDEBAR_KEY, visible ? 'true' : 'false');
        } catch (_) { /* no-op */ }
    }
}

function updateMobileCollapsedHeader(visible) {
    if (!isMobileTemplateActive()) return;
    if (mobileCollapsedHeader) {
        mobileCollapsedHeader.style.display = visible ? 'flex' : 'none';
    }
}

function closeMobileMenu() {
    if (!isMobileTemplateActive()) return;
    setSidebarVisible(false);
}

// Load sidebar state, apply glow/gradient, and BBCode formatting
function initSidebarAndBBCode() {
    const isSidebarVisible = localStorage.getItem(SIDEBAR_KEY);
    const defaultSidebarVisible = isMobileTemplateActive() ? 'false' : 'true';
    if (isSidebarVisible === null) {
        // Mobile template starts closed by default; desktop starts open.
        localStorage.setItem(SIDEBAR_KEY, defaultSidebarVisible);
        if (defaultSidebarVisible === 'false' && sidebar && showSidebarBtn) {
            setSidebarVisible(false, false);
        }
    } else if (isSidebarVisible === 'false') {
        setSidebarVisible(false, false);
    } else {
        setSidebarVisible(true, false);
    }

    // Apply global glow effect based on saved intensity
    try {
        const storedIntensity = localStorage.getItem(GLOW_INTENSITY_KEY);
        const intensity = storedIntensity || GLOW_DEFAULT_INTENSITY;
        applyGlowIntensity(intensity);
    } catch (_) {
        applyGlowIntensity(GLOW_DEFAULT_INTENSITY);
    }

    // Start smooth gradient rotation for #ascii-gradient if present
    if (GRADIENT_RAF_ENABLED) {
        const asciiGradientEl = document.getElementById('ascii-gradient');
        if (asciiGradientEl) startGradientRotation(asciiGradientEl, GRADIENT_ROTATION_MS);
    }

    // Apply BBCode formatting to any post-content elements on the page
    try {
        const targets = document.querySelectorAll('#post-content, .post-content');
        targets.forEach(el => {
            const raw = el.textContent || '';
            const html = parseBBCode(raw);
            el.innerHTML = html;
            initInlineMediaPlayers(el);

            // Highlight any code blocks in formatted content
            if (typeof hljs !== 'undefined') {
                el.querySelectorAll('pre code').forEach((block) => {
                    hljs.highlightElement(block);
                });
            }

            // Attach tooltip listeners for any newly rendered elements
            el.querySelectorAll('[data-tooltip]').forEach(element => {
                element.addEventListener('mouseenter', function(e) {
                    const rawText = this.getAttribute('data-tooltip') || '';
                    const text = rawText.replace(/\\n/g, '<br>');
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip';
                    tooltip.innerHTML = text;
                    document.body.appendChild(tooltip);

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
                    this.addEventListener('mousemove', updateTooltipPosition);
                    this.addEventListener('mouseleave', () => {
                        tooltip.remove();
                    });
                });
            });
        });
    } catch (_) { /* no-op */ }
}

// Run once on initial load
window.addEventListener('DOMContentLoaded', initSidebarAndBBCode);

// Global mini player track library for autoplay logic
const MINI_PLAYER_LIBRARY = {
    tracks: [],
    autoPlayedIds: new Set()
};

// Mini music player wiring
function initMiniPlayer() {
    try {
        const audio = document.getElementById('mini-player-audio');
        const miniPlayerEl = document.getElementById('mini-player');
        const playBtn = document.getElementById('mini-player-play');
        const muteBtn = document.getElementById('mini-player-mute');
        const titleContainerEl = document.getElementById('mini-player-title');
        const titleEl = document.getElementById('mini-player-title-inner');
        const tracklistEl = document.getElementById('mini-player-tracks');
        const downloadBtn = document.getElementById('mini-player-download');
        const artEl = document.getElementById('mini-player-art');
        const seekEl = document.getElementById('mini-player-seek');
        const volumeEl = document.getElementById('mini-player-volume');

        const setLiveMode = (isLive) => {
            if (!miniPlayerEl) return;
            if (isLive) {
                miniPlayerEl.classList.add('live-stream');
                if (seekEl) seekEl.style.display = 'none';
                if (downloadBtn) downloadBtn.style.display = 'none';
            } else {
                miniPlayerEl.classList.remove('live-stream');
                if (seekEl) seekEl.style.display = '';
                if (downloadBtn) downloadBtn.style.display = '';
            }
        };

        if (!audio || !playBtn || !muteBtn || !titleContainerEl || !titleEl) return;

        const trackLibrary = MINI_PLAYER_LIBRARY;

        // If the mini player has already been wired once, avoid
        // re-attaching all audio/control listeners. Instead, just
        // (re)bind album links so new /music content can control the
        // existing, already-playing audio element.
        if (audio.dataset.initialized === '1') {
            // Ensure toast listen-along bindings still exist
            initToastListenAlong();
            if (window.bindMiniPlayerAlbumLinks) {
                window.bindMiniPlayerAlbumLinks();
            }
            return;
        }

        const PLAYER_STATE_KEY = 'miniPlayerStateV1';
        // Default volume if no saved state
        const DEFAULT_VOLUME = 0.3;
        let isSeeking = false;
        let lastVolume = 1;
        let lastTrackLabel = '';

        setLiveMode(false);

        // Optional initial state from body data attributes
        const body = document.body;
        const initialSrc = body.getAttribute('data-mini-player-src');
        const initialTitle = body.getAttribute('data-mini-player-title');
        const initialArt = body.getAttribute('data-mini-player-art');
        if (initialSrc) audio.src = initialSrc;
        if (initialTitle) {
            setNowPlayingTitle(initialTitle);
        }
        if (artEl && initialArt) artEl.src = initialArt;

        // Apply default volume unless we restore a saved state later
        audio.volume = DEFAULT_VOLUME;

        // Restore saved state if present (overrides default volume)
        try {
            const savedRaw = window.localStorage.getItem(PLAYER_STATE_KEY);
            if (savedRaw) {
                const saved = JSON.parse(savedRaw);
                if (saved && typeof saved === 'object') {
                    if (saved.src) audio.src = saved.src;
                    if (typeof saved.currentTime === 'number' && !Number.isNaN(saved.currentTime)) {
                        audio.currentTime = saved.currentTime;
                    }
                    if (typeof saved.volume === 'number' && saved.volume >= 0 && saved.volume <= 1) {
                        audio.volume = saved.volume;
                    }
                    if (typeof saved.muted === 'boolean') {
                        audio.muted = saved.muted;
                    }
                    if (saved.title && titleEl) {
                        setNowPlayingTitle(saved.title);
                    }
                    if (saved.art && artEl) {
                        artEl.src = saved.art;
                    }
                }
            }
        } catch (_) { /* no-op */ }

        const setPlayIcon = (isPlaying) => {
            const icon = playBtn.querySelector('i');
            if (!icon) return;
            if (isPlaying) {
                icon.classList.remove('fa-play');
                icon.classList.add('fa-pause');
            } else {
                icon.classList.remove('fa-pause');
                icon.classList.add('fa-play');
            }
        };

        const clearActiveTracks = () => {
            if (!tracklistEl) return;
            tracklistEl.querySelectorAll('.mini-track.active').forEach(el => el.classList.remove('active'));
        };

        const savePlayerState = () => {
            try {
                if (!audio || !audio.src) return;
                const state = {
                    src: audio.src,
                    currentTime: audio.currentTime || 0,
                    paused: audio.paused,
                    volume: audio.volume,
                    muted: audio.muted,
                    title: titleEl ? titleEl.textContent : '',
                    art: artEl ? artEl.src : ''
                };
                window.localStorage.setItem(PLAYER_STATE_KEY, JSON.stringify(state));
            } catch (_) { /* no-op */ }
        };

        // Capture the latest state right before a full page refresh/close
        window.addEventListener('beforeunload', savePlayerState);

        function updateTitleScroll() {
            if (!titleContainerEl || !titleEl) return;
            titleEl.classList.remove('scrolling');
            titleEl.style.transform = '';
            titleEl.style.removeProperty('--scroll-distance');
            titleEl.style.removeProperty('--scroll-duration');

            const containerWidth = titleContainerEl.clientWidth || 0;
            const contentWidth = titleEl.scrollWidth || 0;
            const overflow = contentWidth - containerWidth;
            if (overflow > 4) {
                const gap = 36;
                const distance = contentWidth + gap;
                const duration = Math.max(10, Math.min(26, distance / 18));
                titleEl.setAttribute('data-scroll-text', titleEl.textContent || '');
                titleEl.style.setProperty('--scroll-gap', gap + 'px');
                titleEl.style.setProperty('--scroll-distance', distance + 'px');
                titleEl.style.setProperty('--scroll-duration', duration + 's');
                // Restart the marquee after measurements so repeated title changes
                // begin from the readable start position.
                void titleEl.offsetWidth;
                titleEl.classList.add('scrolling');
            } else {
                titleEl.removeAttribute('data-scroll-text');
                titleEl.style.removeProperty('--scroll-gap');
            }
        }

        function setNowPlayingTitle(label) {
            if (!titleEl) return;
            titleEl.textContent = label;
            lastTrackLabel = label || '';
            // wait a frame so layout is updated
            requestAnimationFrame(updateTitleScroll);
        }

        window.addEventListener('resize', () => requestAnimationFrame(updateTitleScroll));

        const normalizeArtUrl = (art) => {
            if (!art) return null;
            try {
                return new URL(art, window.location.href).toString();
            } catch (_) {
                return null;
            }
        };

        const setMediaSessionMetadata = (meta) => {
            if (!('mediaSession' in navigator) || !meta) return;
            const artworkUrl = normalizeArtUrl(meta.albumArt || meta.art || '');
            const artwork = artworkUrl ? [
                { src: artworkUrl, sizes: '512x512', type: 'image/png' }
            ] : [];
            try {
                navigator.mediaSession.metadata = new MediaMetadata({
                    title: meta.name || meta.title || 'Unknown',
                    artist: meta.albumArtist || meta.artist || '',
                    album: meta.albumName || meta.album || '',
                    artwork
                });
            } catch (_) { /* no-op */ }
        };

        const bindMediaSessionActions = () => {
            if (!('mediaSession' in navigator)) return;
            try {
                navigator.mediaSession.setActionHandler('play', () => audio.play().catch(() => {}));
                navigator.mediaSession.setActionHandler('pause', () => audio.pause());
                navigator.mediaSession.setActionHandler('previoustrack', () => {/* not implemented */});
                navigator.mediaSession.setActionHandler('nexttrack', () => {
                    // trigger autoplay chain if available
                    handleAutoplayOnEnded();
                });
            } catch (_) { /* no-op */ }
        };

        const updatePlayingClass = () => {
            if (!miniPlayerEl) return;
            if (audio && !audio.paused && audio.src) {
                miniPlayerEl.classList.add('is-playing');
            } else {
                miniPlayerEl.classList.remove('is-playing');
            }
        };

        const updateVisibility = () => {
            if (!miniPlayerEl) return;
            if (audio && audio.src) {
                miniPlayerEl.classList.remove('mini-inactive');
            } else {
                miniPlayerEl.classList.add('mini-inactive');
            }
        };

        // --- Autoplay helpers ---

        // Current album run state for sequential album playback
        let albumRunState = null; // { albumId, nextIndex }

        const normalizeTrackSrc = (src) => {
            try {
                const u = new URL(src, window.location.origin);
                return u.pathname || src;
            } catch (_) {
                return src || '';
            }
        };

        const startAlbumRunFromIndex = (albumId, startingIndex) => {
            if (!albumId || startingIndex == null) {
                albumRunState = null;
                return;
            }
            const nextIndex = startingIndex + 1;
            const hasNext = trackLibrary.tracks.some(t => t.albumId === albumId && t.index === nextIndex && t.albumType === 'album');
            albumRunState = hasNext ? { albumId, nextIndex } : null;
        };

        const getRandomAutoplayTrack = () => {
            if (!trackLibrary.tracks.length) return null;
            const candidates = trackLibrary.tracks.filter(t => !trackLibrary.autoPlayedIds.has(t.id));
            if (!candidates.length) return null;
            const idx = Math.floor(Math.random() * candidates.length);
            return candidates[idx] || null;
        };

        const autoplayTrack = (track) => {
            if (!track || !track.src) return;
            const labelName = track.name || 'Untitled';
            const labelArtist = track.albumArtist ? ' - ' + track.albumArtist : '';

            trackLibrary.autoPlayedIds.add(track.id);

            audio.src = track.src;
            audio.play().catch(() => {});
            setLiveMode(false);
            setPlayIcon(true);
            if (artEl && track.albumArt) {
                artEl.src = track.albumArt;
            }
            setNowPlayingTitle(labelName + labelArtist);
            setMediaSessionMetadata({
                name: track.name,
                albumArtist: track.albumArtist,
                albumName: track.albumName,
                albumArt: track.albumArt
            });
            if (seekEl) {
                seekEl.value = '0';
            }
            savePlayerState();
            updateVisibility();
            updatePlayingClass();
        };

        const playAlbumTrack = (track, idx, albumMeta) => {
            if (!track) return;
            const src = track.directory || track.file_directory || '';
            if (!src) return;

            const meta = albumMeta || {};
            const name = track.name || `Track ${idx + 1}`;
            const artistLabel = meta.albumArtist ? ' - ' + meta.albumArtist : '';

            audio.src = src;
            audio.play().catch(() => {});
            setLiveMode(false);
            setPlayIcon(true);
            clearActiveTracks();
            if (tracklistEl) {
                tracklistEl.innerHTML = '';
                tracklistEl.style.display = 'none';
            }
            if (artEl) {
                artEl.src = meta.albumArt || '';
            }
            setNowPlayingTitle(name + artistLabel);
            setMediaSessionMetadata({
                name,
                albumArtist: meta.albumArtist || '',
                albumName: meta.albumName || '',
                albumArt: meta.albumArt || ''
            });
            if (seekEl) {
                seekEl.value = '0';
            }
            savePlayerState();
            updateVisibility();
            updatePlayingClass();
            startAlbumRunFromIndex(meta.albumId || '', idx);
        };

        const showAlbumTrackPopup = (albumMeta, tracks) => {
            const meta = albumMeta || {};
            const playableTracks = (tracks || [])
                .map((track, index) => ({ track, index }))
                .filter(entry => entry.track && (entry.track.directory || entry.track.file_directory));

            const overlay = document.createElement('div');
            overlay.className = 'site-popup-overlay album-track-popup-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            const dialog = document.createElement('div');
            dialog.className = 'site-popup-dialog album-track-popup-dialog';

            const title = document.createElement('div');
            title.className = 'site-popup-title';
            title.textContent = meta.albumName || 'album tracks';

            const detail = document.createElement('div');
            detail.className = 'site-popup-detail album-track-popup-detail';

            const close = () => {
                document.removeEventListener('keydown', onKeydown);
                overlay.classList.add('is-closing');
                window.setTimeout(() => overlay.remove(), 160);
            };

            const onKeydown = (event) => {
                if (event.key === 'Escape') close();
            };

            if (meta.albumArtist || meta.albumType) {
                const byline = document.createElement('div');
                byline.className = 'album-track-popup-meta';
                byline.textContent = [meta.albumArtist, meta.albumType].filter(Boolean).join(' / ');
                detail.append(byline);
            }

            const list = document.createElement('div');
            list.className = 'album-track-list';

            if (!playableTracks.length) {
                const empty = document.createElement('div');
                empty.className = 'album-track-empty';
                empty.textContent = 'no tracks defined';
                list.append(empty);
            } else {
                playableTracks.forEach((entry, idx) => {
                    const track = entry.track;
                    const trackIndex = entry.index;
                    const button = document.createElement('button');
                    button.className = 'album-track-button';
                    button.type = 'button';

                    const number = document.createElement('span');
                    number.className = 'album-track-number';
                    number.textContent = String(idx + 1).padStart(2, '0');

                    const name = document.createElement('span');
                    name.className = 'album-track-name';
                    name.textContent = track.name || `Track ${trackIndex + 1}`;

                    button.append(number, name);
                    button.addEventListener('click', () => {
                        playAlbumTrack(track, trackIndex, meta);
                        close();
                    });
                    list.append(button);
                });
            }

            detail.append(list);

            const actions = document.createElement('div');
            actions.className = 'site-popup-actions';

            const closeButton = document.createElement('button');
            closeButton.className = 'site-popup-button site-popup-ok';
            closeButton.type = 'button';
            closeButton.textContent = 'close';
            closeButton.addEventListener('click', close);
            actions.append(closeButton);

            dialog.append(title, detail, actions);
            overlay.append(dialog);
            overlay.addEventListener('click', event => {
                if (event.target === overlay) close();
            });
            document.addEventListener('keydown', onKeydown);
            document.body.append(overlay);

            const firstTrackButton = list.querySelector('.album-track-button');
            (firstTrackButton || closeButton).focus();
        };

        const handleAutoplayOnEnded = () => {
            try {
                const currentId = normalizeTrackSrc(audio.src || '');
                if (!currentId) return;

                const currentTrack = trackLibrary.tracks.find(t => t.id === currentId) || null;
                if (!currentTrack) return;

                // If we are in an album run, try to play the next track
                if (albumRunState && albumRunState.albumId === currentTrack.albumId) {
                    const nextTrack = trackLibrary.tracks.find(t => t.albumId === albumRunState.albumId && t.index === albumRunState.nextIndex && t.albumType === 'album') || null;
                    if (nextTrack) {
                        albumRunState.nextIndex += 1;
                        autoplayTrack(nextTrack);
                        return;
                    }
                    // No more tracks in this album; fall through to random
                    albumRunState = null;
                }

                // Otherwise, or after finishing an album, play a random track
                const randomTrack = getRandomAutoplayTrack();
                if (randomTrack) {
                    autoplayTrack(randomTrack);
                }
            } catch (_) { /* no-op */ }
        };
        // Download current track with filename based on "song name - artist"
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => {
                try {
                    if (!audio || !audio.src) return;
                    const src = audio.src;
                    // Derive a safe filename from the last track label
                    let baseName = lastTrackLabel || 'track';
                    // Strip any leading "now playing:" prefix
                    baseName = baseName.replace(/^now playing:\s*/i, '');
                    // Replace problematic filename characters
                    baseName = baseName.replace(/[\\/:*?"<>|]+/g, '-').trim() || 'track';

                    // Try to keep the original file extension, if any
                    let ext = '';
                    const srcPath = src.split('?')[0].split('#')[0];
                    const lastDot = srcPath.lastIndexOf('.');
                    if (lastDot > srcPath.lastIndexOf('/')) {
                        ext = srcPath.substring(lastDot);
                    }
                    const filename = ext ? baseName + ext : baseName;

                    const a = document.createElement('a');
                    a.href = src;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                } catch (_) { /* no-op */ }
            });
        }

        playBtn.addEventListener('click', () => {
            if (!audio.src) return;
            if (audio.paused) {
                audio.play().catch(() => {});
                setPlayIcon(true);
                savePlayerState();
            } else {
                audio.pause();
                setPlayIcon(false);
                savePlayerState();
            }
            updatePlayingClass();
        });

        muteBtn.addEventListener('click', () => {
            audio.muted = !audio.muted;
            const icon = muteBtn.querySelector('i');
            if (!icon) return;
            if (audio.muted) {
                icon.classList.remove('fa-volume-high');
                icon.classList.add('fa-volume-xmark');
                if (volumeEl) {
                    lastVolume = parseFloat(volumeEl.value || '1') || 1;
                    volumeEl.value = '0';
                }
            } else {
                icon.classList.remove('fa-volume-xmark');
                icon.classList.add('fa-volume-high');
                if (volumeEl) {
                    volumeEl.value = String(lastVolume || 1);
                }
            }
            savePlayerState();
        });

        audio.addEventListener('ended', () => {
            setPlayIcon(false);
            clearActiveTracks();
            savePlayerState();
            updatePlayingClass();
            // Trigger autoplay chain after a track finishes
            handleAutoplayOnEnded();
        });

        audio.addEventListener('play', updatePlayingClass);
        audio.addEventListener('pause', updatePlayingClass);

        // Seek bar wiring
        if (seekEl) {
            audio.addEventListener('loadedmetadata', () => {
                if (!isNaN(audio.duration) && audio.duration > 0) {
                    seekEl.max = String(audio.duration);
                    seekEl.value = '0';
                }
            });

            audio.addEventListener('timeupdate', () => {
                if (isSeeking) return;
                if (!isNaN(audio.currentTime)) {
                    seekEl.value = String(audio.currentTime);
                    // periodically persist playback position
                    savePlayerState();
                }
            });

            seekEl.addEventListener('input', () => {
                if (isNaN(audio.duration) || audio.duration <= 0) return;
                isSeeking = true;
                const val = parseFloat(seekEl.value || '0') || 0;
                audio.currentTime = Math.max(0, Math.min(val, audio.duration));
                isSeeking = false;
            });
        }

        // Volume slider wiring
        if (volumeEl) {
            audio.volume = 1;
            volumeEl.value = '1';
            volumeEl.addEventListener('input', () => {
                let vol = parseFloat(volumeEl.value || '1');
                if (isNaN(vol)) vol = 1;
                vol = Math.max(0, Math.min(vol, 1));
                audio.volume = vol;
                if (vol === 0) {
                    audio.muted = true;
                } else {
                    audio.muted = false;
                    lastVolume = vol;
                }
                const icon = muteBtn.querySelector('i');
                if (icon) {
                    if (audio.muted || vol === 0) {
                        icon.classList.remove('fa-volume-high');
                        icon.classList.add('fa-volume-xmark');
                    } else {
                        icon.classList.remove('fa-volume-xmark');
                        icon.classList.add('fa-volume-high');
                    }
                }
                savePlayerState();
            });
        }

        // Album grid integration: clicking entries controls the mini player.
        const bindAlbumLinks = () => {
            const albumLinks = document.querySelectorAll('.album-link');
            if (!albumLinks.length) return;

            // Rebuild track library from current album definitions
            trackLibrary.tracks = [];

            albumLinks.forEach(link => {
                // Avoid double-binding if called multiple times
                if (link.dataset.miniPlayerBound === '1') return;
                link.dataset.miniPlayerBound = '1';

                const albumType = (link.getAttribute('data-album-type') || '').toLowerCase();
                const albumName = link.getAttribute('data-album-name') || '';
                const albumArt = link.getAttribute('data-album-art') || '';
                const albumArtist = link.getAttribute('data-album-artist') || '';
                const tracksRaw = link.getAttribute('data-album-tracks') || '[]';

                let tracks;
                try {
                    tracks = JSON.parse(tracksRaw) || [];
                } catch (_) {
                    tracks = [];
                }

                const albumId = albumName + '|' + albumArtist + '|' + albumType;

                // Populate global track library with all tracks from this album
                if (tracks && tracks.length) {
                    tracks.forEach((track, idx) => {
                        const src = track.directory || track.file_directory || '';
                        if (!src) return;
                        const id = normalizeTrackSrc(src);
                        const name = track.name || `Track ${idx + 1}`;
                        trackLibrary.tracks.push({
                            id,
                            src,
                            name,
                            albumName,
                            albumArtist,
                            albumType,
                            albumId,
                            index: idx,
                            albumArt
                        });
                    });
                }

                link.addEventListener('click', (e) => {
                    e.preventDefault();

                    // For Singles and Remixes: play the first defined track
                    // directly in the mini player without showing a track list.
                    if (albumType !== 'album') {
                        if (!tracks.length) return;

                        const first = tracks.find(t => t && (t.directory || t.file_directory));
                        if (!first) return;

                        const src = first.directory || first.file_directory;
                        const name = first.name || albumName || 'Untitled';
                        if (artEl) {
                            artEl.src = albumArt || '';
                        }
                        audio.src = src;
                        audio.play().catch(() => {});
                        setLiveMode(false);
                        setPlayIcon(true);
                        clearActiveTracks();
                        if (tracklistEl) {
                            tracklistEl.innerHTML = '';
                            tracklistEl.style.display = 'none';
                        }
                        const artistLabelSingle = albumArtist ? ' - ' + albumArtist : '';
                        setNowPlayingTitle(name + artistLabelSingle);
                        setMediaSessionMetadata({
                            name,
                            albumArtist,
                            albumName,
                            albumArt
                        });
                        if (seekEl) {
                            seekEl.value = '0';
                        }
                        savePlayerState();
                        updateVisibility();
                        updatePlayingClass();
                        // Singles/Remixes do not start an album run; next autoplay is random
                        albumRunState = null;
                        return;
                    }

                    // For full Albums: show the track picker in the site popup.
                    clearActiveTracks();
                    if (tracklistEl) {
                        tracklistEl.innerHTML = '';
                        tracklistEl.style.display = 'none';
                    }
                    showAlbumTrackPopup({
                        albumName,
                        albumArtist,
                        albumType,
                        albumArt,
                        albumId
                    }, tracks);
                });
            });
        };

        // Bind immediately for the current page and expose a global
        // hook so SPA navigation can re-bind after content changes.
        bindAlbumLinks();
        window.bindMiniPlayerAlbumLinks = bindAlbumLinks;

        // Enable media session actions for hardware/notification controls
        bindMediaSessionActions();

        // Mark audio element as initialized so subsequent calls to
        // initMiniPlayer from SPA navigation only re-bind album
        // links instead of duplicating listeners.
        audio.dataset.initialized = '1';

        // Restore previous playback state (cross-page continuity)
        try {
            const rawState = window.localStorage.getItem(PLAYER_STATE_KEY);
            if (rawState) {
                const state = JSON.parse(rawState);
                if (state && state.src) {
                    audio.src = state.src;
                    if (typeof state.volume === 'number' && volumeEl) {
                        audio.volume = Math.max(0, Math.min(1, state.volume));
                        volumeEl.value = String(audio.volume);
                    }
                    audio.muted = !!state.muted;
                    if (muteBtn) {
                        const icon = muteBtn.querySelector('i');
                        if (icon) {
                            if (audio.muted || audio.volume === 0) {
                                icon.classList.remove('fa-volume-high');
                                icon.classList.add('fa-volume-xmark');
                            } else {
                                icon.classList.remove('fa-volume-xmark');
                                icon.classList.add('fa-volume-high');
                            }
                        }
                    }
                    if (artEl && state.art) {
                        artEl.src = state.art;
                    }
                    if (state.title) {
                        setNowPlayingTitle(state.title);
                    }
                    if (typeof state.currentTime === 'number' && state.currentTime > 0) {
                        audio.addEventListener('loadedmetadata', function restoreTimeOnce() {
                            audio.removeEventListener('loadedmetadata', restoreTimeOnce);
                            if (!isNaN(audio.duration) && state.currentTime <= audio.duration) {
                                audio.currentTime = state.currentTime;
                            }
                        });
                    }
                    // After a full page refresh, always start paused
                    // at the last known position instead of auto-playing.
                    audio.pause();
                    setPlayIcon(false);
                    updateVisibility();
                    updatePlayingClass();
                }
            }
        } catch (_) { /* no-op */ }

        // Ensure correct initial visibility when no track is loaded
        updateVisibility();
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', initMiniPlayer);

function syncAccountFooterButton() {
    try {
        const footerButtons = document.getElementById('footer-buttons');
        if (!footerButtons) return;

        const accountLink = footerButtons.querySelector('a[href="/account"], a[href="/account/login"], a[href="/account/logout"]');
        if (!accountLink) return;

        const accountButton = accountLink.querySelector('#footer-button');
        if (!accountButton) return;

        const isLoggedInNow = !!document.getElementById('user-greeting');
        accountLink.setAttribute('href', isLoggedInNow ? '/account/logout' : '/account');
        accountButton.setAttribute('data-tooltip', isLoggedInNow ? 'log out' : 'access your fridg3.org account');
        accountButton.innerHTML = isLoggedInNow
            ? '<i class="fa-solid fa-right-from-bracket"></i>'
            : '<i class="fa-solid fa-user"></i>';
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', syncAccountFooterButton);

function syncActiveChatSidebarButton() {
    try {
        const sidebarEl = document.getElementById('sidebar');
        if (!sidebarEl || !window.fetch) return;

        const existing = document.getElementById('sidebar-active-chat');
        const isLoggedInNow = !!document.getElementById('user-greeting');
        if (!isLoggedInNow) {
            if (existing) existing.remove();
            return;
        }

        fetch('/chat?action=active-account-chat', {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(resp => {
                if (!resp.ok) throw new Error('active chat unavailable');
                return resp.json();
            })
            .then(data => {
                const chat = data && data.ok ? data.chat : null;
                const current = document.getElementById('sidebar-active-chat');
                if (!chat || !chat.url) {
                    if (current) current.remove();
                    return;
                }

                const link = current || document.createElement('a');
                link.id = 'sidebar-active-chat';
                link.href = chat.url;
                link.setAttribute('data-tooltip', 'open your active private chat');
                link.innerHTML = '<i class="fa-solid fa-comment-dots"></i><span>active chat</span>';

                if (!current) {
                    const tracks = document.getElementById('mini-player-tracks');
                    const miniPlayer = document.getElementById('mini-player');
                    const footer = document.getElementById('sidebar-footer');
                    sidebarEl.insertBefore(link, tracks || miniPlayer || footer || null);
                }
            })
            .catch(() => {
                const current = document.getElementById('sidebar-active-chat');
                if (current) current.remove();
            });
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', syncActiveChatSidebarButton);

// Footer active state based on current path
function initFooterActiveState() {
    try {
        const rawPath = (window.location && window.location.pathname) ? window.location.pathname : '/';
        const path = rawPath.replace(/\/+$/, '') || '/'; // normalize trailing slash
        const footerButtons = document.getElementById('footer-buttons');
        if (!footerButtons) return;

        // Determine which footer link should be active
        let activeHref = null;
        if (path === '/') activeHref = '/';
        else if (path.startsWith('/discord')) activeHref = '/discord';
        else if (path.startsWith('/account')) activeHref = '/account'; // covers /account and /account/login
        else if (path.startsWith('/settings')) activeHref = '/settings';

        // Clear any existing active classes
        footerButtons.querySelectorAll('#footer-button.active').forEach(btn => btn.classList.remove('active'));

        // Apply active to the matching footer button
        if (activeHref) {
            const link = Array.from(footerButtons.querySelectorAll('a')).find(a => a.getAttribute('href') === activeHref);
            if (link) {
                const btn = link.querySelector('#footer-button');
                if (btn) btn.classList.add('active');
            }
        }

        // Mark mini player initialized
        audio.dataset.initialized = '1';

        // Ensure toast listen-along bindings exist
        initToastListenAlong();
    } catch (_) { /* no-op */ }
}

// Toast listen-along support (works with SPA navigation)
function initToastListenAlong() {
    if (window.__toastListenAlongBound) return;
    window.__toastListenAlongBound = true;

    document.addEventListener('click', (event) => {
        const btn = event.target && event.target.closest ? event.target.closest('#listen-along-button') : null;
        if (!btn) return;
        event.preventDefault();
        playToastStreamInMiniPlayer();
    });

    const audio = document.getElementById('mini-player-audio');
    if (audio && !audio.dataset.toastBound) {
        audio.dataset.toastBound = '1';
        audio.addEventListener('play', () => {
            const src = audio.currentSrc || audio.src || '';
            const candidates = (window.__toastStreamCandidates || []).map((c) => {
                try {
                    return new URL(c, window.location.origin).toString().split('?')[0];
                } catch (_) {
                    return (c || '').split('?')[0];
                }
            });
            const isToast = candidates.some(c => c && src.startsWith(c));
            setToastLiveControls(isToast);
            audio.dataset.toastLive = isToast ? '1' : '';
        });
    }
}

async function playToastStreamInMiniPlayer() {
    try {
        const response = await fetch('/api/discord-bot-status/');
        const data = await response.json();

        const streamUrlRaw = data && data.stream ? data.stream.url : '';
        const streamName = (data && data.stream && data.stream.name) ? data.stream.name : 'live stream';

        if (!streamUrlRaw) return;

        const audio = document.getElementById('mini-player-audio');
        if (!audio) {
            window.location.href = '/music';
            return;
        }

        const resolved = await resolveToastStreamUrl(streamUrlRaw);
        if (!resolved) return;

        const rawCandidates = buildToastStreamCandidates(resolved);
        if (!rawCandidates.length) return;

        // If a candidate is already https, play it directly; otherwise proxy to avoid mixed content
        const candidates = rawCandidates
            .map((u) => {
                try {
                    const parsed = new URL(u, window.location.href);
                    if (parsed.protocol === 'https:') return parsed.toString();
                    return buildToastProxyUrl(parsed.toString());
                } catch (_) {
                    return buildToastProxyUrl(u);
                }
            })
            .filter(Boolean);
        if (!candidates.length) return;

        window.__toastStreamCandidates = candidates.slice();

        const titleEl = document.getElementById('mini-player-title-inner');
        if (titleEl) {
            titleEl.textContent = streamName;
        }

        const artEl = document.getElementById('mini-player-art');
        const streamArt = 'https://images-ext-1.discordapp.net/external/S3f2i3R92rowfL9Uq5RmPFJtaqtluL-J7lVley9Ps7I/%3Fsize%3D4096/https/cdn.discordapp.com/avatars/1408177993284587794/2fd48df24ed679f3450b2532fce3f80b.png';
        if (artEl) {
            artEl.src = streamArt;
        }

        setToastLiveControls(true);

        let currentIndex = 0;
        const tryPlay = (idx) => {
            if (idx >= candidates.length) return;
            audio.src = candidates[idx];
            audio.play().catch(() => {});
        };

        const onError = () => {
            currentIndex += 1;
            if (currentIndex < candidates.length) {
                tryPlay(currentIndex);
            }
        };

        audio.addEventListener('error', onError, { once: true });
        tryPlay(0);

        const playIcon = document.querySelector('#mini-player-play i');
        if (playIcon) {
            playIcon.classList.remove('fa-play');
            playIcon.classList.add('fa-pause');
        }

        try {
            const state = {
                src: audio.src,
                currentTime: 0,
                paused: false,
                volume: audio.volume,
                muted: audio.muted,
                title: titleEl ? titleEl.textContent : '',
                art: streamArt
            };
            window.localStorage.setItem('miniPlayerStateV1', JSON.stringify(state));
        } catch (_) { /* no-op */ }
    } catch (err) {
        console.error('Failed to start listen-along:', err);
    }
}

function setToastLiveControls(isLive) {
    const miniPlayerEl = document.getElementById('mini-player');
    const seekEl = document.getElementById('mini-player-seek');
    const downloadBtn = document.getElementById('mini-player-download');
    if (miniPlayerEl) miniPlayerEl.classList.toggle('live-stream', !!isLive);
    if (seekEl) seekEl.style.display = isLive ? 'none' : '';
    if (downloadBtn) downloadBtn.style.display = isLive ? 'none' : '';
}

function buildToastProxyUrl(targetUrl) {
    if (!targetUrl) return null;
    try {
        const parsed = new URL(targetUrl, window.location.href);
        if (!/^https?:$/.test(parsed.protocol)) return null;
        return '/api/stream-proxy/?u=' + encodeURIComponent(parsed.toString());
    } catch (_) {
        return null;
    }
}

// If a toast stream is already loaded (e.g., after refresh), keep live controls hidden
async function ensureToastLiveControlsOnLoad() {
    try {
        const audio = document.getElementById('mini-player-audio');
        if (!audio) return;

        const src = audio.currentSrc || audio.src || '';
        if (!src) return;

        const statusResp = await fetch('/api/discord-bot-status/');
        if (!statusResp.ok) return;
        const data = await statusResp.json();
        const streamUrlRaw = data && data.stream ? data.stream.url : '';
        if (!streamUrlRaw) return;

        const resolved = await resolveToastStreamUrl(streamUrlRaw);
        if (!resolved) return;

        const rawCandidates = buildToastStreamCandidates(resolved);
        if (!rawCandidates.length) return;

        const candidates = rawCandidates
            .map((u) => {
                try {
                    const parsed = new URL(u, window.location.href);
                    if (parsed.protocol === 'https:') return parsed.toString();
                    return buildToastProxyUrl(parsed.toString());
                } catch (_) {
                    return buildToastProxyUrl(u);
                }
            })
            .filter(Boolean)
            .map((u) => {
                try {
                    return new URL(u, window.location.origin).toString().split('?')[0];
                } catch (_) {
                    return (u || '').split('?')[0];
                }
            });

        const audioSrc = (() => {
            try { return new URL(src, window.location.origin).toString().split('?')[0]; }
            catch (_) { return (src || '').split('?')[0]; }
        })();

        const isToast = candidates.some(c => c && audioSrc.startsWith(c));
        if (isToast) {
            window.__toastStreamCandidates = candidates.slice();
            setToastLiveControls(true);
            audio.dataset.toastLive = '1';
        }
    } catch (_) { /* no-op */ }
}

async function resolveToastStreamUrl(url) {
    if (!url) return null;
    const normalize = (u) => {
        if (!u) return u;
        const trimmed = u.trim();
        if (/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//.test(trimmed)) return trimmed;
        if (trimmed.startsWith('//')) return 'http:' + trimmed;
        return 'http://' + trimmed.replace(/\/$/, '');
    };

    const base = normalize(url);

    if (!/\.m3u8?$|\.pls$/i.test(base)) {
        return base;
    }

    try {
        const resp = await fetch(base);
        const text = await resp.text();

        if (/\.pls$/i.test(base)) {
            const match = text.match(/File\d+\s*=\s*(.+)/i);
            if (match && match[1]) {
                return normalize(match[1]);
            }
        }

        const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
        for (const line of lines) {
            if (line.startsWith('#')) continue;
            return normalize(line);
        }
    } catch (_) { /* no-op */ }

    return base;
}

function buildToastStreamCandidates(resolvedUrl) {
    if (!resolvedUrl) return [];
    try {
        const urlObj = new URL(resolvedUrl, window.location.href);
        const path = urlObj.pathname || '/';
        const hasPath = path && path !== '/' && path !== '';
        if (hasPath) return [urlObj.toString()];

        const origins = urlObj.origin;
        return [
            origins + '/;stream.nsv',
            origins + '/;?icy=http',
            origins + '/;stream.mp3',
            origins + '/;',
            origins + '/stream',
            origins + '/stream/',
            origins + '/live',
            origins + '/radio',
            origins + '/'
        ];
    } catch (_) {
        return [resolvedUrl];
    }
}

window.addEventListener('DOMContentLoaded', initFooterActiveState);
window.addEventListener('DOMContentLoaded', ensureToastLiveControlsOnLoad);

// Sidebar active state based on current path
function initSidebarActiveState() {
    try {
        const rawPath = (window.location && window.location.pathname) ? window.location.pathname : '/';
        const path = rawPath.replace(/\/+$/, '') || '/'; // normalize trailing slash
        const sidebarEl = document.getElementById('sidebar');
        if (!sidebarEl) return;

        // Map of sidebar routes
        const routes = [
            '/feed',
            '/journal',
            '/contact',
            '/guestbook',
            '/music',
            '/gallery',
            '/projects',
            '/merch',
            '/bookmarks',
            '/saves',
            '/tools',
            '/others',
        ];

        // Determine active href based on prefix match
        const activeHref = routes.find(r => path.startsWith(r)) || null;

        // Clear any existing active classes in sidebar tabs
        sidebarEl.querySelectorAll('#tab.active').forEach(tab => tab.classList.remove('active'));

        // Apply active to matching sidebar tab
        if (activeHref) {
            const link = Array.from(sidebarEl.querySelectorAll('a')).find(a => a.getAttribute('href') === activeHref);
            if (link) {
                const tab = link.querySelector('#tab');
                if (tab) tab.classList.add('active');
            }
        }
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', initSidebarActiveState);

if (hideSidebarBtn) {
    hideSidebarBtn.addEventListener('click', function() {
        setSidebarVisible(false);
    });
}

if (showSidebarBtn) {
    showSidebarBtn.addEventListener('click', function() {
        setSidebarVisible(true);
    });
}

// Detect logged-in state based on presence of the Logout footer link
function isLoggedIn() {
    try {
        return !!document.querySelector('a[href="/account/logout"]');
    } catch (_) {
        return false;
    }
}

// Bookmark helpers: localStorage-backed list of post IDs (for anonymous users only)
function getStoredBookmarks() {
    try {
        if (isLoggedIn()) return [];
        const raw = localStorage.getItem('bookmarkedPosts');
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
        return [];
    }
}

function setStoredBookmarks(list) {
    try {
        if (isLoggedIn()) return; // keep logged-in users server-side only
        const unique = Array.from(new Set(list.map(String)));
        localStorage.setItem('bookmarkedPosts', JSON.stringify(unique));
    } catch (_) {
        // ignore storage errors
    }
}

function syncBookmarkIcons() {
    if (isLoggedIn()) return; // logged-in users rely on server state, not localStorage
    const bookmarks = getStoredBookmarks();
    const postBookmarks = document.querySelectorAll('#post-bookmark, #post-bookmark-feed');
    postBookmarks.forEach(bookmark => {
        const icon = bookmark.querySelector('i');
        if (!icon) return;
        const id = bookmark.dataset.postId;
        if (id && bookmarks.includes(id)) {
            icon.classList.add('fa-solid');
            icon.classList.remove('fa-regular');
        } else {
            icon.classList.add('fa-regular');
            icon.classList.remove('fa-solid');
        }
    });
}
window.syncBookmarkIcons = syncBookmarkIcons;

function attachBookmarkBehavior(bookmark) {
    const icon = bookmark.querySelector('i');
    if (!icon) return;

    const postId = bookmark.dataset.postId || null;

    // Track the canonical bookmarked state on the element so that
    // hover effects can temporarily change the icon without losing
    // whether this post is actually bookmarked.
    if (!bookmark.dataset.bookmarked) {
        bookmark.dataset.bookmarked = icon.classList.contains('fa-solid') ? '1' : '0';
    }

    // Hover: always show solid while hovering
    bookmark.addEventListener('mouseenter', function() {
        icon.classList.add('fa-solid');
        icon.classList.remove('fa-regular');
    });

    bookmark.addEventListener('mouseleave', function() {
        // For logged-in users, revert to the element's own
        // bookmarked flag rather than localStorage.
        if (isLoggedIn()) {
            const isMarked = bookmark.dataset.bookmarked === '1';
            if (isMarked) {
                icon.classList.add('fa-solid');
                icon.classList.remove('fa-regular');
            } else {
                icon.classList.add('fa-regular');
                icon.classList.remove('fa-solid');
            }
            return;
        }

        // Anonymous users: derive state from localStorage
        const bookmarks = getStoredBookmarks();
        const isMarked = postId && bookmarks.includes(postId);
        bookmark.dataset.bookmarked = isMarked ? '1' : '0';
        if (isMarked) {
            icon.classList.add('fa-solid');
            icon.classList.remove('fa-regular');
        } else {
            icon.classList.add('fa-regular');
            icon.classList.remove('fa-solid');
        }
    });

    // Click: toggle bookmark, persist locally, sync to server, and reload preserving scroll
    bookmark.addEventListener('click', function(e) {
        if (!postId) return; // no-op for demo icons without an ID
        e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();

        // Logged-in users: toggle server-side bookmark and reflect
        // the new state on this element.
        if (isLoggedIn()) {
            const currentlyMarked = bookmark.dataset.bookmarked === '1';
            const nextMarked = !currentlyMarked;
            bookmark.dataset.bookmarked = nextMarked ? '1' : '0';
            if (nextMarked) {
                icon.classList.add('fa-solid');
                icon.classList.remove('fa-regular');
            } else {
                icon.classList.add('fa-regular');
                icon.classList.remove('fa-solid');
            }

            // Fire-and-forget server toggle
            try {
                fetch('/api/bookmark/index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ postId })
                })
                    .then(resp => {
                        if (!resp.ok) throw new Error('bookmark failed');
                    })
                    .catch(() => {
                        // Revert on failure so UI stays truthful
                        bookmark.dataset.bookmarked = currentlyMarked ? '1' : '0';
                        if (currentlyMarked) {
                            icon.classList.add('fa-solid');
                            icon.classList.remove('fa-regular');
                        } else {
                            icon.classList.add('fa-regular');
                            icon.classList.remove('fa-solid');
                        }
                        showSiteNotice('bookmark failed', 'could not update bookmark.');
                    });
                return; // no page reload needed
            } catch (_) {
                // If fetch setup fails, revert state
                bookmark.dataset.bookmarked = currentlyMarked ? '1' : '0';
                if (currentlyMarked) {
                    icon.classList.add('fa-solid');
                    icon.classList.remove('fa-regular');
                } else {
                    icon.classList.add('fa-regular');
                    icon.classList.remove('fa-solid');
                }
                showSiteNotice('bookmark failed', 'could not update bookmark.');
                return;
            }
        } else {
            // Anonymous users: maintain bookmarks in localStorage
            let bookmarks = getStoredBookmarks();
            const idx = bookmarks.indexOf(postId);
            if (idx === -1) {
                bookmarks.push(postId);
            } else {
                bookmarks.splice(idx, 1);
            }
            setStoredBookmarks(bookmarks);
            syncBookmarkIcons();

            // Fire-and-forget server sync (will 401 when not logged in, which is fine)
            try {
                fetch('/api/bookmark/index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ bookmarks })
                }).catch(() => {});
            } catch (_) { /* ignore */ }
        }
    });
}
window.attachBookmarkBehavior = attachBookmarkBehavior;

function initScrollAndBookmarkIcons() {
    // Restore scroll position if set
    try {
        const scrollKey = 'scroll:' + window.location.pathname + window.location.search;
        const saved = sessionStorage.getItem(scrollKey);
        if (saved !== null) {
            const y = parseInt(saved, 10);
            if (!isNaN(y)) {
                window.scrollTo(0, y);
            }
            sessionStorage.removeItem(scrollKey);
        }
    } catch (_) { /* no-op */ }

    // Attach bookmark behaviors
    try {
        const postBookmarks = document.querySelectorAll('#post-bookmark, #post-bookmark-feed');
        postBookmarks.forEach(attachBookmarkBehavior);

        // Ensure icons reflect stored state on initial load
        syncBookmarkIcons();
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', initScrollAndBookmarkIcons);

// Image lightbox functionality
const imageModal = document.createElement('div');
imageModal.className = 'image-modal';
document.body.appendChild(imageModal);

document.addEventListener('click', function(e) {
    // Support inline post images everywhere; grid lightbox only on /gallery
    if (e.target && e.target.closest && e.target.closest('.grid-delete-form')) {
        return; // allow delete buttons to use their own handlers
    }
    const rawPath = (window.location && window.location.pathname) ? window.location.pathname : '/';
    const path = rawPath.replace(/\/+$/, '') || '/';
    const allowGridLightbox = path === '/gallery' || path.startsWith('/gallery/');

    let targetImg = null;
    const clickedImg = e.target && e.target.closest ? e.target.closest('img') : null;

    if (clickedImg && clickedImg.closest('.album-link')) {
        return;
    }

    if (clickedImg && clickedImg.closest('.no-image-viewer')) {
        return;
    }

    // If toast stream is playing, clicking cover art should navigate to the toast page
    if (clickedImg && clickedImg.id === 'mini-player-art') {
        const miniPlayerEl = document.getElementById('mini-player');
        const isLive = miniPlayerEl && miniPlayerEl.classList.contains('live-stream');
        if (isLive) {
            e.preventDefault();
            e.stopPropagation();
            const targetUrl = '/others/toast-discord-bot';
            if (typeof loadPageIntoContent === 'function') {
                loadPageIntoContent(targetUrl);
            } else {
                window.location.href = targetUrl;
            }
            return;
        }
    }

    if (clickedImg && clickedImg.closest('.image-modal')) {
        return; // ignore clicks inside the modal itself
    }

    if (clickedImg && clickedImg.id === 'post-image') {
        targetImg = clickedImg;
    } else if (allowGridLightbox && clickedImg) {
        const fromGrid = clickedImg.closest('.grid-item');
        if (fromGrid) {
            targetImg = fromGrid.querySelector('.grid-image');
        }
    } else if (clickedImg) {
        targetImg = clickedImg; // fallback: any image opens in viewer
    }

    if (targetImg) {
        const imageSrc = targetImg.src;
        const filename = targetImg.alt || imageSrc.split('/').pop();
        const content = document.createElement('div');
        content.className = 'image-modal-content';
        
        const filenameSpan = document.createElement('span');
        filenameSpan.className = 'image-modal-filename';
        filenameSpan.textContent = filename;
        
        const modalImg = document.createElement('img');
        modalImg.src = imageSrc;
        
        const expandLink = document.createElement('a');
        expandLink.className = 'image-modal-expand';
        expandLink.textContent = 'click to expand';
        expandLink.href = imageSrc;
        expandLink.target = '_blank';
        
        content.appendChild(filenameSpan);
        content.appendChild(modalImg);
        content.appendChild(expandLink);
        
        imageModal.innerHTML = '';
        imageModal.appendChild(content);
        imageModal.classList.add('active');
    }
});

imageModal.addEventListener('click', function(e) {
    if (e.target === imageModal) {
        imageModal.classList.remove('active');
    }
});

// Admin-only gallery delete handler
async function submitGalleryDelete(form) {
    const filenameInput = form.querySelector('input[name="filename"]');
    const deleteButton = form.querySelector('.grid-delete-button');
    const filename = filenameInput ? filenameInput.value.trim() : '';
    if (!filename) return;

    const confirmed = await showSitePopup({
        title: 'delete image?',
        detail: 'delete ' + filename + '?',
        okText: 'delete',
        cancelText: 'cancel'
    });
    if (!confirmed) return;

    const originalLabel = deleteButton ? deleteButton.innerHTML : '';
    if (deleteButton) {
        deleteButton.disabled = true;
        deleteButton.innerHTML = '<i class="fa-solid fa-hourglass" aria-hidden="true"></i> deleting...';
    }

    try {
        const resp = await fetch('/api/gallery/delete/index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ filename })
        });

        let payload = {};
        try {
            payload = await resp.json();
        } catch (_) {
            payload = {};
        }

        if (!resp.ok || payload.ok !== true) {
            const message = payload.error || 'failed to delete image';
            throw new Error(message);
        }

        const card = form.closest('.grid-item');
        if (card) {
            card.remove();
        }
    } catch (err) {
        showSiteNotice('delete failed', err.message || 'failed to delete image.');
    } finally {
        if (deleteButton) {
            deleteButton.disabled = false;
            deleteButton.innerHTML = originalLabel || 'delete';
        }
    }
}

document.addEventListener('submit', function(e) {
    const form = e.target && e.target.closest ? e.target.closest('.grid-delete-form') : null;
    if (!form) return;
    if (!window.fetch) return; // fall back to normal submission when fetch isn't available
    e.preventDefault();
    submitGalleryDelete(form);
});

document.addEventListener('click', function(event) {
    const audioNote = event.target && event.target.closest ? event.target.closest('.feed-audio-note') : null;
    if (!audioNote) return;
    event.preventDefault();
}, true);

// Note: /bookmarks is rendered server-side from the user's bookmark JSON.

// Enhance /bookmarks with localStorage bookmarks for non-logged-in users
function enhanceBookmarksPage() {
    try {
        const rawPath = (window.location && window.location.pathname) ? window.location.pathname : '/';
        const path = rawPath.replace(/\/+$/, '') || '/';
        if (!(path.startsWith('/bookmarks') || path.startsWith('/saves'))) return;

        const container = document.getElementById('bookmarks-list');
        if (!container) return;

        const localIds = getStoredBookmarks();
        if (!localIds.length) return;

        const existingIds = new Set();
        container.querySelectorAll('#post-bookmark-feed[data-post-id]').forEach(el => {
            if (el.dataset.postId) existingIds.add(el.dataset.postId);
        });

        const idsToAdd = localIds.filter(id => !existingIds.has(id));
        if (!idsToAdd.length) return;

        // If container only has placeholder text, clear it before adding posts
        if (!container.querySelector('#post')) {
            container.innerHTML = '';
        }

        idsToAdd.forEach(id => {
            fetch('/api/feed-post/index.php?id=' + encodeURIComponent(id))
                .then(resp => resp.ok ? resp.json() : null)
                .then(data => {
                    if (!data) return;

                    const postLink = document.createElement('a');
                    postLink.href = '/feed/posts/?=' + encodeURIComponent(id);
                    postLink.className = 'feed-post-link';
                    postLink.style.textDecoration = 'none';
                    postLink.style.color = 'inherit';

                    const post = document.createElement('div');
                    post.id = 'post';
                    post.style.cursor = 'pointer';

                    const header = document.createElement('div');
                    header.id = 'post-header';

                    const userSpan = document.createElement('span');
                    userSpan.id = 'post-username';
                    userSpan.textContent = '@' + data.username;

                    const dateSpan = document.createElement('span');
                    dateSpan.id = 'post-date-feed';

                    const bookmarkSpan = document.createElement('span');
                    bookmarkSpan.id = 'post-bookmark-feed';
                    bookmarkSpan.dataset.tooltip = 'add to bookmarks';
                    bookmarkSpan.dataset.postId = id;
                    const icon = document.createElement('i');
                    icon.className = 'fa-regular fa-bookmark';
                    bookmarkSpan.appendChild(icon);

                    dateSpan.textContent = data.date_human + ' • ';
                    dateSpan.appendChild(bookmarkSpan);

                    header.appendChild(userSpan);
                    header.appendChild(dateSpan);

                    const bodySpan = document.createElement('span');
                    bodySpan.id = 'post-content';
                    bodySpan.textContent = data.body || '';

                    post.appendChild(header);
                    post.appendChild(bodySpan);
                    postLink.appendChild(post);
                    container.appendChild(postLink);

                    // Attach bookmark behavior and sync icon state for the new bookmark icon
                    attachBookmarkBehavior(bookmarkSpan);
                    syncBookmarkIcons();

                    // Apply BBCode formatting to this post body
                    try {
                        const raw = bodySpan.textContent || '';
                        const html = parseBBCode(raw);
                        bodySpan.innerHTML = html;
                        initInlineMediaPlayers(bodySpan);

                        if (typeof hljs !== 'undefined') {
                            bodySpan.querySelectorAll('pre code').forEach((block) => {
                                hljs.highlightElement(block);
                            });
                        }
                    } catch (_) { /* no-op */ }
                })
                .catch(() => { /* ignore */ });
        });
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', enhanceBookmarksPage);

// BBCode formatting state (images + file list) is global so that
// it can be reused when the editor is loaded via SPA navigation.
const bbcodeImages = new Map();
const bbcodeVoiceNotes = new Map();
const imageFileStore = new DataTransfer();
const voiceFileStore = new DataTransfer();
let isPreviewMode = false;
const VOICE_NOTE_MAX_MS = 120000;
const VOICE_NOTE_AUDIO_CONSTRAINTS = {
    echoCancellation: { ideal: true },
    noiseSuppression: { ideal: true },
    autoGainControl: { ideal: true }
};

function fridg3VoiceMimeType() {
    if (!window.MediaRecorder) return '';
    const candidates = [
        'audio/mp4',
        'audio/webm;codecs=opus',
        'audio/ogg;codecs=opus',
        'audio/webm'
    ];
    return candidates.find(type => {
        try {
            return MediaRecorder.isTypeSupported(type);
        } catch (_) {
            return false;
        }
    }) || '';
}

function fridg3VoiceExtension(mimeType) {
    const value = String(mimeType || '').toLowerCase();
    if (value.includes('mp4')) return 'm4a';
    if (value.includes('ogg')) return 'ogg';
    if (value.includes('webm')) return 'webm';
    return 'webm';
}

function fridg3CreateVoiceRecorder(container, onReady) {
    if (!container || container.dataset.voiceRecorderBound === '1') return null;
    container.dataset.voiceRecorderBound = '1';
    container.innerHTML = [
        '<div class="voice-recorder-status">ready to record</div>',
        '<div class="voice-recorder-timer">0:00 / 2:00</div>',
        '<div class="voice-recorder-preview" hidden></div>',
        '<div class="voice-recorder-actions">',
        '<button type="button" data-voice-action="record"><i class="fa-solid fa-microphone"></i><span>record</span></button>',
        '<button type="button" data-voice-action="accept" hidden><i class="fa-solid fa-check"></i><span>use</span></button>',
        '<button type="button" data-voice-action="discard" hidden><i class="fa-solid fa-xmark"></i><span>discard</span></button>',
        '</div>'
    ].join('');

    const status = container.querySelector('.voice-recorder-status');
    const timer = container.querySelector('.voice-recorder-timer');
    const preview = container.querySelector('.voice-recorder-preview');
    const recordBtn = container.querySelector('[data-voice-action="record"]');
    const recordIcon = recordBtn ? recordBtn.querySelector('i') : null;
    const recordText = recordBtn ? recordBtn.querySelector('span') : null;
    const acceptBtn = container.querySelector('[data-voice-action="accept"]');
    const discardBtn = container.querySelector('[data-voice-action="discard"]');
    let stream = null;
    let recorder = null;
    let chunks = [];
    let startedAt = 0;
    let tickTimer = null;
    let stopTimer = null;
    let currentBlob = null;
    let currentUrl = '';
    let currentMime = '';
    let isRecording = false;
    let isStopping = false;

    const setText = (text) => { if (status) status.textContent = text; };
    const formatTime = (seconds) => {
        seconds = Math.max(0, Math.floor(seconds || 0));
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return mins + ':' + (secs < 10 ? '0' : '') + secs;
    };
    const updateTimer = () => {
        const elapsed = startedAt ? Math.min(120, (Date.now() - startedAt) / 1000) : 0;
        if (timer) timer.textContent = formatTime(elapsed) + ' / 2:00';
    };
    const previewMode = () => container.classList.contains('chat-voice-recorder') ? 'chat' : 'feed';
    const clearPreview = () => {
        if (currentUrl) URL.revokeObjectURL(currentUrl);
        currentUrl = '';
        currentBlob = null;
        if (preview) {
            preview.innerHTML = '';
            preview.hidden = true;
        }
    };
    const reset = () => {
        if (tickTimer) clearInterval(tickTimer);
        if (stopTimer) clearTimeout(stopTimer);
        tickTimer = null;
        stopTimer = null;
        startedAt = 0;
        chunks = [];
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        recorder = null;
        isRecording = false;
        isStopping = false;
        if (recordBtn) recordBtn.hidden = false;
        if (recordBtn) recordBtn.disabled = false;
        if (recordIcon) {
            recordIcon.classList.add('fa-microphone');
            recordIcon.classList.remove('fa-stop');
        }
        if (recordText) recordText.textContent = 'record';
        if (acceptBtn) acceptBtn.hidden = true;
        if (discardBtn) discardBtn.hidden = true;
        updateTimer();
    };
    const stopRecording = () => {
        if (!recorder || !isRecording || isStopping) {
            return;
        }
        isStopping = true;
        if (recordBtn) recordBtn.disabled = true;
        setText('processing voice note...');
        if (recorder.state !== 'inactive') {
            recorder.stop();
        }
    };

    if (recordBtn) {
        recordBtn.addEventListener('click', async () => {
            if (isRecording) {
                stopRecording();
                return;
            }
            if (isStopping) return;

            clearPreview();
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
                setText('voice recording is not supported in this browser');
                return;
            }
            currentMime = fridg3VoiceMimeType();
            if (!currentMime) {
                setText('no supported audio recorder found');
                return;
            }
            try {
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ audio: VOICE_NOTE_AUDIO_CONSTRAINTS });
                } catch (constraintError) {
                    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                }
                recorder = new MediaRecorder(stream, {
                    mimeType: currentMime,
                    audioBitsPerSecond: 48000
                });
                chunks = [];
                recorder.addEventListener('dataavailable', event => {
                    if (event.data && event.data.size > 0) chunks.push(event.data);
                });
                recorder.addEventListener('error', () => {
                    reset();
                    setText('recording failed');
                });
                recorder.addEventListener('stop', () => {
                    const stoppedMime = recorder ? recorder.mimeType : currentMime;
                    currentBlob = new Blob(chunks, { type: stoppedMime || currentMime });
                    if (stream) {
                        stream.getTracks().forEach(track => track.stop());
                        stream = null;
                    }
                    if (tickTimer) clearInterval(tickTimer);
                    if (stopTimer) clearTimeout(stopTimer);
                    tickTimer = null;
                    stopTimer = null;
                    isRecording = false;
                    isStopping = false;
                    if (recordBtn) recordBtn.disabled = false;
                    recorder = null;
                    if (currentBlob.size <= 0) {
                        reset();
                        setText('recording failed');
                        return;
                    }
                    currentUrl = URL.createObjectURL(currentBlob);
                    if (preview) {
                        preview.innerHTML = renderChatStyleAudio(currentUrl, 'voice note', previewMode());
                        preview.hidden = false;
                        initInlineMediaPlayers(preview);
                    }
                    if (recordBtn) recordBtn.hidden = false;
                    if (recordIcon) {
                        recordIcon.classList.add('fa-microphone');
                        recordIcon.classList.remove('fa-stop');
                    }
                    if (recordText) recordText.textContent = 'record';
                    if (acceptBtn) acceptBtn.hidden = false;
                    if (discardBtn) discardBtn.hidden = false;
                    setText('preview your voice note');
                    updateTimer();
                });
                recorder.start();
                isRecording = true;
                isStopping = false;
                startedAt = Date.now();
                tickTimer = setInterval(updateTimer, 250);
                stopTimer = setTimeout(stopRecording, VOICE_NOTE_MAX_MS);
                if (recordIcon) {
                    recordIcon.classList.add('fa-stop');
                    recordIcon.classList.remove('fa-microphone');
                }
                if (recordText) recordText.textContent = 'stop';
                if (acceptBtn) acceptBtn.hidden = true;
                if (discardBtn) discardBtn.hidden = true;
                setText('recording...');
                updateTimer();
            } catch (_) {
                reset();
                setText('microphone access blocked');
            }
        });
    }

    if (discardBtn) {
        discardBtn.addEventListener('click', () => {
            clearPreview();
            reset();
            setText('ready to record');
        });
    }
    if (acceptBtn) {
        acceptBtn.addEventListener('click', () => {
            if (!currentBlob) return;
            const mime = currentBlob.type || currentMime || 'audio/webm';
            const ext = fridg3VoiceExtension(mime);
            const file = new File([currentBlob], 'voice-note.' + ext, { type: mime });
            if (typeof onReady === 'function') onReady(file, currentUrl);
            clearPreview();
            reset();
            setText('voice note added');
            container.hidden = true;
        });
    }

    return { reset, clearPreview };
}

window.fridg3CreateVoiceRecorder = fridg3CreateVoiceRecorder;

function renderChatStyleAudio(url, fileName, mode = 'feed') {
    const escapeAttr = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    const escapeText = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    const safeUrl = escapeAttr(url);
    const safeName = escapeText(fileName || 'voice note');
    const isChat = mode === 'chat';
    const classes = isChat
        ? 'chat-attachment chat-attachment-media chat-attachment-audio voice-preview-chat-note'
        : 'feed-audio-note feed-voice-note chat-attachment chat-attachment-media chat-attachment-audio';
    const label = isChat
        ? `<a class="chat-attachment-download" href="${safeUrl}"><i class="fa-solid fa-microphone"></i><span>${safeName}</span></a>`
        : '';
    return [
        `<div class="${classes}">`,
        `<audio class="chat-media-element" preload="metadata" src="${safeUrl}"></audio>`,
        label,
        '<div class="chat-media-player" data-media-kind="audio">',
        '<button class="chat-media-play" type="button" aria-label="play audio"><i class="fa-solid fa-play"></i></button>',
        '<input class="chat-media-seek" type="range" min="0" max="1000" value="0" step="1" aria-label="seek audio">',
        '<span class="chat-media-time">0:00 / 0:00</span>',
        '<button class="chat-media-speed" type="button" aria-label="playback speed"><span class="chat-media-speed-label">1x</span></button>',
        '</div>',
        '</div>'
    ].join('');
}

function initInlineMediaPlayers(root = document) {
    const scope = root && root.querySelectorAll ? root : document;
    const wraps = [];
    if (scope.matches && scope.matches('.chat-attachment-media')) {
        wraps.push(scope);
    }
    scope.querySelectorAll('.chat-attachment-media').forEach(function(wrap) {
        wraps.push(wrap);
    });
    wraps.forEach(function(wrap) {
        if (wrap.dataset.mediaBound === '1') return;
        const media = wrap.querySelector('.chat-media-element');
        const controls = wrap.querySelector('.chat-media-player');
        if (!media || !controls) return;
        wrap.dataset.mediaBound = '1';

        if (wrap.classList.contains('feed-audio-note')) {
            wrap.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
            });
        }

        const play = controls.querySelector('.chat-media-play');
        const playIcon = play ? play.querySelector('i') : null;
        const seek = controls.querySelector('.chat-media-seek');
        const time = controls.querySelector('.chat-media-time');
        const speed = controls.querySelector('.chat-media-speed');
        const speedLabel = speed ? speed.querySelector('.chat-media-speed-label') : null;
        const playbackSpeeds = [1, 1.5, 2];
        const format = (seconds) => {
            seconds = Number(seconds || 0);
            if (!isFinite(seconds) || seconds < 0) seconds = 0;
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return mins + ':' + (secs < 10 ? '0' : '') + secs;
        };
        const updatePlay = () => {
            if (!playIcon) return;
            playIcon.classList.toggle('fa-play', media.paused);
            playIcon.classList.toggle('fa-pause', !media.paused);
        };
        const updateTime = () => {
            const duration = isFinite(media.duration) ? media.duration : 0;
            if (seek && !seek.matches(':active')) {
                seek.value = duration > 0 ? String(Math.round((media.currentTime / duration) * 1000)) : '0';
            }
            if (time) time.textContent = format(media.currentTime) + ' / ' + format(duration);
        };
        const updateSpeed = () => {
            if (!speedLabel) return;
            const rate = playbackSpeeds.includes(media.playbackRate) ? media.playbackRate : 1;
            speedLabel.textContent = rate + 'x';
            if (speed) speed.setAttribute('aria-label', 'playback speed ' + rate + 'x');
        };

        if (play) {
            play.addEventListener('click', function() {
                if (media.paused) {
                    document.querySelectorAll('.chat-media-element').forEach(function(other) {
                        if (other !== media) other.pause();
                    });
                    media.play().catch(function() {});
                } else {
                    media.pause();
                }
            });
        }
        if (seek) {
            seek.addEventListener('input', function() {
                if (!isFinite(media.duration) || media.duration <= 0) return;
                media.currentTime = (Number(seek.value || 0) / 1000) * media.duration;
                updateTime();
            });
        }
        if (speed) {
            speed.addEventListener('click', function() {
                const currentIndex = playbackSpeeds.indexOf(media.playbackRate);
                const nextIndex = currentIndex === -1 ? 0 : (currentIndex + 1) % playbackSpeeds.length;
                media.playbackRate = playbackSpeeds[nextIndex];
                updateSpeed();
            });
        }
        media.addEventListener('loadedmetadata', updateTime);
        media.addEventListener('loadedmetadata', function resetPreviewSeekOnce() {
            media.removeEventListener('loadedmetadata', resetPreviewSeekOnce);
            if (media.currentTime > 0 && media.currentTime >= (isFinite(media.duration) ? media.duration - 0.05 : 0)) {
                media.currentTime = 0;
            }
            if (seek) seek.value = '0';
            updateTime();
        });
        media.addEventListener('timeupdate', updateTime);
        media.addEventListener('play', updatePlay);
        media.addEventListener('pause', updatePlay);
        media.addEventListener('ended', function() {
            updatePlay();
            updateTime();
        });
        media.addEventListener('ratechange', updateSpeed);
        updatePlay();
        updateTime();
        updateSpeed();
    });
}

// Compress images client-side to JPEG under 1MB (also converts PNG/GIF/WEBP to JPEG)
async function compressImageToJpegUnder1MB(file, maxBytes = 1000000) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = async function() {
            URL.revokeObjectURL(url);
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                reject(new Error('Canvas not supported'));
                return;
            }

            let width = img.naturalWidth || img.width;
            let height = img.naturalHeight || img.height;
            canvas.width = width;
            canvas.height = height;

            const drawWhite = () => {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            };

            const toBlobPromise = (quality) => new Promise(res => canvas.toBlob(res, 'image/jpeg', quality));

            let quality = 0.9;
            let blob = null;

            const tryCompress = async () => {
                drawWhite();
                blob = await toBlobPromise(quality);
                return blob && blob.size <= maxBytes;
            };

            // First pass: reduce quality
            while (quality >= 0.4) {
                const ok = await tryCompress();
                if (ok) break;
                quality -= 0.1;
            }

            // If still too big, scale down dimensions gradually and retry quality ladder
            let scale = 0.9;
            while (blob && blob.size > maxBytes && scale > 0.3) {
                width = Math.max(1, Math.floor(width * scale));
                height = Math.max(1, Math.floor(height * scale));
                canvas.width = width;
                canvas.height = height;
                quality = 0.9;
                while (quality >= 0.4) {
                    const ok = await tryCompress();
                    if (ok) break;
                    quality -= 0.1;
                }
                scale -= 0.1;
            }

            if (!blob) {
                reject(new Error('Compression failed'));
                return;
            }

            const baseName = (file.name || 'image').replace(/\.[^.]+$/, '') || 'image';
            const compressedFile = new File([blob], baseName + '.jpg', { type: 'image/jpeg' });
            resolve(compressedFile);
        };
        img.onerror = function() {
            URL.revokeObjectURL(url);
            reject(new Error('Image load failed'));
        };
        img.src = url;
    });
}

function initBBCodeEditor() {
    const bbcodeTextbox = document.getElementById('bbcode-textbox');
    const bbcodeEditor = bbcodeTextbox ? bbcodeTextbox.closest('.bbcode-editor') : null;
    const bbcodeScope = bbcodeEditor || document;
    const bbcodePreview = bbcodeScope.querySelector('#bbcode-preview');
    const bbcodePreviewToggle = bbcodeScope.querySelector('#bbcode-preview-toggle');
    const bbcodeHeaderDropdown = bbcodeScope.querySelector('#bbcode-header-dropdown');
    const bbcodeButtons = bbcodeScope.querySelectorAll('.bbcode-btn');

    // Avoid rebinding if this editor instance is already initialized
    if (!bbcodeTextbox || bbcodeTextbox.dataset.bbcodeInitialized === '1') return;
    bbcodeTextbox.dataset.bbcodeInitialized = '1';

    const guestFilterTermsScript = bbcodeScope.querySelector('[data-feed-guest-filter-terms]');
    let guestFilterTerms = [];
    if (guestFilterTermsScript) {
        try {
            const parsed = JSON.parse(guestFilterTermsScript.textContent || '[]');
            guestFilterTerms = Array.isArray(parsed)
                ? parsed.map(term => String(term || '').trim()).filter(Boolean)
                : [];
        } catch (_) {
            guestFilterTerms = [];
        }
        guestFilterTerms.sort((a, b) => Array.from(b).length - Array.from(a).length);
    }

    const escapeFilterTerm = (term) => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const filterWordEdge = /^[\p{L}\p{N}_]/u;
    const filterWordEnd = /[\p{L}\p{N}_]$/u;
    const filteredPhraseTooltip = 'this phrase was automatically filtered.';
    const applyGuestPreviewFilter = (text) => {
        if (!guestFilterTerms.length || !text) return text;
        let filtered = text;
        guestFilterTerms.forEach(term => {
            const needsStartBoundary = filterWordEdge.test(term);
            const needsEndBoundary = filterWordEnd.test(term);
            const prefix = needsStartBoundary ? '(^|[^\\p{L}\\p{N}_])' : '()';
            const suffix = needsEndBoundary ? '(?=$|[^\\p{L}\\p{N}_])' : '';
            const pattern = new RegExp(prefix + '(' + escapeFilterTerm(term) + ')' + suffix, 'giu');
            filtered = filtered.replace(pattern, (_match, before, matchedTerm) => {
                const stars = '★'.repeat(Math.max(1, Array.from(matchedTerm || '').length));
                return (before || '') + `[tooltip="${filteredPhraseTooltip}"]${stars}[/tooltip]`;
            });
        });
        return filtered;
    };

    bbcodeButtons.forEach(button => {
        button.addEventListener('click', function() {
            if (this.id === 'bbcode-preview-toggle' || this.id === 'bbcode-image-btn' || this.id === 'bbcode-voice-btn' || this.id === 'bbcode-color-btn' || this.id === 'bbcode-tooltip-btn' || this.id === 'bbcode-link-btn' || this.id === 'bbcode-spoiler-btn') return;
            
            const tag = this.getAttribute('data-tag');
            const start = bbcodeTextbox.selectionStart;
            const end = bbcodeTextbox.selectionEnd;
            const selectedText = bbcodeTextbox.value.substring(start, end);
            const beforeText = bbcodeTextbox.value.substring(0, start);
            const afterText = bbcodeTextbox.value.substring(end);
            
            // Use base tag for closing when tag contains an assignment (e.g., code=python)
            const closingTag = tag.includes('=') ? tag.split('=')[0] : tag;
            const newText = `[${tag}]${selectedText}[/${closingTag}]`;
            bbcodeTextbox.value = beforeText + newText + afterText;
            
            // Set cursor position after the inserted tags
            const newCursorPos = start + tag.length + 2 + selectedText.length;
            bbcodeTextbox.focus();
            bbcodeTextbox.setSelectionRange(newCursorPos, newCursorPos);
        });
    });
    
    // Header dropdown
    if (bbcodeHeaderDropdown) {
        bbcodeHeaderDropdown.addEventListener('change', function() {
            const tag = this.value;
            if (!tag) return;
            
            const start = bbcodeTextbox.selectionStart;
            const end = bbcodeTextbox.selectionEnd;
            const selectedText = bbcodeTextbox.value.substring(start, end);
            const beforeText = bbcodeTextbox.value.substring(0, start);
            const afterText = bbcodeTextbox.value.substring(end);
            
            const newText = `[${tag}]${selectedText}[/${tag}]`;
            bbcodeTextbox.value = beforeText + newText + afterText;
            
            // Set cursor position after the inserted tags
            const newCursorPos = start + tag.length + 2 + selectedText.length;
            bbcodeTextbox.focus();
            bbcodeTextbox.setSelectionRange(newCursorPos, newCursorPos);
            
            // Reset dropdown
            this.value = '';
        });
    }
        // Link insertion
        const bbcodeLinkBtn = document.getElementById('bbcode-link-btn');
        if (bbcodeLinkBtn) {
            bbcodeLinkBtn.addEventListener('click', function() {
                const defaultUrl = 'https://example.com';
                const start = bbcodeTextbox.selectionStart;
                const end = bbcodeTextbox.selectionEnd;
                const selectedText = bbcodeTextbox.value.substring(start, end);
                const beforeText = bbcodeTextbox.value.substring(0, start);
                const afterText = bbcodeTextbox.value.substring(end);

                const isLikelyUrl = (txt) => {
                    const t = (txt || '').trim();
                    return /^https?:\/\/\S+$/i.test(t) || /^www\..+/i.test(t);
                };

                let url = defaultUrl;
                let linkText = '';

                if (selectedText && isLikelyUrl(selectedText)) {
                    url = selectedText.trim();
                    if (/^www\./i.test(url)) {
                        url = 'https://' + url;
                    }
                    linkText = '';
                } else if (selectedText && selectedText.trim().length) {
                    url = defaultUrl;
                    linkText = selectedText;
                } else {
                    url = defaultUrl;
                    linkText = '';
                }

                const newText = `[link=${url}]${linkText}[/link]`;
                bbcodeTextbox.value = beforeText + newText + afterText;
                const newCursorPos = start + newText.length;
                bbcodeTextbox.focus();
                bbcodeTextbox.setSelectionRange(newCursorPos, newCursorPos);
            });
        }
    
    // Tooltip insertion
    const bbcodeTooltipBtn = document.getElementById('bbcode-tooltip-btn');
    if (bbcodeTooltipBtn) {
        bbcodeTooltipBtn.addEventListener('click', function() {
            const tipText = 'text here';
            const start = bbcodeTextbox.selectionStart;
            const end = bbcodeTextbox.selectionEnd;
            const selectedText = bbcodeTextbox.value.substring(start, end);
            const beforeText = bbcodeTextbox.value.substring(0, start);
            const afterText = bbcodeTextbox.value.substring(end);
            const openTag = `tooltip="${tipText}"`;
            const newText = `[${openTag}]${selectedText}[/tooltip]`;
            bbcodeTextbox.value = beforeText + newText + afterText;
            const newCursorPos = start + newText.length;
            bbcodeTextbox.focus();
            bbcodeTextbox.setSelectionRange(newCursorPos, newCursorPos);
        });
    }

    // Color picker
    const bbcodeColorBtn = document.getElementById('bbcode-color-btn');
    const bbcodeColorInput = document.getElementById('bbcode-color-input');
    
    if (bbcodeColorBtn && bbcodeColorInput) {
        bbcodeColorBtn.addEventListener('click', function() {
            bbcodeColorInput.click();
        });
        
        bbcodeColorInput.addEventListener('change', function() {
            const color = this.value.toUpperCase();
            const start = bbcodeTextbox.selectionStart;
            const end = bbcodeTextbox.selectionEnd;
            const selectedText = bbcodeTextbox.value.substring(start, end);
            const beforeText = bbcodeTextbox.value.substring(0, start);
            const afterText = bbcodeTextbox.value.substring(end);
            
            const newText = `[color:${color}]${selectedText}[/color]`;
            bbcodeTextbox.value = beforeText + newText + afterText;
            
            // Set cursor position after the inserted tags
            const newCursorPos = start + color.length + 9 + selectedText.length;
            bbcodeTextbox.focus();
            bbcodeTextbox.setSelectionRange(newCursorPos, newCursorPos);
        });
    }
    
    // Spoiler button
    const bbcodeSpoilerBtn = document.getElementById('bbcode-spoiler-btn');
    if (bbcodeSpoilerBtn) {
        bbcodeSpoilerBtn.addEventListener('click', function() {
            const start = bbcodeTextbox.selectionStart;
            const end = bbcodeTextbox.selectionEnd;
            const selectedText = bbcodeTextbox.value.substring(start, end);
            const beforeText = bbcodeTextbox.value.substring(0, start);
            const afterText = bbcodeTextbox.value.substring(end);
            
            const newText = `[spoiler]${selectedText}[/spoiler]`;
            bbcodeTextbox.value = beforeText + newText + afterText;
            bbcodeTextbox.focus();
            bbcodeTextbox.setSelectionRange(start + 9, start + 9 + selectedText.length);
        });
    }
    
    // Image attachment
    const bbcodeImageBtn = bbcodeScope.querySelector('#bbcode-image-btn');
    const bbcodeImageInput = bbcodeScope.querySelector('#bbcode-image-input');
    const bbcodeVoiceBtn = bbcodeScope.querySelector('.bbcode-voice-btn, #bbcode-voice-btn');
    const bbcodeVoiceInput = bbcodeScope.querySelector('#bbcode-voice-input');
    const bbcodeVoiceRecorder = bbcodeScope.querySelector('.bbcode-voice-recorder');

    const queueImageFile = async (file) => {
        let processedFile = file;
        try {
            processedFile = await compressImageToJpegUnder1MB(file, 1000000);
        } catch (_) {
            processedFile = file;
        }

        const fileIndex = imageFileStore.files.length;
        imageFileStore.items.add(processedFile);

        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = function(event) {
                const imageData = event.target.result;
                bbcodeImages.set(fileIndex, { data: imageData, name: processedFile.name });

                const start = bbcodeTextbox.selectionStart;
                const end = bbcodeTextbox.selectionEnd;
                const beforeText = bbcodeTextbox.value.substring(0, start);
                const afterText = bbcodeTextbox.value.substring(end);

                const newText = `[img:${fileIndex}][name:${processedFile.name}]`;
                bbcodeTextbox.value = beforeText + newText + afterText;
                const newCursorPos = start + newText.length;
                bbcodeTextbox.focus();
                bbcodeTextbox.setSelectionRange(newCursorPos, newCursorPos);
                resolve();
            };
            reader.readAsDataURL(processedFile);
        });
    };

    const handleImageFiles = async (incomingFiles) => {
        const files = (incomingFiles || []).filter(f => f && typeof f.type === 'string' && f.type.startsWith('image/'));
        if (!files.length) return false;

        // Process sequentially to keep placeholder order predictable
        for (const file of files) {
            await queueImageFile(file);
        }

        if (bbcodeImageInput) {
            bbcodeImageInput.files = imageFileStore.files;
        }
        return true;
    };

    if (bbcodeImageBtn && bbcodeImageInput) {
        bbcodeImageBtn.addEventListener('click', function() {
            const canSelectFile = !bbcodeImageInput.disabled;
            const promptDetail = canSelectFile
                ? 'enter an image URL, or leave blank to select a file.'
                : 'enter an image URL.';
            showSitePrompt('add image', promptDetail, '').then(function(imageUrl) {
                if (imageUrl === null) return;

                if (imageUrl.trim()) {
                    // URL provided - use [img=URL][name:filename] format
                    const start = bbcodeTextbox.selectionStart;
                    const end = bbcodeTextbox.selectionEnd;
                    const beforeText = bbcodeTextbox.value.substring(0, start);
                    const afterText = bbcodeTextbox.value.substring(end);

                    const url = imageUrl.trim();
                    const fileName = url.split('/').pop() || 'image';
                    const newText = `[img=${url}][name:${fileName}]`;
                    bbcodeTextbox.value = beforeText + newText + afterText;
                    bbcodeTextbox.focus();
                    bbcodeTextbox.setSelectionRange(start + newText.length, start + newText.length);
                } else if (canSelectFile) {
                    // Open file picker
                    bbcodeImageInput.click();
                }
            });
        });
        
        bbcodeImageInput.addEventListener('change', async function(e) {
            const used = await handleImageFiles(Array.from(e.target.files || []));
            if (used) {
                bbcodeImageInput.files = imageFileStore.files;
            }
        });
    }

    if (bbcodeVoiceBtn && bbcodeVoiceInput && bbcodeVoiceRecorder) {
        fridg3CreateVoiceRecorder(bbcodeVoiceRecorder, function(file) {
            const fileIndex = voiceFileStore.files.length;
            voiceFileStore.items.add(file);
            bbcodeVoiceInput.files = voiceFileStore.files;
            bbcodeVoiceNotes.set(fileIndex, {
                url: URL.createObjectURL(file),
                name: file.name || 'voice-note.m4a'
            });

            const start = bbcodeTextbox.selectionStart;
            const end = bbcodeTextbox.selectionEnd;
            const beforeText = bbcodeTextbox.value.substring(0, start);
            const afterText = bbcodeTextbox.value.substring(end);
            const newText = `[voice:${fileIndex}][name:${file.name || 'voice-note.m4a'}]`;
            bbcodeTextbox.value = beforeText + newText + afterText;
            bbcodeTextbox.focus();
            bbcodeTextbox.setSelectionRange(start + newText.length, start + newText.length);
        });

        bbcodeVoiceBtn.addEventListener('click', function() {
            bbcodeVoiceRecorder.hidden = !bbcodeVoiceRecorder.hidden;
        });
    }

    // Paste support for images into the editor
    if (bbcodeTextbox) {
        bbcodeTextbox.addEventListener('paste', async function(e) {
            const files = [];
            const items = e.clipboardData ? Array.from(e.clipboardData.items || []) : [];
            items.forEach(item => {
                if (item.kind === 'file') {
                    const file = item.getAsFile();
                    if (file && file.type && file.type.startsWith('image/')) {
                        files.push(file);
                    }
                }
            });
            if (!files.length && e.clipboardData && e.clipboardData.files) {
                files.push(...Array.from(e.clipboardData.files).filter(f => f && f.type && f.type.startsWith('image/')));
            }

            const used = await handleImageFiles(files);
            if (used) {
                e.preventDefault();
            }
        });
    }
    
    // Journal drafts: clicking a draft loads it into the form fields
    try {
        const titleInput = document.querySelector('input[name="title"]');
        const descriptionInput = document.querySelector('textarea[name="description"]');
        if (titleInput && descriptionInput && bbcodeTextbox) {
            document.querySelectorAll('.journal-draft-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const t = this.dataset.draftTitle || '';
                    const d = this.dataset.draftDescription || '';
                    const body = this.dataset.draftContent || '';
                    titleInput.value = t;
                    descriptionInput.value = d;
                    bbcodeTextbox.value = body;
                });
            });

            // Delete draft buttons (styled like feed edit icon)
            document.querySelectorAll('#post-edit-feed[data-draft-id]').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const id = this.getAttribute('data-draft-id');
                    if (!id) return;
                    showSitePopup({
                        title: 'delete draft?',
                        detail: 'this draft will be removed.',
                        okText: 'delete',
                        cancelText: 'cancel'
                    }).then(function(confirmed) {
                        if (!confirmed) return;

                        const form = document.getElementById('create-post-form');
                        if (!form) return;

                        let hidden = form.querySelector('input[name="delete_draft"]');
                        if (!hidden) {
                            hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'delete_draft';
                            form.appendChild(hidden);
                        }
                        hidden.value = id;
                        form.submit();
                    });
                });
            });
        }
    } catch (_) { /* no-op */ }

    // Preview toggle
    if (bbcodePreviewToggle && bbcodePreview) {
        bbcodePreviewToggle.addEventListener('click', function(e) {
            const createForm = document.getElementById('create-post-form');
            const isJournalCreateForm = !!(createForm && createForm.querySelector('button[name="save_draft"]'));
            if (isJournalCreateForm) {
                e.preventDefault();
                const addOrUpdateHidden = (name, value) => {
                    let input = createForm.querySelector('input[name="' + name + '"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        createForm.appendChild(input);
                    }
                    input.value = value;
                };

                addOrUpdateHidden('save_draft', '1');
                addOrUpdateHidden('open_preview', '1');
                if (typeof createForm.requestSubmit === 'function') {
                    createForm.requestSubmit();
                } else {
                    createForm.submit();
                }
                return;
            }

            isPreviewMode = !isPreviewMode;
            
            if (isPreviewMode) {
                // Show preview
                const bbcodeText = bbcodeTextbox.value;
                const htmlText = parseBBCode(applyGuestPreviewFilter(bbcodeText));
                bbcodePreview.innerHTML = htmlText;
                initInlineMediaPlayers(bbcodePreview);
                bbcodeTextbox.style.display = 'none';
                bbcodePreview.style.display = 'block';
                
                // Highlight code blocks
                if (typeof hljs !== 'undefined') {
                    bbcodePreview.querySelectorAll('pre code').forEach((block) => {
                        hljs.highlightElement(block);
                    });
                }
                
                // Attach tooltip listeners for newly rendered preview content
                bbcodePreview.querySelectorAll('[data-tooltip]').forEach(element => {
                    element.addEventListener('mouseenter', function(e) {
                        const rawText = this.getAttribute('data-tooltip') || '';
                        const text = rawText.replace(/\\n/g, '<br>');
                        const tooltip = document.createElement('div');
                        tooltip.className = 'tooltip';
                        tooltip.innerHTML = text;
                        document.body.appendChild(tooltip);
                        
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
                        this.addEventListener('mousemove', updateTooltipPosition);
                        this.addEventListener('mouseleave', () => {
                            tooltip.remove();
                        });
                    });
                });
                
                // Disable toolbar buttons
                bbcodeButtons.forEach(button => {
                    if (button.id !== 'bbcode-preview-toggle') {
                        button.classList.add('disabled');
                    }
                });
                if (bbcodeHeaderDropdown) bbcodeHeaderDropdown.classList.add('disabled');
                const bbcodeLinkBtn = document.getElementById('bbcode-link-btn');
                if (bbcodeLinkBtn) bbcodeLinkBtn.classList.add('disabled');
            } else {
                // Show editor
                bbcodeTextbox.style.display = 'block';
                bbcodePreview.style.display = 'none';
                
                // Enable toolbar buttons
                bbcodeButtons.forEach(button => {
                    button.classList.remove('disabled');
                });
                if (bbcodeHeaderDropdown) bbcodeHeaderDropdown.classList.remove('disabled');
                const bbcodeLinkBtn = document.getElementById('bbcode-link-btn');
                if (bbcodeLinkBtn) bbcodeLinkBtn.classList.remove('disabled');
            }
        });
    }
}

// Ensure the BBCode editor is wired on initial page load
window.addEventListener('DOMContentLoaded', initBBCodeEditor);

function initToastFeedGenerator() {
    const generator = document.getElementById('toast-feed-generator');
    if (!generator || generator.dataset.bound === '1') return;
    generator.dataset.bound = '1';

    const editor = document.getElementById('bbcode-textbox');
    const form = document.getElementById('create-post-form');
    const promptBox = document.getElementById('toast-feed-prompt');
    const lengthSlider = document.getElementById('toast-feed-length');
    const lengthLabel = document.getElementById('toast-feed-length-label');
    const generateBtn = generator.querySelector('[data-action="toast-generate-feed"]');
    const status = document.getElementById('toast-feed-generator-status');
    const modeRadios = generator.querySelectorAll('input[name="toast-feed-mode"]');
    const postButton = form ? form.querySelector('[data-toast-post-button="1"], button[type="submit"]') : null;
    if (!editor || !generateBtn) return;
    let generatedDraftReady = false;
    let placeholderTimer = null;

    const controls = () => {
        const scope = editor.closest('.bbcode-editor') || document;
        return Array.from(scope.querySelectorAll('.bbcode-btn, .bbcode-dropdown, input[type="file"], input[type="color"]'));
    };

    const setStatus = (message, isError) => {
        if (!status) return;
        status.textContent = message || '';
        status.style.color = isError ? 'red' : 'var(--subtle)';
    };

    const setEditorLocked = (locked, placeholder) => {
        editor.readOnly = locked;
        if (placeholder !== undefined) {
            editor.placeholder = placeholder;
        }
        controls().forEach(control => {
            control.disabled = locked;
            control.classList.toggle('disabled', locked);
        });
        if (postButton) {
            postButton.disabled = locked;
        }
    };

    const stopPlaceholderAnimation = () => {
        if (placeholderTimer !== null) {
            window.clearInterval(placeholderTimer);
            placeholderTimer = null;
        }
    };

    const startPlaceholderAnimation = () => {
        stopPlaceholderAnimation();
        let dots = 0;
        const tick = () => {
            dots = (dots % 3) + 1;
            editor.placeholder = 'writing a post' + '.'.repeat(dots);
        };
        tick();
        placeholderTimer = window.setInterval(tick, 360);
    };

    const selectedMode = () => {
        let mode = 'random';
        modeRadios.forEach(radio => {
            if (radio.checked) mode = radio.value;
        });
        return mode;
    };

    const syncPromptVisibility = () => {
        if (!promptBox) return;
        promptBox.style.display = selectedMode() === 'prompt' ? '' : 'none';
    };

    const syncLengthLabel = () => {
        if (!lengthSlider || !lengthLabel) return;
        const labels = {
            1: 'one-liner',
            2: 'short',
            3: 'normal',
            4: 'ramble',
            5: 'trauma dump',
        };
        const value = Number(lengthSlider.value);
        const min = Number(lengthSlider.min || 1);
        const max = Number(lengthSlider.max || 5);
        const percent = max > min ? ((value - min) / (max - min)) * 100 : 0;
        lengthSlider.style.setProperty('--toast-feed-length-fill', percent + '%');
        lengthLabel.textContent = labels[value] || 'normal';
    };

    modeRadios.forEach(radio => {
        radio.addEventListener('change', syncPromptVisibility);
    });
    if (lengthSlider) {
        lengthSlider.addEventListener('input', syncLengthLabel);
        syncLengthLabel();
    }
    syncPromptVisibility();
    setEditorLocked(true, 'generate a post first...');

    if (form) {
        form.addEventListener('submit', function(e) {
            if (generatedDraftReady) return;
            e.preventDefault();
            setEditorLocked(true, 'generate a post first...');
            setStatus('generate a draft before posting.', true);
        });
    }

    generateBtn.addEventListener('click', async () => {
        const mode = selectedMode();
        const prompt = promptBox ? promptBox.value.trim() : '';
        if (mode === 'prompt' && !prompt) {
            setStatus('prompt mode needs a prompt.', true);
            if (promptBox) promptBox.focus();
            return;
        }

        const originalText = generateBtn.textContent;
        generatedDraftReady = false;
        generateBtn.disabled = true;
        generateBtn.textContent = 'writing...';
        setStatus('', false);
        setEditorLocked(true, 'writing a post...');
        startPlaceholderAnimation();

        try {
            const params = new URLSearchParams();
            params.append('mode', mode);
            params.append('prompt', prompt);
            params.append('length', lengthSlider ? lengthSlider.value : '3');
            const res = await fetch('/api/toast-feed-generate/index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: params.toString(),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data || !data.ok || typeof data.content !== 'string') {
                throw new Error(data && data.message ? data.message : 'generation failed.');
            }

            editor.value = data.content;
            generatedDraftReady = true;
            setEditorLocked(false, '');
            editor.focus();
            setStatus('draft ready.', false);
        } catch (err) {
            generatedDraftReady = false;
            setEditorLocked(true, 'generate a post first...');
            setStatus((err && err.message) ? err.message : 'generation failed.', true);
        } finally {
            stopPlaceholderAnimation();
            generateBtn.textContent = originalText;
            generateBtn.disabled = false;
        }
    });
}

window.addEventListener('DOMContentLoaded', initToastFeedGenerator);

// BBCode parser
function parseBBCode(text) {
    // Extract and temporarily store URLs from [img=URL] and [link=URL] before HTML sanitization
    const imgUrlMap = new Map();
    const linkUrlMap = new Map();
    const codeBlockMap = new Map();
    const tooltipMap = new Map();
    let imgCounter = 0;
    let linkCounter = 0;
    let codeCounter = 0;
    let tooltipCounter = 0;
    
    // Replace [code=lang]...[/code] with placeholder to preserve newlines
    text = text.replace(/\[code=(\w+)\](.*?)\[\/code\]/gis, function(match, lang, code) {
        const id = codeCounter++;
        codeBlockMap.set(id, { lang, code });
        return `[code-placeholder:${id}]`;
    });
    
    // Replace [tooltip="text"]content[/tooltip] with placeholder
    text = text.replace(/\[tooltip="(.*?)"\](.*?)\[\/tooltip\]/gis, function(match, tip, content) {
        const id = tooltipCounter++;
        tooltipMap.set(id, { tip, content });
        return `[tooltip-placeholder:${id}]`;
    });
    
    // Replace [img=URL][name:filename] or [img=URL] with placeholder
    // Accept http(s) URLs, root-relative paths (/data/images/...), or simple filenames
    text = text.replace(/\[img=([^\]\s]+)\](?:\[name:(.*?)\])?/gi, function(match, url, customName) {
        const id = imgCounter++;
        imgUrlMap.set(id, { url, name: customName });
        return `[img-placeholder:${id}]`;
    });

    const audioUrlMap = new Map();
    let audioCounter = 0;
    text = text.replace(/\[audio=([^\]\s]+)\](?:\[name:(.*?)\])?/gi, function(match, url, customName) {
        const id = audioCounter++;
        audioUrlMap.set(id, { url, name: customName });
        return `[audio-placeholder:${id}]`;
    });
    
    // Replace [link=URL]text[/link] with placeholder
    text = text.replace(/\[link=(https?:\/\/[^\]]+)\](.*?)\[\/link\]/gis, function(match, url, text) {
        const id = linkCounter++;
        linkUrlMap.set(id, { url, text });
        return `[link-placeholder:${id}]`;
    });
    
    // Escape HTML to prevent raw HTML injection
    let html = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    // Allow explicit <br> tags that users include by unescaping them from the escaped text
    html = html.replace(/&lt;br\s*\/?&gt;/gi, '<br>');
    
    html = html.replace(/\[b\](.*?)\[\/b\]/gi, '<strong>$1</strong>');
    html = html.replace(/\[i\](.*?)\[\/i\]/gi, '<em>$1</em>');
    html = html.replace(/\[u\](.*?)\[\/u\]/gi, '<u>$1</u>');
    html = html.replace(/\[s\](.*?)\[\/s\]/gi, '<s>$1</s>');
    // Simple markdown-style bold/italic using asterisks
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/(^|[^*])\*(?!\*)([^*]+?)\*(?!\*)/g, function(_, prefix, content) {
        return prefix + '<em>' + content + '</em>';
    });
    html = html.replace(/\[h3\](.*?)\[\/h3\]/gi, '<h3>$1</h3>');
    html = html.replace(/\[h4\](.*?)\[\/h4\]/gi, '<h4>$1</h4>');
    html = html.replace(/\[h5\](.*?)\[\/h5\]/gi, '<h5>$1</h5>');
    html = html.replace(/\[spoiler\](.*?)\[\/spoiler\]/gi, '<span class="spoiler">$1</span>');
    html = html.replace(/\[color:(#[0-9A-F]{6})\](.*?)\[\/color\]/gi, '<span style="color: $1;">$2</span>');
    html = html.replace(/(^|[\s([{"'>])@([a-zA-Z0-9_-]{1,50})(?=$|[\s)\]}",.!?:;<])/g, function(match, prefix, username) {
        return `${prefix}<span class="inline-mention">@${username}</span>`;
    });
    // Lists: [list]line1\nline2[/list] -> <ul><li>line1</li><li>line2</li></ul>
    html = html.replace(/\[list\]([\s\S]*?)\[\/list\]/gi, function(match, inner) {
        const lines = inner.split(/\r?\n/).map(l => l.trim()).filter(l => l.length > 0);
        if (!lines.length) return '';
        const items = lines.map(l => `<li>${l}</li>`).join('');
        return `<ul>${items}</ul>`;
    });
    // Remove newlines immediately after heading closing tags
    html = html.replace(/<\/h3>\n/g, '<\/h3>');
    html = html.replace(/<\/h4>\n/g, '<\/h4>');
    html = html.replace(/<\/h5>\n/g, '<\/h5>');
    // Restore [tooltip] placeholders
    html = html.replace(/\[tooltip-placeholder:(\d+)\]/gi, function(match, id) {
        const tooltipData = tooltipMap.get(parseInt(id));
        if (tooltipData) {
            return `<span data-tooltip="${tooltipData.tip}">${tooltipData.content}</span>`;
        }
        return match;
    });
    // Restore [link=URL] placeholders
    html = html.replace(/\[link-placeholder:(\d+)\]/gi, function(match, id) {
        const linkData = linkUrlMap.get(parseInt(id));
        if (linkData) {
            const trimmedText = (linkData.text || '').trim();
            const linkText = trimmedText.length ? trimmedText : linkData.url;
            return `<a href="${linkData.url}" data-tooltip="${linkData.url}" target="_blank">${linkText}</a>`;
        }
        return match;
    });
    // Restore [img=URL] placeholders
    html = html.replace(/\[img-placeholder:(\d+)\]/gi, function(match, id) {
        const imgData = imgUrlMap.get(parseInt(id));
        if (imgData) {
            const fileName = imgData.name || imgData.url.split('/').pop() || 'image';
            return `<img id="post-image" src="${imgData.url}" alt="${fileName}" style="max-width: 100%; height: auto;">`;
        }
        return match;
    });
    // [img:ID][name:...] -> <img src="data URL">
    html = html.replace(/\[img:(\d+)\](?:\[name:(.*?)\])?/gi, function(match, id, customName) {
        const imageObj = bbcodeImages.get(parseInt(id));
        if (imageObj) {
            const fileName = customName || imageObj.name || 'image';
            return `<img id="post-image" src="${imageObj.data}" alt="${fileName}" style="max-width: 100%; height: auto;">`;
        }
        return match;
    });
    html = html.replace(/\[audio-placeholder:(\d+)\]/gi, function(match, id) {
        const audioData = audioUrlMap.get(parseInt(id));
        if (!audioData) return match;
        const fileName = audioData.name || audioData.url.split('/').pop() || 'audio';
        return renderChatStyleAudio(audioData.url, fileName);
    });
    html = html.replace(/\[voice:(\d+)\](?:\[name:(.*?)\])?/gi, function(match, id, customName) {
        const voiceObj = bbcodeVoiceNotes.get(parseInt(id));
        if (!voiceObj) return match;
        const fileName = customName || voiceObj.name || 'voice-note.m4a';
        return renderChatStyleAudio(voiceObj.url, fileName);
    });
    html = html.replace(/(<div class="[^"]*\bfeed-audio-note\b[\s\S]*?<\/div>\s*<\/div>)\n+/gi, '$1');
    // Convert newlines to <br> for regular content
    html = html.replace(/\n/g, '<br>'); // actual newline characters
    html = html.replace(/\\n/g, '<br>'); // literal "\n" sequences
    // Restore code blocks with preserved newlines (after <br> conversion)
    html = html.replace(/\[code-placeholder:(\d+)\]/gi, function(match, id) {
        const codeData = codeBlockMap.get(parseInt(id));
        if (codeData) {
            return `<pre><code class="language-${codeData.lang}">${codeData.code}</code></pre>`;
        }
        return match;
    });
    // Remove any <br> immediately following a code block; CSS margins provide spacing
    html = html.replace(/<\/pre>(<br\s*\/?\s*>)+/gi, '</pre>');

    // Do not allow <br> directly after h3 or h4 headings
    html = html.replace(/(<\/h3>)(<br\s*\/?\s*>)+/gi, '</h3>');
    html = html.replace(/(<\/h4>)(<br\s*\/?\s*>)+/gi, '</h4>');

    // Remove a single <br> directly after each h5 heading (leave any additional spacing)
    html = html.replace(/<\/h5><br\s*\/?\s*>/gi, '</h5>');

    // Remove a single <br> immediately before any h3 heading (collapse extra blank line)
    html = html.replace(/<br\s*\/?\s*>\s*(<h3[^>]*>)/gi, '$1');
    return html;
}

function initToastDiscordBotPage() {
    try {
        const hasToastPage = document.getElementById('control-panel-container') || document.getElementById('listen-along-button');
        if (!hasToastPage) return;

        ensureToastCardStyles();
        toastCheckAdminStatus();
        toastUpdateNowPlaying();

        if (window.__toastStatusInterval) {
            clearInterval(window.__toastStatusInterval);
        }
        window.__toastStatusInterval = setInterval(toastUpdateNowPlaying, 5000);
    } catch (_) { /* no-op */ }
}

function ensureToastCardStyles() {
    if (document.getElementById('toast-discord-card-style')) return;
    const style = document.createElement('style');
    style.id = 'toast-discord-card-style';
    style.textContent = `
@font-face { font-family: 'GG Sans'; src: url('/others/toast-discord-bot/gg-sans-regular.ttf') format('truetype'); font-weight: normal; font-style: normal; }
@font-face { font-family: 'GG Sans'; src: url('/others/toast-discord-bot/gg-sans-bold.ttf') format('truetype'); font-weight: bold; font-style: normal; }
.profile-card { font-family: 'GG Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
.profile-name { font-size: 15px; font-family: 'GG Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-weight: bold; line-height: 1.2; }
.profile-username { font-size: 12px; line-height: 1.2; }
.status-line { font-size: 12px; }
.now-playing { font-size: 12px; }
`;
    document.head.appendChild(style);
}

async function toastCheckAdminStatus() {
    try {
        const response = await fetch('/api/account/is-admin/');
        const data = await response.json();
        const isAdmin = data && data.isAdmin === true;

        const controlPanel = document.getElementById('control-panel-container');
        if (controlPanel) {
            controlPanel.style.display = isAdmin ? 'block' : 'none';
        }

        const updateBtn = document.getElementById('update-stream-button');
        if (updateBtn) {
            updateBtn.disabled = !isAdmin;
            if (!isAdmin) {
                updateBtn.style.opacity = '0.5';
                updateBtn.style.cursor = 'not-allowed';
            }
        }
    } catch (_) {
        const controlPanel = document.getElementById('control-panel-container');
        if (controlPanel) {
            controlPanel.style.display = 'none';
        }
    }
}

async function toastUpdateNowPlaying() {
    try {
        const response = await fetch('/api/discord-bot-status/');
        const data = await response.json();

        const statusDot = document.querySelector('.status-dot');
        const statusText = document.querySelector('.status-line .muted');
        const nowPlayingEl = document.querySelector('.now-playing');

        if (data && data.bot && data.bot.status) {
            const isOnline = String(data.bot.status).toLowerCase() === 'online';
            if (statusDot) statusDot.style.background = isOnline ? '#6ccf6c' : '#cf6c6c';
            if (statusText) statusText.textContent = isOnline ? 'Online' : 'Offline';
            if (nowPlayingEl) nowPlayingEl.style.display = isOnline ? 'block' : 'none';
        }

        if (data && data.stream && data.stream.name) {
            const nameEl = document.getElementById('now-playing-name');
            if (nameEl) nameEl.textContent = data.stream.name;
        }

    } catch (_) {
        const statusDot = document.querySelector('.status-dot');
        const statusText = document.querySelector('.status-line .muted');
        const nowPlayingEl = document.querySelector('.now-playing');
        if (statusDot) statusDot.style.background = '#cf6c6c';
        if (statusText) statusText.textContent = 'Offline';
        if (nowPlayingEl) nowPlayingEl.style.display = 'none';
        const nameEl = document.getElementById('now-playing-name');
        if (nameEl) nameEl.textContent = 'Unknown';
    }
}
