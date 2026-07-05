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

function consumeLegacyDomainRedirectNotice() {
    try {
        const currentUrl = new URL(window.location.href);
        const marker = currentUrl.searchParams.get('legacy_domain');
        let cameFromLegacyDomain = marker === 'fridg3.org';

        if (!cameFromLegacyDomain && document.referrer) {
            const referrerUrl = new URL(document.referrer);
            const referrerHost = referrerUrl.hostname.toLowerCase();
            cameFromLegacyDomain = referrerHost === 'fridg3.org'
                || referrerHost === 'www.fridg3.org'
                || referrerHost === 'm.fridg3.org';
        }

        if (!cameFromLegacyDomain) return;

        currentUrl.searchParams.delete('legacy_domain');
        if (marker !== null) {
            window.history.replaceState(window.history.state, document.title, currentUrl.toString());
        }

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

        const renderAsciiRows = (timeStr) => {
            const rows = Array.from({ length: maxLines }, () => '');
            timeStr.split('').forEach((ch) => {
                const glyph = fontMap[ch] || [];
                const width = ch === ':' ? glyphWidthFor(glyph) : glyphWidth;
                const gap = ch === ':' ? 0 : charGap;
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
                el._asciiTimeRender = render;
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

// this script contains a shit ton of functionality for fridge.dev
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
