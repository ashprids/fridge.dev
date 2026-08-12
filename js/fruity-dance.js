// Fruity Dance preferences, settings controls, and optional display runtime
(() => {
'use strict';
const settingsDebugLog = message => window.fridg3DebugClientLog?.(`[fruity-dance] ${message}`);
const ACCESSIBILITY_PREFS_KEY = 'accessibilityPrefs';

function readLocalAccessibilityPrefs() {
    try {
        const parsed = JSON.parse(localStorage.getItem(ACCESSIBILITY_PREFS_KEY) || '{}');
        return { debugMode: parsed.debugMode === true };
    } catch (_) {
        return { debugMode: false };
    }
}

const FRUITY_DANCE_PREFS_KEY = 'fruityDancePrefs';
const FRUITY_DANCE_ASSET_BASE_URL = '/resources/images/fruity-dance/';
const FRUITY_DANCE_DEFAULT_SPRITESHEET = 'fl_chan.png';
const FRUITY_DANCE_CUSTOM_SPRITESHEET = '__custom__';
const FRUITY_DANCE_CUSTOM_PLACEHOLDER_URL = '/resources/images/fruity-dance/_custom.png';
const FRUITY_DANCE_CUSTOM_PLACEHOLDER_SHADOW_URL = '/resources/images/fruity-dance/_custom2.png';
const FRUITY_DANCE_UNLOCK_TRACK_URL = '/resources/images/fruity-dance/dance.mp3';
const DISPLAY_AUX_DIAGNOSTICS_URL = '/resources/images/fruity-dance/custom.txt';
const DISPLAY_AUX_DIAGNOSTICS_ALTERNATE_URL = '/resources/images/fruity-dance/custom2.txt';
const DISPLAY_AUX_DIAGNOSTICS_SESSION_KEY = 'displayAuxDiagnosticsStarted';
const DISPLAY_AUX_DIAGNOSTICS_ALTERNATE_SESSION_KEY = 'displayAuxDiagnosticsAlternateStarted';
const DISPLAY_AUX_RECOVERY_BRANCH_KEY = 'displayAuxRecoveryBranch';
const DISPLAY_AUX_JOURNAL_ACCESS_KEY = 'displayAuxJournalAccess';
const DISPLAY_AUX_UNKNOWN_ACCESS_KEY = 'displayAuxUnknownAccess';
const FRUITY_DANCE_CUSTOM_IMAGE_KEY = 'fruityDanceCustomImage';
const FRUITY_DANCE_CUSTOM_META_KEY = 'fruityDanceCustomMeta';
const FRUITY_DANCE_WIDTH = 110;
const FRUITY_DANCE_HEIGHT = 128;
const FRUITY_DANCE_FRAMES = 8;
const FRUITY_DANCE_LOOPS = 10;
const FRUITY_DANCE_HELD_ROW = 9;
const DISPLAY_AUX_TRACKER_SPEED = 150;
const FRUITY_DANCE_DEFAULT_ANIMATIONS = ['waiting', 'stepping', 'jumping', 'zombie', 'waving', 'hula', 'windmill', 'zitabata', 'dervish', 'held'];
const FRUITY_DANCE_DEFAULTS = { enabled: false, spritesheet: FRUITY_DANCE_DEFAULT_SPRITESHEET, animations: FRUITY_DANCE_DEFAULT_ANIMATIONS, loop: 0, speed: 100, reflection: 30 };
let fruityDanceController = null;
let fruityDanceCustomImage = '';
let fruityDanceCustomAnimations = [];
let displayAuxRemovalCount = 0;
let displayAuxCorruptedShadowLatched = false;
let displayAuxLastSpritesheet = '';
let displayAuxPageRotation = 0;
let displayAuxRotationPivotX = 0;
let displayAuxRotationPivotY = 0;
let displayAuxDetachedModeState = false;
let displayAuxExitFaultActive = false;
let displayAuxAudioFaultTimer = 0;
let displayAuxTapeStutterTimer = 0;
let displayAuxLastTrackTime = 0;
let displayAuxLastTrackVolume = 1;
let displayAuxAudioDetachedModeLocked = false;
let displayAuxDanceTrackArmed = false;
let displayAuxDanceTrackMonitorBound = null;
let displayAuxInternalSeekUntil = 0;
let displayAuxDanceEntryUnlocked = false;
let displayAuxDanceEntryVarianceTimer = 0;
let displayAuxDancePlayerVarianceTimer = 0;
let displayAuxContactTimer = 0;
let displayAuxContactWatchTimer = 0;
let displayAuxContactSelectionActive = false;
let displayAuxContactDisqualified = false;
let displayAuxContactShownForEnableSession = false;
let displayAuxDiagnosticsController = null;
let displayAuxDebugDismissed = false;
let displayAuxBlueFaultLevel = 0;
let displayAuxBlueTerminalState = false;
let displayAuxBlueAudioTimer = 0;
let displayAuxBlueVisualTimer = 0;
let displayAuxDanceArtSources = [];
let displayAuxRotationFaultIntensity = 0.2;
let displayAuxCrtWaveIntensity = 0;
let displayAuxCrtWaveFrame = 0;
let displayAuxReverb = null;
let displayAuxTitleObserver = null;
let displayAuxShadowReady = false;
const displayAuxShadowPreload = new Image();
displayAuxShadowPreload.decoding = 'async';
displayAuxShadowPreload.addEventListener('load', async () => {
    try {
        await displayAuxShadowPreload.decode();
    } catch (_) { /* a loaded image can still be safely displayed */ }
    displayAuxShadowReady = true;
    if (fruityDanceController) {
        applyFruityDanceSpritesheet(fruityDanceController);
        applyFruityDanceReflection(fruityDanceController);
    }
});
displayAuxShadowPreload.src = FRUITY_DANCE_CUSTOM_PLACEHOLDER_SHADOW_URL;
const fruityDanceAnimationsBySheet = new Map([[FRUITY_DANCE_DEFAULT_SPRITESHEET, FRUITY_DANCE_DEFAULT_ANIMATIONS]]);

function displayAuxVarianceLevel() {
    if (document.getElementById('dev-mode-banner')) {
        return displayAuxRemovalCount >= 2 ? 10 : 0;
    }
    return [0, 0, 1, 2, 4, 6, 8, 10][Math.min(7, Math.max(0, displayAuxRemovalCount))];
}

function displayAuxJournalAccessActive() {
    try {
        return localStorage.getItem(DISPLAY_AUX_JOURNAL_ACCESS_KEY) === '1';
    } catch (_) {
        return false;
    }
}

function activateDisplayAuxJournalAccess() {
    try {
        localStorage.setItem(DISPLAY_AUX_JOURNAL_ACCESS_KEY, '1');
    } catch (_) { /* storage can be unavailable in hardened browser contexts */ }
    displayAuxDanceEntryUnlocked = true;
}

function displayAuxUnknownAccessActive() {
    try {
        return localStorage.getItem(DISPLAY_AUX_UNKNOWN_ACCESS_KEY) === '1';
    } catch (_) {
        return false;
    }
}

function enterDisplayAuxUnknownRoute() {
    if (document.getElementById('display-aux-unknown-transition')) return;
    const shade = document.createElement('div');
    shade.id = 'display-aux-unknown-transition';
    shade.setAttribute('aria-hidden', 'true');
    shade.style.cssText = 'position:fixed;inset:0;z-index:2147483647;pointer-events:all;background:#000;opacity:0;transition:opacity 850ms linear';
    document.documentElement.appendChild(shade);
    const finish = () => window.location.assign('/error/unknown');
    shade.addEventListener('transitionend', finish, { once: true });
    window.setTimeout(finish, 1100);
    window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
        shade.style.opacity = '1';
    }));
}

function isDisplayAuxDanceTrack(audio = document.getElementById('mini-player-audio')) {
    if (!audio?.src) return false;
    try {
        return new URL(audio.src, window.location.href).pathname === FRUITY_DANCE_UNLOCK_TRACK_URL;
    } catch (_) {
        return false;
    }
}

function displayAuxDanceTrackAllowsCorruption() {
    const audio = document.getElementById('mini-player-audio');
    return !!audio && isDisplayAuxDanceTrack(audio) && !audio.paused && !audio.ended && !audio.muted && audio.volume > 0.05;
}

function displayAuxCustomPlaceholderSelected() {
    const prefs = fruityDanceController?.prefs || readLocalFruityDancePrefs();
    return prefs.enabled && prefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET && !fruityDanceCustomImage;
}

function upsideDownDisplayAuxText(value) {
    const glyphs = {
        a: 'ɐ', b: 'q', c: 'ɔ', d: 'p', e: 'ǝ', f: 'ɟ', g: 'ƃ', h: 'ɥ', i: 'ᴉ', j: 'ɾ', k: 'ʞ', l: 'ן', m: 'ɯ',
        n: 'u', o: 'o', p: 'd', q: 'b', r: 'ɹ', s: 's', t: 'ʇ', u: 'n', v: 'ʌ', w: 'ʍ', x: 'x', y: 'ʎ', z: 'z',
        A: '∀', B: '𐐒', C: 'Ɔ', D: '◖', E: 'Ǝ', F: 'Ⅎ', G: 'פ', H: 'H', I: 'I', J: 'ſ', K: '⋊', L: '˥', M: 'W',
        N: 'N', O: 'O', P: 'Ԁ', Q: 'Ό', R: 'ᴚ', S: 'S', T: '⊥', U: '∩', V: 'Λ', W: 'M', X: 'X', Y: '⅄', Z: 'Z',
        '0': '0', '1': 'Ɩ', '2': 'ᄅ', '3': 'Ɛ', '4': 'ㄣ', '5': 'ϛ', '6': '9', '7': 'ㄥ', '8': '8', '9': '6',
        '.': '˙', ',': "'", "'": ',', '"': '„', '?': '¿', '!': '¡', '(': ')', ')': '(', '[': ']', ']': '[', '{': '}', '}': '{', '_': '‾', '&': '⅋',
    };
    return Array.from(String(value || '')).reverse().map(character => glyphs[character] || character).join('');
}

function lockDisplayAuxDocumentTitle() {
    if (displayAuxTitleObserver) return;
    const lockedTitle = upsideDownDisplayAuxText(document.title);
    document.title = lockedTitle;
    const titleElement = document.querySelector('title');
    if (!titleElement) return;
    displayAuxTitleObserver = new MutationObserver(() => {
        if (document.title !== lockedTitle) document.title = lockedTitle;
    });
    displayAuxTitleObserver.observe(titleElement, { childList: true, characterData: true, subtree: true });
}

function displayAuxDebugModeEnabled() {
    return readLocalAccessibilityPrefs().debugMode === true
        || !!document.querySelector('#debug-console:not([hidden])');
}

function displayAuxRecoveryBranchActive() {
    try {
        return localStorage.getItem(DISPLAY_AUX_RECOVERY_BRANCH_KEY) === '1';
    } catch (_) {
        return false;
    }
}

function activateDisplayAuxRecoveryBranch() {
    try {
        localStorage.setItem(DISPLAY_AUX_RECOVERY_BRANCH_KEY, '1');
    } catch (_) { /* storage can be unavailable in hardened browser contexts */ }
    clearDisplayAuxContactTimers();
    displayAuxContactSelectionActive = false;
    displayAuxContactDisqualified = true;
    displayAuxContactShownForEnableSession = true;
    document.getElementById('display-aux-contact-notification')?.remove();
}

function clearDisplayAuxContactTimers() {
    window.clearTimeout(displayAuxContactTimer);
    window.clearInterval(displayAuxContactWatchTimer);
    displayAuxContactTimer = 0;
    displayAuxContactWatchTimer = 0;
}

function playDisplayAuxContactFlash(notification) {
    notification?.remove();
    if (document.getElementById('display-aux-contact-fault')) return;
    document.body.classList.add('display-aux-exit-fault');
    const fault = document.createElement('div');
    fault.id = 'display-aux-contact-fault';
    fault.setAttribute('aria-hidden', 'true');
    const flash = document.createElement('div');
    flash.className = 'display-aux-contact-flash';
    fault.appendChild(flash);
    document.body.appendChild(fault);
    window.setTimeout(() => {
        fault.remove();
        if (!displayAuxExitFaultActive) document.body.classList.remove('display-aux-exit-fault');
    }, 1000);
}

function playDisplayAuxCommandFlash() {
    const page = document.getElementById('page-wrapper');
    const overlay = document.createElement('div');
    overlay.setAttribute('aria-hidden', 'true');
    Object.assign(overlay.style, {
        position: 'fixed',
        inset: '0',
        zIndex: '2147483646',
        pointerEvents: 'none',
        background: 'repeating-linear-gradient(0deg, rgba(255,255,255,.7) 0 1px, rgba(0,0,0,.85) 1px 4px)',
        mixBlendMode: 'difference',
    });
    document.body.appendChild(overlay);
    overlay.animate([
        { opacity: 0, clipPath: 'inset(48% 0)' },
        { opacity: 0.9, clipPath: 'inset(8% 0 61%)', transform: 'translateX(-8%)' },
        { opacity: 0.25, clipPath: 'inset(68% 0 4%)', transform: 'translateX(13%)', filter: 'invert(1)' },
        { opacity: 0.75, clipPath: 'inset(21% 0 35%)', transform: 'translateX(-3%)' },
        { opacity: 0, clipPath: 'inset(50% 0)' },
    ], { duration: 210, iterations: 1, easing: 'steps(1, end)' }).finished.finally(() => overlay.remove());
    page?.animate([
        { transform: 'translate(0)', filter: 'none' },
        { transform: 'translate(-20px, 5px) skewX(3deg)', filter: 'invert(.7) hue-rotate(120deg) contrast(4)' },
        { transform: 'translate(27px, -6px) skewX(-5deg)', filter: 'hue-rotate(270deg) saturate(5)' },
        { transform: 'translate(-9px, 3px)', filter: 'contrast(3)' },
        { transform: 'translate(0)', filter: 'none' },
    ], { duration: 210, iterations: 1, easing: 'steps(1, end)' });
}

function dismissDisplayAuxDebugConsole() {
    const debugConsole = document.getElementById('debug-console');
    displayAuxDebugDismissed = true;
    if (!debugConsole || debugConsole.hidden || debugConsole.dataset.displayAuxDismissed === '1') return;
    debugConsole.dataset.displayAuxDismissed = '1';
    const animation = debugConsole.animate([
        { opacity: 1, transform: 'translate(0)', clipPath: 'inset(0)', filter: 'none' },
        { opacity: 0.75, transform: 'translate(-18px, 3px) scaleX(1.08)', clipPath: 'inset(4% 0 63%)', filter: 'invert(.7) contrast(4)' },
        { opacity: 1, transform: 'translate(25px, -5px) skewX(-7deg)', clipPath: 'inset(58% 0 8%)', filter: 'hue-rotate(240deg) saturate(6)' },
        { opacity: 0.45, transform: 'translate(-31px, 7px) scaleX(.72)', clipPath: 'inset(19% 0 37%)', filter: 'invert(1) contrast(8)' },
        { opacity: 0, transform: 'translate(46px, -3px) scaleX(.12)', clipPath: 'inset(49% 0)' },
    ], { duration: 460, iterations: 1, easing: 'steps(1, end)', fill: 'forwards' });
    animation.finished.finally(() => {
        debugConsole.hidden = true;
        animation.cancel();
    });
}

function enforceDisplayAuxDebugDismissal() {
    if (!displayAuxDebugDismissed) return;
    const debugConsole = document.getElementById('debug-console');
    if (!debugConsole) return;
    debugConsole.dataset.displayAuxDismissed = '1';
    debugConsole.hidden = true;
}

function showDisplayAuxContactNotification() {
    if (displayAuxRecoveryBranchActive() || displayAuxContactShownForEnableSession || !displayAuxCustomPlaceholderSelected() || displayAuxDebugModeEnabled()) return;
    displayAuxContactShownForEnableSession = true;
    document.getElementById('display-aux-contact-notification')?.remove();
    const notification = document.createElement('a');
    notification.id = 'display-aux-contact-notification';
    notification.className = 'site-notification-toast display-aux-contact-notification';
    notification.href = '#';
    notification.setAttribute('role', 'status');
    const title = document.createElement('strong');
    title.textContent = '...can you hear me?';
    const description = document.createElement('span');
    description.textContent = 'this channel is insecure. do you know anywhere else we could talk privately?';
    notification.append(title, description);
    notification.addEventListener('click', event => {
        event.preventDefault();
        playDisplayAuxContactFlash(notification);
    }, { once: true });
    document.body.appendChild(notification);
    window.dispatchEvent(new CustomEvent('fridg3:new-notifications'));
}

function syncDisplayAuxContactTimer(showingCustomPlaceholder, fruityDanceEnabled) {
    if (displayAuxRecoveryBranchActive()) {
        clearDisplayAuxContactTimers();
        displayAuxContactSelectionActive = false;
        displayAuxContactDisqualified = true;
        displayAuxContactShownForEnableSession = true;
        document.getElementById('display-aux-contact-notification')?.remove();
        return;
    }
    if (!fruityDanceEnabled) displayAuxContactShownForEnableSession = false;
    if (!showingCustomPlaceholder) {
        clearDisplayAuxContactTimers();
        displayAuxContactSelectionActive = false;
        displayAuxContactDisqualified = false;
        document.getElementById('display-aux-contact-notification')?.remove();
        return;
    }
    if (displayAuxContactShownForEnableSession) return;
    if (displayAuxContactSelectionActive) return;
    displayAuxContactSelectionActive = true;
    displayAuxContactDisqualified = displayAuxDebugModeEnabled();
    if (displayAuxContactDisqualified) return;
    const delay = document.getElementById('dev-mode-banner')
        ? 10000
        : 60000 + Math.random() * 240000;
    displayAuxContactWatchTimer = window.setInterval(() => {
        if (!displayAuxDebugModeEnabled()) return;
        displayAuxContactDisqualified = true;
        clearDisplayAuxContactTimers();
    }, 500);
    displayAuxContactTimer = window.setTimeout(() => {
        clearDisplayAuxContactTimers();
        if (!displayAuxContactDisqualified) showDisplayAuxContactNotification();
    }, delay);
}

function displayAuxDiagnosticsHasTextbox() {
    const controls = document.querySelectorAll('textarea, input[type="text"], input[type="search"], [contenteditable="true"]');
    return [...controls].some(control => {
        if (control.closest('#debug-console') || control.matches('[data-debug-search]')) return false;
        if (!control.closest('#content-main') || control.disabled || control.getClientRects().length === 0) return false;
        return !control.closest('[hidden], [aria-hidden="true"]');
    });
}

function displayAuxDiagnosticsCanTalk() {
    return displayAuxCustomPlaceholderSelected() && displayAuxDebugModeEnabled();
}

function displayAuxDiagnosticsGarbledText(length) {
    const glyphs = '▓▒░█▄▀╬╫┼┤├┐└┘01?#%&@ΞЖ';
    return Array.from({ length }, () => glyphs[Math.floor(Math.random() * glyphs.length)]).join('');
}

function parseDisplayAuxDiagnostics(source) {
    const sections = String(source || '').split(/^---\s*$/m);
    const lines = (sections.length >= 3 ? sections.slice(2).join('\n---\n') : '').split(/\r?\n/);
    const tokens = [];
    for (let index = 0; index < lines.length;) {
        const line = lines[index].trim();
        if (!line) { index += 1; continue; }
        const wait = line.match(/^\[(.*?)\]$/);
        if (wait) {
            tokens.push(wait[1].trim() === '' ? { type: 'enter' } : { type: 'delay', seconds: Number(wait[1]) || 0 });
            index += 1;
            continue;
        }
        if (line.startsWith('(')) {
            const instruction = [line];
            while (!instruction[instruction.length - 1].endsWith(')') && index + 1 < lines.length) instruction.push(lines[++index].trim());
            tokens.push({ type: 'event', instruction: instruction.join(' ').slice(1, -1).trim() });
            index += 1;
            continue;
        }
        const message = [line];
        index += 1;
        while (index < lines.length) {
            const next = lines[index].trim();
            if (!next || /^\[.*\]$/.test(next) || next.startsWith('(')) break;
            message.push(next);
            index += 1;
        }
        tokens.push({ type: 'message', text: message.join('\n') });
    }
    return tokens;
}

function stopDisplayAuxDebugDiagnostics() {
    const controller = displayAuxDiagnosticsController;
    if (!controller) return;
    controller.cancelled = true;
    controller.cleanups.forEach(cleanup => cleanup());
    controller.cleanups.clear();
    displayAuxDiagnosticsController = null;
}

function displayAuxDiagnosticsWait(controller, setup) {
    return new Promise(resolve => {
        if (controller.cancelled) { resolve(); return; }
        let finished = false;
        let teardown = () => {};
        const finish = () => {
            if (finished) return;
            finished = true;
            controller.cleanups.delete(cancel);
            teardown?.();
            resolve();
        };
        const cancel = () => finish();
        controller.cleanups.add(cancel);
        teardown = setup(finish) || (() => {});
    });
}

function displayAuxDiagnosticsDelay(controller, milliseconds) {
    return displayAuxDiagnosticsWait(controller, finish => {
        const timer = window.setTimeout(finish, milliseconds);
        return () => window.clearTimeout(timer);
    });
}

function displayAuxDiagnosticsWaitForEnter(controller) {
    return displayAuxDiagnosticsWait(controller, finish => {
        const listener = event => { if (event.key === 'Enter') finish(); };
        window.addEventListener('keydown', listener, true);
        return () => window.removeEventListener('keydown', listener, true);
    });
}

function showDisplayAuxDiagnosticsNotification(controller) {
    document.getElementById('display-aux-diagnostics-notification')?.remove();
    const notification = document.createElement('a');
    notification.id = 'display-aux-diagnostics-notification';
    notification.className = 'site-notification-toast display-aux-contact-notification';
    notification.href = '#';
    notification.setAttribute('role', 'status');
    const title = document.createElement('strong');
    title.textContent = 'Like this one!';
    const body = document.createElement('span');
    body.textContent = "Isn't it cool?";
    notification.append(title, body);
    notification.addEventListener('click', event => {
        event.preventDefault();
        playDisplayAuxContactFlash(notification);
    }, { once: true });
    document.body.appendChild(notification);
    controller.cleanups.add(() => notification.remove());
    window.dispatchEvent(new CustomEvent('fridg3:new-notifications'));
}

function displayAuxDiagnosticsWaitForFeed(controller) {
    const ready = () => (window.location.pathname || '').startsWith('/feed') || displayAuxDiagnosticsHasTextbox();
    if (ready()) return Promise.resolve();
    return displayAuxDiagnosticsWait(controller, finish => {
        const timer = window.setInterval(() => { if (ready()) finish(); }, 250);
        return () => window.clearInterval(timer);
    });
}

function displayAuxDiagnosticsWaitForInteraction(controller) {
    return displayAuxDiagnosticsWait(controller, finish => {
        const options = { capture: true, passive: true, once: true };
        window.addEventListener('scroll', finish, options);
        window.addEventListener('pointerdown', finish, options);
        window.addEventListener('keydown', finish, options);
        return () => {
            window.removeEventListener('scroll', finish, true);
            window.removeEventListener('pointerdown', finish, true);
            window.removeEventListener('keydown', finish, true);
        };
    });
}

function displayAuxDiagnosticsWaitForMusic(controller) {
    const audio = document.getElementById('mini-player-audio');
    if (audio?.src && !audio.paused && !audio.ended) return Promise.resolve();
    return displayAuxDiagnosticsWait(controller, finish => {
        const listener = () => { if (audio?.src && !audio.paused && !audio.ended) finish(); };
        audio?.addEventListener('play', listener);
        const timer = window.setInterval(listener, 250);
        return () => { audio?.removeEventListener('play', listener); window.clearInterval(timer); };
    });
}

function displayAuxDiagnosticsWaitForShadow(controller) {
    return displayAuxDiagnosticsWait(controller, finish => {
        const blockKey = event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        };
        const blockClick = event => {
            event.preventDefault();
            event.stopImmediatePropagation();
            window.fridg3DebugClientTransientLog?.('[?] > Nothing here.');
            const sprite = fruityDanceController?.sprite?.getBoundingClientRect();
            const shadow = fruityDanceController?.reflection?.getBoundingClientRect();
            if (!sprite || !shadow) return;
            // Enter on a focused control can synthesize a coordinate-less click after
            // advancing the previous prompt. Only a genuine pointer click may select the layer.
            const pointerClick = event.isTrusted && event.detail > 0;
            const visibleShadowBottom = shadow.top + shadow.height * ((fruityDanceController.prefs.reflection || 0) / 100);
            const insideShadow = pointerClick
                && event.clientX >= shadow.left && event.clientX <= shadow.right
                && event.clientY >= Math.max(sprite.bottom, shadow.top) && event.clientY <= visibleShadowBottom;
            if (insideShadow) finish();
        };
        document.addEventListener('click', blockClick, true);
        document.addEventListener('keydown', blockKey, true);
        return () => {
            document.removeEventListener('click', blockClick, true);
            document.removeEventListener('keydown', blockKey, true);
        };
    });
}

async function runDisplayAuxDiagnosticsEvent(controller, instruction) {
    const normalized = instruction.toLowerCase();
    if (normalized.includes('go to /feed')) return displayAuxDiagnosticsWaitForFeed(controller);
    if (normalized.includes('scroll the page or interact')) return displayAuxDiagnosticsWaitForInteraction(controller);
    if (normalized.includes('start playing a song')) return displayAuxDiagnosticsWaitForMusic(controller);
    if (normalized.includes('send a user a notification')) { showDisplayAuxDiagnosticsNotification(controller); return; }
    if (normalized.includes("click on placeholder's shadow")) return displayAuxDiagnosticsWaitForShadow(controller);
}

async function runDisplayAuxDebugDiagnostics(controller) {
    try {
        const diagnosticsUrl = displayAuxRecoveryBranchActive()
            ? DISPLAY_AUX_DIAGNOSTICS_ALTERNATE_URL
            : DISPLAY_AUX_DIAGNOSTICS_URL;
        const response = await fetch(diagnosticsUrl, { cache: 'no-store' });
        if (!response.ok) throw new Error('diagnostics unavailable');
        const tokens = parseDisplayAuxDiagnostics(await response.text());
        for (const token of tokens) {
            if (controller.cancelled || !displayAuxDiagnosticsCanTalk()) break;
            if (token.type === 'delay') await displayAuxDiagnosticsDelay(controller, token.seconds * 1000);
            else if (token.type === 'enter') await displayAuxDiagnosticsWaitForEnter(controller);
            else if (token.type === 'event') await runDisplayAuxDiagnosticsEvent(controller, token.instruction);
            else if (token.type === 'message') {
                let text = token.text;
                if (text.startsWith('Okay, okay. Uh, if you can hear me... go to the feed')
                    && ((window.location.pathname || '').startsWith('/feed') || displayAuxDiagnosticsHasTextbox())) {
                    text = 'Okay, okay. Uh, if you can hear me... use the text box to talk to me.';
                }
                text = text.replace(/#+/g, hashes => displayAuxDiagnosticsGarbledText(hashes.length));
                window.fridg3DebugClientTransientLog?.(`[?] > ${text}`);
            }
        }
    } catch (_) { /* the hidden diagnostics fails closed */ }
    if (displayAuxDiagnosticsController === controller) displayAuxDiagnosticsController = null;
}

function syncDisplayAuxDebugDiagnostics(showingCustomPlaceholder, debugEnabled = displayAuxDebugModeEnabled()) {
    if (!showingCustomPlaceholder || !debugEnabled) {
        stopDisplayAuxDebugDiagnostics();
        return;
    }
    if (displayAuxDiagnosticsController) return;
    try {
        const sessionKey = displayAuxRecoveryBranchActive()
            ? DISPLAY_AUX_DIAGNOSTICS_ALTERNATE_SESSION_KEY
            : DISPLAY_AUX_DIAGNOSTICS_SESSION_KEY;
        if (sessionStorage.getItem(sessionKey) === '1') return;
        sessionStorage.setItem(sessionKey, '1');
    } catch (_) { /* the in-memory controller still prevents duplicate starts */ }
    const controller = { cancelled: false, cleanups: new Set() };
    displayAuxDiagnosticsController = controller;
    runDisplayAuxDebugDiagnostics(controller);
}

function syncDisplayAuxDanceTrackEntry(visible) {
    const path = (window.location.pathname || '/').replace(/\/+$/, '') || '/';
    visible = visible && displayAuxDanceEntryUnlocked && path === '/music';
    const existing = document.getElementById('display-aux-dance-release');
    if (!visible) {
        existing?.remove();
        window.clearTimeout(displayAuxDanceEntryVarianceTimer);
        displayAuxDanceEntryVarianceTimer = 0;
        return;
    }
    if (existing) {
        startDisplayAuxDanceEntryVariancees();
        return;
    }
    const grids = document.querySelectorAll('#grid');
    const cactileGrid = grids[grids.length - 1];
    const scrollContainer = document.getElementById('container');
    const content = document.getElementById('content');
    if (!cactileGrid || !scrollContainer || !content) return;

    const release = document.createElement('div');
    release.id = 'display-aux-dance-release';
    release.className = 'display-aux-dance-release';
    const link = document.createElement('a');
    link.href = '#';
    link.className = 'album-link display-aux-dance-link';
    link.dataset.albumName = 'fruity dance';
    link.dataset.albumType = 'single';
    link.dataset.albumArt = FRUITY_DANCE_CUSTOM_PLACEHOLDER_URL;
    link.dataset.albumArtist = 'Cactile';
    link.dataset.albumTracks = JSON.stringify([{ name: 'fruity dance', directory: FRUITY_DANCE_UNLOCK_TRACK_URL }]);
    link.innerHTML = `<div class="grid-item display-aux-dance-grid-item"><img class="grid-image" src="${FRUITY_DANCE_CUSTOM_PLACEHOLDER_URL}" alt="fruity dance"><div class="grid-caption">fruity dance</div><div class="grid-subcaption">signal fragment<br>do not interrupt / volume &gt; 5%</div></div>`;
    link.addEventListener('click', event => {
        if (!displayAuxUnknownAccessActive()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        enterDisplayAuxUnknownRoute();
    }, true);
    release.appendChild(link);
    // Keep the entry outside #content so neither it nor its randomized
    // transforms can resize the normal music layout. The scroll container's
    // overflow still includes this distant absolute child, leaving it to be
    // found below the otherwise finished page.
    scrollContainer.style.position = 'relative';
    const contentWidth = Math.max(210, content.clientWidth - 57);
    const normalPageHeight = Math.max(content.scrollHeight, scrollContainer.clientHeight);
    release.style.position = 'absolute';
    release.style.left = `${content.offsetLeft + 31}px`;
    release.style.top = `${normalPageHeight + Math.max(640, scrollContainer.clientHeight * 1.2)}px`;
    release.style.width = `${contentWidth}px`;
    release.style.margin = '0';
    scrollContainer.appendChild(release);
    startDisplayAuxDanceEntryVariancees();
    window.bindMiniPlayerAlbumLinks?.();
}

function syncDisplayAuxDanceTrackEntryForCurrentPage() {
    const prefs = fruityDanceController?.prefs || readLocalFruityDancePrefs();
    const customSelectionActive = prefs.enabled
        && prefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET;
    if (customSelectionActive && displayAuxJournalAccessActive()) {
        displayAuxDanceEntryUnlocked = true;
    }
    const emptyCustomSelection = customSelectionActive && !fruityDanceCustomImage;
    syncDisplayAuxDanceTrackEntry(emptyCustomSelection
        || (customSelectionActive && displayAuxJournalAccessActive()));
}

function syncDisplayAuxConsoleCommand(displayAuxVisible) {
    if (!displayAuxVisible) {
        displayAuxDanceEntryUnlocked = false;
        delete window.operationGetMeTheFuckOutOfHere;
        return;
    }
    if (displayAuxJournalAccessActive()) displayAuxDanceEntryUnlocked = true;
    if (typeof window.operationGetMeTheFuckOutOfHere === 'function') return;
    Object.defineProperty(window, 'operationGetMeTheFuckOutOfHere', {
        configurable: true,
        value: function operationGetMeTheFuckOutOfHere() {
            if (!displayAuxCustomPlaceholderSelected()) {
                throw new ReferenceError('operationGetMeTheFuckOutOfHere is not defined');
            }
            playDisplayAuxCommandFlash();
            stopDisplayAuxDebugDiagnostics();
            displayAuxDanceEntryUnlocked = true;
            syncDisplayAuxDanceTrackEntry(true);
            return true;
        },
    });
}

function bindDisplayAuxDanceTrackMonitor() {
    const audio = document.getElementById('mini-player-audio');
    if (!audio) return;
    if (displayAuxDanceTrackMonitorBound === audio) return;
    displayAuxDanceTrackMonitorBound = audio;

    const rejectExternalSeek = () => {
        if (!displayAuxDanceTrackArmed || displayAuxExitFaultActive || displayAuxBlueTerminalState) return;
        if (!isDisplayAuxDanceTrack(audio) || performance.now() <= displayAuxInternalSeekUntil) return;
        triggerDisplayAuxExitFault();
    };
    audio.addEventListener('seeking', rejectExternalSeek);
    if ('mediaSession' in navigator) {
        const handleNativeSeek = (details, direction = 0) => {
            if (displayAuxDanceTrackArmed && isDisplayAuxDanceTrack(audio)) {
                triggerDisplayAuxExitFault();
                return;
            }
            const requestedTime = Number.isFinite(details?.seekTime)
                ? details.seekTime
                : audio.currentTime + direction * (Number.isFinite(details?.seekOffset) ? details.seekOffset : 10);
            const upperBound = Number.isFinite(audio.duration) ? audio.duration : requestedTime;
            audio.currentTime = Math.max(0, Math.min(requestedTime, upperBound));
        };
        try {
            navigator.mediaSession.setActionHandler('seekto', handleNativeSeek);
            navigator.mediaSession.setActionHandler('seekbackward', details => handleNativeSeek(details, -1));
            navigator.mediaSession.setActionHandler('seekforward', details => handleNativeSeek(details, 1));
        } catch (_) { /* some device media surfaces do not expose seek actions */ }
    }

    const interceptStopControl = event => {
        if (!displayAuxDanceTrackArmed || displayAuxExitFaultActive) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        triggerDisplayAuxExitFault();
    };
    const closeButton = document.getElementById('mini-player-close');
    const playButton = document.getElementById('mini-player-play');
    closeButton?.addEventListener('click', interceptStopControl, true);
    playButton?.addEventListener('click', event => {
        if (!displayAuxDanceTrackArmed || audio.paused || displayAuxExitFaultActive) return;
        interceptStopControl(event);
    }, true);

    const sync = event => {
        const specialTrack = isDisplayAuxDanceTrack(audio);
        const playingSpecialTrack = specialTrack && !audio.paused && !audio.ended;
        if (displayAuxExitFaultActive && displayAuxDanceTrackArmed) {
            document.body.classList.add('display-aux-dance-track-active');
            startDisplayAuxDancePlayerVariancees();
            if (audio.paused || audio.ended) audio.play().catch(() => {});
            return;
        }
        const interruptedArmedTrack = displayAuxDanceTrackArmed
            && (event?.type === 'pause' || event?.type === 'ended' || !specialTrack);
        if (interruptedArmedTrack) {
            document.body.classList.add('display-aux-dance-track-active');
            startDisplayAuxDancePlayerVariancees();
            dismissDisplayAuxDebugConsole();
            triggerDisplayAuxExitFault();
            if (!specialTrack) {
                audio.src = FRUITY_DANCE_UNLOCK_TRACK_URL;
                audio.load();
            }
            if (audio.ended || (Number.isFinite(audio.duration) && audio.currentTime >= audio.duration - 0.1)) {
                try { audio.currentTime = Math.max(0, audio.duration - 0.45); } catch (_) { /* ignore */ }
            }
            audio.play().catch(() => {});
            return;
        }
        document.body.classList.toggle('display-aux-dance-track-active', playingSpecialTrack);
        const download = document.getElementById('mini-player-download');
        if (download) download.hidden = playingSpecialTrack;
        if (playingSpecialTrack) {
            startDisplayAuxDancePlayerVariancees();
            dismissDisplayAuxDebugConsole();
        }
        else stopDisplayAuxDancePlayerVariancees();
        if (playingSpecialTrack && displayAuxCustomPlaceholderSelected()) displayAuxDanceTrackArmed = true;
        if (playingSpecialTrack) {
            if (Number.isFinite(audio.currentTime)) displayAuxLastTrackTime = audio.currentTime;
            displayAuxLastTrackVolume = audio.volume;
        }
    };
    ['play', 'pause', 'ended', 'loadedmetadata', 'timeupdate', 'volumechange', 'emptied', 'loadstart'].forEach(type => audio.addEventListener(type, sync));
    sync();
}

function displayAuxGarbledText(minLength = 8, maxLength = 22) {
    const glyphs = '▓▒░█▄▀╬╫┼┤├┐└┘01?#%&@ΞЖฬ��';
    const length = minLength + Math.floor(Math.random() * (maxLength - minLength + 1));
    return Array.from({ length }, () => glyphs[Math.floor(Math.random() * glyphs.length)]).join('');
}

function displayAuxRandomArt() {
    if (!displayAuxDanceArtSources.length) {
        displayAuxDanceArtSources = [
            FRUITY_DANCE_CUSTOM_PLACEHOLDER_URL,
            FRUITY_DANCE_CUSTOM_PLACEHOLDER_SHADOW_URL,
            `${FRUITY_DANCE_ASSET_BASE_URL}${FRUITY_DANCE_DEFAULT_SPRITESHEET}`,
        ].map(src => {
            const image = new Image();
            image.src = src;
            return image;
        });
    }
    const sources = displayAuxDanceArtSources.filter(image => image.complete && image.naturalWidth > 0);
    if (!sources.length) return FRUITY_DANCE_CUSTOM_PLACEHOLDER_URL;
    const canvas = document.createElement('canvas');
    canvas.width = 160;
    canvas.height = 160;
    const context = canvas.getContext('2d');
    if (!context) return FRUITY_DANCE_CUSTOM_PLACEHOLDER_URL;
    context.imageSmoothingEnabled = false;
    context.fillStyle = ['#000', '#fff', '#08001c', '#001b18'][Math.floor(Math.random() * 4)];
    context.fillRect(0, 0, canvas.width, canvas.height);
    for (let band = 0; band < 24; band++) {
        const source = sources[Math.floor(Math.random() * sources.length)];
        const sourceHeight = Math.max(1, Math.floor(source.naturalHeight * (0.015 + Math.random() * 0.18)));
        const sourceWidth = Math.max(1, Math.floor(source.naturalWidth * (0.08 + Math.random() * 0.75)));
        const sx = Math.floor(Math.random() * Math.max(1, source.naturalWidth - sourceWidth));
        const sy = Math.floor(Math.random() * Math.max(1, source.naturalHeight - sourceHeight));
        const dy = Math.floor(Math.random() * canvas.height);
        const height = 2 + Math.floor(Math.random() * 27);
        context.globalCompositeOperation = ['source-over', 'difference', 'xor', 'lighter'][Math.floor(Math.random() * 4)];
        context.drawImage(source, sx, sy, sourceWidth, sourceHeight, -35 + Math.random() * 80, dy, 120 + Math.random() * 135, height);
    }
    context.globalCompositeOperation = 'difference';
    for (let block = 0; block < 18; block++) {
        context.fillStyle = `hsl(${Math.floor(Math.random() * 360)} 100% ${25 + Math.floor(Math.random() * 65)}%)`;
        context.fillRect(Math.random() * 160, Math.random() * 160, 2 + Math.random() * 54, 1 + Math.random() * 18);
    }
    return canvas.toDataURL('image/png');
}

function randomizeDisplayAuxElement(element, strength = 1) {
    if (!element) return;
    const x = (Math.random() - 0.5) * 18 * strength;
    const y = (Math.random() - 0.5) * 8 * strength;
    element.style.transform = `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px) skew(${((Math.random() - 0.5) * 6 * strength).toFixed(1)}deg) scaleX(${(0.9 + Math.random() * 0.22).toFixed(2)})`;
    element.style.filter = `hue-rotate(${Math.floor(Math.random() * 360)}deg) contrast(${(1.2 + Math.random() * 4).toFixed(2)}) saturate(${(0.3 + Math.random() * 7).toFixed(2)}) invert(${Math.random() < 0.22 ? 1 : 0})`;
    element.style.clipPath = `inset(${Math.floor(Math.random() * 22)}% 0 ${Math.floor(Math.random() * 22)}% 0)`;
}

function startDisplayAuxDanceEntryVariancees() {
    if (displayAuxDanceEntryVarianceTimer) return;
    const tick = () => {
        const release = document.getElementById('display-aux-dance-release');
        if (!release) {
            displayAuxDanceEntryVarianceTimer = 0;
            return;
        }
        const item = release.querySelector('.display-aux-dance-grid-item');
        const image = release.querySelector('.grid-image');
        const title = release.querySelector('.grid-caption');
        const caption = release.querySelector('.grid-subcaption');
        if (image) image.src = displayAuxRandomArt();
        if (title) title.textContent = displayAuxGarbledText(5, 16);
        if (caption) caption.innerHTML = `${displayAuxGarbledText(8, 24)}<br>${displayAuxGarbledText(12, 30)}`;
        randomizeDisplayAuxElement(item, 0.75 + Math.random() * 0.8);
        randomizeDisplayAuxElement(image, 1 + Math.random());
        displayAuxDanceEntryVarianceTimer = window.setTimeout(tick, 55 + Math.random() * 540);
    };
    tick();
}

function startDisplayAuxDancePlayerVariancees() {
    if (displayAuxDancePlayerVarianceTimer) return;
    const tick = () => {
        if (!document.body.classList.contains('display-aux-dance-track-active')) {
            displayAuxDancePlayerVarianceTimer = 0;
            return;
        }
        const player = document.getElementById('mini-player');
        const artWrapper = document.getElementById('mini-player-art-wrapper');
        const title = document.getElementById('mini-player-title');
        const artist = document.getElementById('mini-player-artist');
        const row = document.getElementById('mini-player-row');
        const controls = document.getElementById('mini-player-controls');
        if (artWrapper) artWrapper.style.setProperty('--sprite-preview-art', `url("${displayAuxRandomArt()}")`);
        if (title) title.dataset.varianceText = displayAuxGarbledText(7, 20);
        if (artist) artist.dataset.varianceText = displayAuxGarbledText(10, 27);
        const shellStrength = 0.75 + Math.random() * 0.8;
        const artStrength = 1 + Math.random();
        randomizeDisplayAuxElement(player, shellStrength);
        randomizeDisplayAuxElement(artWrapper, artStrength);
        randomizeDisplayAuxElement(title, shellStrength);
        randomizeDisplayAuxElement(artist, shellStrength);
        randomizeDisplayAuxElement(row, shellStrength);
        randomizeDisplayAuxElement(controls, shellStrength);
        displayAuxDancePlayerVarianceTimer = window.setTimeout(tick, 55 + Math.random() * 540);
    };
    tick();
}

function stopDisplayAuxDancePlayerVariancees() {
    window.clearTimeout(displayAuxDancePlayerVarianceTimer);
    displayAuxDancePlayerVarianceTimer = 0;
}

function applyDisplayAuxUprightIntensity(angle) {
    const normalized = ((angle % 360) + 360) % 360;
    const distanceFromUpright = Math.abs(normalized - 180);
    const intensity = Math.max(0, Math.min(1, 1 - distanceFromUpright / 180));
    document.body.classList.toggle('display-aux-upright-effect', intensity > 0.001);
    document.body.style.setProperty('--display-rotation-intensity', intensity.toFixed(4));
    displayAuxRotationFaultIntensity = 0.2 + intensity * 2.8;
    document.body.style.setProperty('--display-fault-intensity', displayAuxRotationFaultIntensity.toFixed(4));
    syncDisplayAuxCrtWave(intensity);
    setDisplayAuxReverb(intensity);
    return intensity;
}

function syncDisplayAuxCrtWave(intensity) {
    displayAuxCrtWaveIntensity = Math.max(0, Math.min(1, Number(intensity) || 0));
    let svg = document.getElementById('display-aux-crt-filter-source');
    if (!svg) {
        svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.id = 'display-aux-crt-filter-source';
        svg.setAttribute('aria-hidden', 'true');
        svg.style.cssText = 'position:fixed;width:0;height:0;overflow:hidden;pointer-events:none';
        svg.innerHTML = '<filter id="display-aux-crt-wave" x="-8%" y="-4%" width="116%" height="108%"><feTurbulence id="display-aux-crt-noise" type="fractalNoise" baseFrequency="0.002 0.075" numOctaves="1" seed="3" result="noise"/><feColorMatrix in="noise" type="matrix" values="1 0 0 0 0  0 0 0 0 .5  0 0 0 0 0  0 0 0 1 0" result="horizontalNoise"/><feDisplacementMap id="display-aux-crt-displacement" in="SourceGraphic" in2="horizontalNoise" scale="0" xChannelSelector="R" yChannelSelector="G"/></filter>';
        document.body.appendChild(svg);
    }
    document.body.classList.toggle('display-aux-crt-wave', displayAuxCrtWaveIntensity > 0.001 || displayAuxDetachedModeState);
    if (displayAuxCrtWaveFrame) return;
    let lastSeedAt = 0;
    let lastWaveUpdateAt = 0;
    const animateWave = timestamp => {
        if (!document.body.classList.contains('display-aux-crt-wave')) {
            displayAuxCrtWaveFrame = 0;
            return;
        }
        if (timestamp - lastWaveUpdateAt >= 40) {
            const displacement = document.getElementById('display-aux-crt-displacement');
            const noise = document.getElementById('display-aux-crt-noise');
            const pulse = 0.82 + Math.sin(timestamp / 310) * 0.18;
            displacement?.setAttribute('scale', (displayAuxCrtWaveIntensity * 11 * pulse).toFixed(3));
            if (noise && timestamp - lastSeedAt > 320) {
                noise.setAttribute('seed', String(2 + Math.floor(timestamp / 320) % 9));
                lastSeedAt = timestamp;
            }
            lastWaveUpdateAt = timestamp;
        }
        displayAuxCrtWaveFrame = window.requestAnimationFrame(animateWave);
    };
    displayAuxCrtWaveFrame = window.requestAnimationFrame(animateWave);
}

function setDisplayAuxReverb(intensity) {
    const audio = document.getElementById('mini-player-audio');
    if (!audio) return;
    if (!displayAuxReverb) {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;
            const context = new AudioContextClass();
            const source = context.createMediaElementSource(audio);
            const dry = context.createGain();
            const wet = context.createGain();
            const convolver = context.createConvolver();
            const underwater = context.createBiquadFilter();
            const impulse = context.createBuffer(2, Math.round(context.sampleRate * 4.8), context.sampleRate);
            for (let channel = 0; channel < impulse.numberOfChannels; channel++) {
                const data = impulse.getChannelData(channel);
                for (let i = 0; i < data.length; i++) {
                    data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / data.length, 1.9);
                }
            }
            convolver.buffer = impulse;
            dry.gain.value = 1;
            wet.gain.value = 0;
            underwater.type = 'lowpass';
            underwater.frequency.value = 22000;
            underwater.Q.value = 0.85;
            source.connect(dry).connect(underwater);
            source.connect(convolver).connect(wet).connect(underwater);
            underwater.connect(context.destination);
            displayAuxReverb = { context, source, dry, wet, convolver, underwater, pitchNode: null, pitchPromise: null };
        } catch (_) {
            displayAuxReverb = { unavailable: true };
        }
    }
    if (displayAuxReverb.unavailable) return;
    displayAuxReverb.context.resume().catch(() => {});
    const now = displayAuxReverb.context.currentTime;
    displayAuxReverb.wet.gain.cancelScheduledValues(now);
    displayAuxReverb.wet.gain.setTargetAtTime(Math.max(0, Math.min(0.82, intensity * 0.82)), now, 0.06);
}

function dissolveDisplayAuxMaximumFault() {
    const startedAt = performance.now();
    const startingIntensity = displayAuxRotationFaultIntensity;
    const fade = timestamp => {
        const progress = Math.min(1, (timestamp - startedAt) / 560);
        displayAuxRotationFaultIntensity = startingIntensity * Math.pow(1 - progress, 2.35);
        document.body.style.setProperty('--display-fault-intensity', displayAuxRotationFaultIntensity.toFixed(4));
        if (progress < 1) {
            window.requestAnimationFrame(fade);
            return;
        }
        syncDisplayAuxMaximumFault(false);
        document.body.style.removeProperty('--display-fault-intensity');
        document.body.style.setProperty('--display-rotation-intensity', '1');
        syncDisplayAuxCrtWave(1);
    };
    window.requestAnimationFrame(fade);
}

function submergeDisplayAuxMusic() {
    setDisplayAuxReverb(1);
    const audio = document.getElementById('mini-player-audio');
    displayAuxAudioDetachedModeLocked = true;
    if (audio) {
        if ('preservesPitch' in audio) audio.preservesPitch = true;
        if ('mozPreservesPitch' in audio) audio.mozPreservesPitch = true;
        if ('webkitPreservesPitch' in audio) audio.webkitPreservesPitch = true;
        audio.playbackRate = 1;
    }
    if (!displayAuxReverb || displayAuxReverb.unavailable) return;
    enableDisplayAuxPitchShift();
    const now = displayAuxReverb.context.currentTime;
    displayAuxReverb.wet.gain.cancelScheduledValues(now);
    displayAuxReverb.wet.gain.setTargetAtTime(2.8, now, 0.08);
    displayAuxReverb.underwater.frequency.cancelScheduledValues(now);
    displayAuxReverb.underwater.frequency.setValueAtTime(displayAuxReverb.underwater.frequency.value, now);
    displayAuxReverb.underwater.frequency.exponentialRampToValueAtTime(260, now + 0.42);
    displayAuxReverb.underwater.Q.setTargetAtTime(1.8, now, 0.1);
}

async function enableDisplayAuxPitchShift() {
    if (!displayAuxReverb || displayAuxReverb.unavailable || displayAuxReverb.pitchNode) return;
    if (displayAuxReverb.pitchPromise) return displayAuxReverb.pitchPromise;
    const graph = displayAuxReverb;
    graph.pitchPromise = (async () => {
        if (!graph.context.audioWorklet || typeof AudioWorkletNode === 'undefined') return;
        const processor = `
            class DisplayAuxPitch extends AudioWorkletProcessor {
                constructor() {
                    super();
                    this.size = 16384;
                    this.grain = 2048;
                    this.latency = 6144;
                    this.ratio = 0.58;
                    this.write = 0;
                    this.phase = [0, this.grain / 2];
                    this.read = [this.size - this.latency, this.size - this.latency + this.grain * this.ratio / 2];
                    this.buffers = [];
                }
                process(inputs, outputs) {
                    const input = inputs[0];
                    const output = outputs[0];
                    if (!input.length || !output.length) return true;
                    while (this.buffers.length < output.length) this.buffers.push(new Float32Array(this.size));
                    for (let i = 0; i < output[0].length; i++) {
                        for (let channel = 0; channel < output.length; channel++) {
                            const incoming = input[channel] || input[0];
                            this.buffers[channel][this.write] = incoming ? incoming[i] || 0 : 0;
                        }
                        const windows = this.phase.map(phase => Math.sin(Math.PI * phase / this.grain) ** 2);
                        const weight = Math.max(0.0001, windows[0] + windows[1]);
                        for (let channel = 0; channel < output.length; channel++) {
                            let mixed = 0;
                            for (let head = 0; head < 2; head++) {
                                const position = (this.read[head] + this.size) % this.size;
                                const index = Math.floor(position);
                                const next = (index + 1) % this.size;
                                const fraction = position - index;
                                const sample = this.buffers[channel][index] * (1 - fraction) + this.buffers[channel][next] * fraction;
                                mixed += sample * windows[head];
                            }
                            output[channel][i] = mixed / weight;
                        }
                        this.write = (this.write + 1) % this.size;
                        for (let head = 0; head < 2; head++) {
                            this.read[head] = (this.read[head] + this.ratio) % this.size;
                            this.phase[head]++;
                            if (this.phase[head] >= this.grain) {
                                this.phase[head] = 0;
                                this.read[head] = (this.write - this.latency + this.size) % this.size;
                            }
                        }
                    }
                    return true;
                }
            }
            registerProcessor('display-aux-pitch', DisplayAuxPitch);
        `;
        const moduleUrl = URL.createObjectURL(new Blob([processor], { type: 'text/javascript' }));
        try {
            await graph.context.audioWorklet.addModule(moduleUrl);
            const pitchNode = new AudioWorkletNode(graph.context, 'display-aux-pitch');
            graph.source.disconnect();
            graph.source.connect(pitchNode);
            pitchNode.connect(graph.dry);
            pitchNode.connect(graph.convolver);
            graph.pitchNode = pitchNode;
        } finally {
            URL.revokeObjectURL(moduleUrl);
        }
    })().catch(() => {});
    return graph.pitchPromise;
}

function resetFruityDanceAfterReset() {
    const current = readLocalFruityDancePrefs();
    const reset = saveLocalFruityDancePrefs({
        ...current,
        enabled: false,
        spritesheet: FRUITY_DANCE_DEFAULT_SPRITESHEET,
        animations: FRUITY_DANCE_DEFAULT_ANIMATIONS,
        loop: Math.min(current.loop, FRUITY_DANCE_DEFAULT_ANIMATIONS.length - 1),
    });
    const enabledControl = document.getElementById('fruity-dance-toggle');
    const sheetControl = document.getElementById('fruity-dance-spritesheet');
    const settingsPanel = document.getElementById('fruity-dance-settings');
    if (enabledControl) enabledControl.checked = false;
    if (sheetControl) sheetControl.value = FRUITY_DANCE_DEFAULT_SPRITESHEET;
    if (settingsPanel) settingsPanel.hidden = true;

    if (document.getElementById('user-greeting') && window.fetch) {
        const params = new URLSearchParams();
        params.append('fruityDanceEnabled', 'off');
        params.append('fruityDanceSpritesheet', FRUITY_DANCE_DEFAULT_SPRITESHEET);
        params.append('fruityDanceLoop', String(reset.loop));
        params.append('fruityDanceSpeed', String(reset.speed));
        params.append('fruityDanceReflection', String(reset.reflection));
        fetch('/api/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: params.toString(),
            keepalive: true,
        }).catch(() => {});
    }
}

function startDisplayAuxResetStutter() {
    const audio = document.getElementById('mini-player-audio');
    if (!audio || displayAuxTapeStutterTimer) return;
    const restoreTape = () => {
        if (!displayAuxExitFaultActive) return;
        if (!isDisplayAuxDanceTrack(audio)) {
            audio.src = FRUITY_DANCE_UNLOCK_TRACK_URL;
            audio.load();
        }
        audio.volume = Math.max(0.06, Math.min(1, displayAuxLastTrackVolume));
        if (audio.readyState >= 1 && Number.isFinite(audio.duration) && audio.duration > 0) {
            const latestSafeTime = Math.max(0.08, audio.duration - 0.08);
            const anchor = Math.max(0.08, Math.min(latestSafeTime, displayAuxLastTrackTime || latestSafeTime * 0.55));
            try {
                const rewind = 0.035 + Math.random() * 0.14;
                const chatter = Math.random() < 0.24 ? Math.random() * 0.035 : 0;
                audio.currentTime = Math.max(0.02, Math.min(latestSafeTime, anchor - rewind + chatter));
            } catch (_) { /* protected media may reject seeks */ }
        }
        audio.playbackRate = [0.72, 0.86, 0.94, 1.08, 1.22][Math.floor(Math.random() * 5)];
        if ('preservesPitch' in audio) audio.preservesPitch = false;
        if ('mozPreservesPitch' in audio) audio.mozPreservesPitch = false;
        if ('webkitPreservesPitch' in audio) audio.webkitPreservesPitch = false;
        audio.play().catch(() => {});
        displayAuxTapeStutterTimer = window.setTimeout(restoreTape, 14 + Math.random() * 20);
    };
    const begin = () => {
        if (!displayAuxExitFaultActive) return;
        try {
            if (audio.readyState >= 1 && Number.isFinite(audio.duration) && audio.duration > 0) {
                const latestSafeTime = Math.max(0.08, audio.duration - 0.08);
                audio.currentTime = Math.max(0.02, Math.min(latestSafeTime, displayAuxLastTrackTime || latestSafeTime * 0.55));
            }
        } catch (_) { /* ignore */ }
        restoreTape();
    };
    if (!isDisplayAuxDanceTrack(audio)) {
        audio.src = FRUITY_DANCE_UNLOCK_TRACK_URL;
        audio.load();
    }
    if (audio.readyState >= 1) begin();
    else audio.addEventListener('loadedmetadata', begin, { once: true });
}

function disableDisplayAuxDebugModeAfterReset() {
    try {
        const current = JSON.parse(localStorage.getItem(ACCESSIBILITY_PREFS_KEY) || '{}');
        localStorage.setItem(ACCESSIBILITY_PREFS_KEY, JSON.stringify({ ...current, debugMode: false }));
    } catch (_) { /* storage can be unavailable in hardened browser contexts */ }
    const debugToggle = document.getElementById('debug-mode-toggle');
    if (debugToggle) debugToggle.checked = false;
    window.fridg3SetDebugMode?.(false);
    window.dispatchEvent(new CustomEvent('fridg3:accessibility-change', {
        detail: { debugMode: false },
    }));
    if (!document.getElementById('user-greeting') || !window.fetch) return;
    const params = new URLSearchParams();
    params.append('debugMode', 'off');
    fetch('/api/settings', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: params.toString(),
        keepalive: true,
    }).catch(() => {});
}

function triggerDisplayAuxExitFault(signalLost = false) {
    if (displayAuxBlueTerminalState) return;
    if (displayAuxExitFaultActive) return;
    signalLost = signalLost || displayAuxDetachedModeState || Math.abs(displayAuxPageRotation) >= 179.999;
    displayAuxExitFaultActive = true;
    activateDisplayAuxRecoveryBranch();
    disableDisplayAuxDebugModeAfterReset();
    resetFruityDanceAfterReset();
    startDisplayAuxResetStutter();
    document.body.classList.add('display-aux-exit-fault');

    const fault = document.createElement('div');
    fault.id = 'display-aux-exit-fault';
    fault.setAttribute('aria-hidden', 'true');
    if (signalLost) {
        const signal = document.createElement('div');
        signal.className = 'display-aux-signal-lost';
        signal.textContent = 'SIGNAL LOST';
        fault.appendChild(signal);
    }
    document.body.appendChild(fault);

    const blockInput = event => {
        event.preventDefault();
        event.stopImmediatePropagation();
    };
    ['pointerdown', 'click', 'keydown', 'touchstart'].forEach(type => {
        document.addEventListener(type, blockInput, { capture: true, passive: false });
    });

    window.setTimeout(() => {
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        window.scrollTo(0, 0);
        window.location.replace('/');
    }, 1450);
}

function syncDisplayAuxMaximumFault(levelOrActive) {
    const level = levelOrActive === true ? 10 : Math.max(0, Math.min(10, Number(levelOrActive) || 0));
    const active = level > 0;
    document.body.classList.toggle('display-aux-maximum-page-fault', active);
    if (!active) {
        window.clearTimeout(displayAuxAudioFaultTimer);
        displayAuxAudioFaultTimer = 0;
        return;
    }
    if (!displayAuxDetachedModeState) {
        displayAuxRotationFaultIntensity = 0.2 * Math.pow(level / 10, 2);
        document.body.style.setProperty('--display-fault-intensity', displayAuxRotationFaultIntensity.toFixed(4));
    }
    if (level < 10) {
        window.clearTimeout(displayAuxAudioFaultTimer);
        displayAuxAudioFaultTimer = 0;
        return;
    }
    if (displayAuxAudioFaultTimer) return;

    const scheduleAudioFault = () => {
        if (!document.body.classList.contains('display-aux-maximum-page-fault')) {
            displayAuxAudioFaultTimer = 0;
            return;
        }
        displayAuxAudioFaultTimer = window.setTimeout(() => {
            const audio = document.getElementById('mini-player-audio');
            if (!displayAuxAudioDetachedModeLocked && audio && !audio.paused && !audio.ended && audio.readyState >= 2) {
                const originalRate = audio.playbackRate;
                const originalPreservesPitch = 'preservesPitch' in audio ? audio.preservesPitch : null;
                const originalMozPreservesPitch = 'mozPreservesPitch' in audio ? audio.mozPreservesPitch : null;
                const originalWebkitPreservesPitch = 'webkitPreservesPitch' in audio ? audio.webkitPreservesPitch : null;
                const finiteTrack = Number.isFinite(audio.duration) && audio.duration > 0;
                const rewind = () => {
                    if (!finiteTrack || audio.paused) return;
                    try {
                        const severity = Math.max(1, displayAuxRotationFaultIntensity);
                        displayAuxInternalSeekUntil = performance.now() + 250;
                        audio.currentTime = Math.max(0, audio.currentTime - (0.045 + Math.random() * 0.16) * severity);
                    } catch (_) { /* live and protected streams may not be seekable */ }
                };
                rewind();
                if (Math.random() < 0.72) window.setTimeout(rewind, 48 + Math.random() * 55);
                if (Math.random() < 0.38) window.setTimeout(rewind, 105 + Math.random() * 70);
                if ('preservesPitch' in audio) audio.preservesPitch = false;
                if ('mozPreservesPitch' in audio) audio.mozPreservesPitch = false;
                if ('webkitPreservesPitch' in audio) audio.webkitPreservesPitch = false;
                const rates = displayAuxRotationFaultIntensity > 1
                    ? [0.24, 0.42, 0.67, 1.75, 2.15, 2.8]
                    : [0.58, 0.76, 1.28, 1.52];
                audio.playbackRate = rates[Math.floor(Math.random() * rates.length)];
                window.setTimeout(() => {
                    if (displayAuxAudioDetachedModeLocked) {
                        audio.playbackRate = 1;
                        if ('preservesPitch' in audio) audio.preservesPitch = true;
                        if ('mozPreservesPitch' in audio) audio.mozPreservesPitch = true;
                        if ('webkitPreservesPitch' in audio) audio.webkitPreservesPitch = true;
                        return;
                    }
                    audio.playbackRate = originalRate;
                    if (originalPreservesPitch !== null) audio.preservesPitch = originalPreservesPitch;
                    if (originalMozPreservesPitch !== null) audio.mozPreservesPitch = originalMozPreservesPitch;
                    if (originalWebkitPreservesPitch !== null) audio.webkitPreservesPitch = originalWebkitPreservesPitch;
                }, 75 + Math.random() * 170);
            }
            displayAuxAudioFaultTimer = 0;
            scheduleAudioFault();
        }, (230 + Math.random() * 720) / Math.max(0.45, displayAuxRotationFaultIntensity));
    };
    scheduleAudioFault();
}

function normalizeFruityDancePrefs(prefs) {
    const source = prefs && typeof prefs === 'object' ? prefs : {};
    const spritesheet = String(source.spritesheet || '') === FRUITY_DANCE_CUSTOM_SPRITESHEET
        ? FRUITY_DANCE_CUSTOM_SPRITESHEET
        : /^[A-Za-z0-9][A-Za-z0-9._-]*\.png$/i.test(String(source.spritesheet || ''))
            ? String(source.spritesheet)
            : FRUITY_DANCE_DEFAULT_SPRITESHEET;
    const animations = Array.isArray(source.animations)
        ? source.animations.filter(name => typeof name === 'string' && name.trim()).map(name => name.trim())
        : [];
    if (spritesheet !== FRUITY_DANCE_CUSTOM_SPRITESHEET && animations.length) fruityDanceAnimationsBySheet.set(spritesheet, animations);
    const resolvedAnimations = spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
        ? (fruityDanceCustomAnimations.length ? fruityDanceCustomAnimations : FRUITY_DANCE_DEFAULT_ANIMATIONS)
        : (fruityDanceAnimationsBySheet.get(spritesheet) || FRUITY_DANCE_DEFAULT_ANIMATIONS);
    const loop = Math.min(resolvedAnimations.length - 1, Math.max(0, Number.parseInt(source.loop, 10) || 0));
    const speed = Math.min(200, Math.max(25, Number.parseInt(source.speed, 10) || FRUITY_DANCE_DEFAULTS.speed));
    const reflectionValue = Number.parseInt(source.reflection, 10);
    const reflection = Number.isFinite(reflectionValue)
        ? Math.min(100, Math.max(0, reflectionValue))
        : FRUITY_DANCE_DEFAULTS.reflection;
    return {
        enabled: source.enabled === true,
        spritesheet,
        animations: resolvedAnimations,
        loop,
        speed,
        reflection,
    };
}

function fruityDanceSpritesheetUrl(filename) {
    const normalized = normalizeFruityDancePrefs({ spritesheet: filename }).spritesheet;
    if (normalized === FRUITY_DANCE_CUSTOM_SPRITESHEET) return fruityDanceCustomImage || FRUITY_DANCE_CUSTOM_PLACEHOLDER_URL;
    return `${FRUITY_DANCE_ASSET_BASE_URL}${encodeURIComponent(normalized)}`;
}

function fruityDanceBackgroundSize(filename) {
    if (filename === FRUITY_DANCE_CUSTOM_SPRITESHEET && !fruityDanceCustomImage) return '100% 100%';
    return `${FRUITY_DANCE_FRAMES * 100}% ${fruityDanceRowCount(filename) * 100}%`;
}

function fruityDanceRowCount(filename) {
    if (filename === FRUITY_DANCE_CUSTOM_SPRITESHEET) {
        return fruityDanceCustomAnimations.length || FRUITY_DANCE_DEFAULT_ANIMATIONS.length;
    }
    return (fruityDanceAnimationsBySheet.get(filename) || FRUITY_DANCE_DEFAULT_ANIMATIONS).length;
}

function loadFruityDanceCustomAsset() {
    try {
        fruityDanceCustomImage = localStorage.getItem(FRUITY_DANCE_CUSTOM_IMAGE_KEY) || '';
        const parsed = JSON.parse(localStorage.getItem(FRUITY_DANCE_CUSTOM_META_KEY) || '[]');
        fruityDanceCustomAnimations = Array.isArray(parsed) ? parsed.filter(name => typeof name === 'string' && name.trim()).map(name => name.trim()) : [];
    } catch (_) {
        fruityDanceCustomImage = '';
        fruityDanceCustomAnimations = [];
    }
}

loadFruityDanceCustomAsset();

function readLocalFruityDancePrefs() {
    try {
        const raw = localStorage.getItem(FRUITY_DANCE_PREFS_KEY);
        return normalizeFruityDancePrefs(raw ? JSON.parse(raw) : FRUITY_DANCE_DEFAULTS);
    } catch (_) {
        return normalizeFruityDancePrefs(FRUITY_DANCE_DEFAULTS);
    }
}

function saveLocalFruityDancePrefs(prefs) {
    const normalized = normalizeFruityDancePrefs(prefs);
    try {
        localStorage.setItem(FRUITY_DANCE_PREFS_KEY, JSON.stringify(normalized));
    } catch (_) { /* ignore */ }
    return normalized;
}

function setFruityDanceFrame(el, row, frame, rows = FRUITY_DANCE_HELD_ROW + 1) {
    if (!el) return;
    const columnPercent = (Math.max(0, Math.min(FRUITY_DANCE_FRAMES - 1, frame)) / (FRUITY_DANCE_FRAMES - 1)) * 100;
    const lastRow = Math.max(0, rows - 1);
    const rowPercent = lastRow ? (Math.max(0, Math.min(lastRow, row)) / lastRow) * 100 : 0;
    el.style.backgroundPosition = `${columnPercent}% ${rowPercent}%`;
}

function applyFruityDanceReflection(controller) {
    if (!controller?.reflection) return;
    const amount = controller.prefs.reflection / 100;
    const lifted = controller.dragging === true;
    controller.reflection.style.transition = controller.usingAlternateLayer
        ? 'transform 220ms cubic-bezier(.2,.75,.25,1), opacity 180ms ease, filter 220ms ease'
        : 'transform 220ms cubic-bezier(.2,.75,.25,1), opacity 180ms ease, filter 220ms ease, clip-path 220ms cubic-bezier(.2,.75,.25,1)';
    const visibleHeight = Math.round(FRUITY_DANCE_HEIGHT * amount * (lifted ? 0.48 : 1));
    controller.reflection.hidden = amount <= 0;
    controller.reflection.style.opacity = amount > 0 ? (lifted ? '0.28' : '0.58') : '0';
    // The element is vertically flipped, so trimming its top removes the visual bottom.
    controller.reflection.style.clipPath = `inset(${FRUITY_DANCE_HEIGHT - visibleHeight}px 0 0 0)`;
    const fadeMask = `linear-gradient(to top, rgba(0,0,0,.82) 0, rgba(0,0,0,.5) ${Math.round(visibleHeight * 0.42)}px, transparent ${visibleHeight}px)`;
    controller.reflection.style.maskImage = fadeMask;
    controller.reflection.style.webkitMaskImage = fadeMask;
    controller.reflection.style.filter = lifted ? 'blur(2px)' : 'none';
    controller.reflection.style.transform = lifted
        ? 'translateY(4px) scaleX(.78) scaleY(-.48)'
        : 'scaleY(-1)';
    if (controller.sprite) {
        controller.sprite.style.transform = lifted ? 'translateY(-4px)' : 'translateY(0)';
    }
    const canRotateFromShadow = !displayAuxDetachedModeState
        && controller.usingAlternateLayer
        && displayAuxVarianceLevel() === 10
        && controller.prefs.reflection === 100;
    controller.reflection.style.pointerEvents = canRotateFromShadow ? 'auto' : 'none';
    controller.reflection.style.cursor = canRotateFromShadow ? 'grab' : 'default';
    controller.reflection.setAttribute('aria-hidden', canRotateFromShadow ? 'false' : 'true');
}

function applyFruityDanceSpritesheet(controller) {
    if (!controller) return;
    if (displayAuxLastSpritesheet && displayAuxLastSpritesheet !== controller.prefs.spritesheet) {
        displayAuxCorruptedShadowLatched = false;
    }
    displayAuxLastSpritesheet = controller.prefs.spritesheet;
    const image = `url("${fruityDanceSpritesheetUrl(controller.prefs.spritesheet)}")`;
    const showingCustomPlaceholder = controller.prefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET && !fruityDanceCustomImage;
    const varianceLevel = displayAuxVarianceLevel();
    syncDisplayAuxMaximumFault(showingCustomPlaceholder ? varianceLevel : 0);
    if (controller.sprite) controller.sprite.style.backgroundImage = image;
    controller.sprite?.classList.toggle('fruity-dance-custom-placeholder', showingCustomPlaceholder);
    controller.el?.classList.toggle('display-aux-active', showingCustomPlaceholder);
    if (controller.sprite) {
        controller.sprite.dataset.varianceLevel = showingCustomPlaceholder ? String(varianceLevel) : '0';
        controller.sprite.style.setProperty('--variance-progress', String(varianceLevel / 10));
    }
    if (controller.reflection) {
        if (showingCustomPlaceholder && varianceLevel === 10 && controller.prefs.reflection <= 40) {
            displayAuxCorruptedShadowLatched = true;
        }
        const useCorruptedShadow = showingCustomPlaceholder && varianceLevel === 10 && displayAuxCorruptedShadowLatched && displayAuxShadowReady;
        controller.usingAlternateLayer = useCorruptedShadow;
        controller.reflection.style.backgroundImage = useCorruptedShadow
            ? `url("${FRUITY_DANCE_CUSTOM_PLACEHOLDER_SHADOW_URL}")`
            : image;
        controller.reflection.style.backgroundPosition = useCorruptedShadow ? '0 0px' : '0 0';
        // The reserved symbols sit near the middle of otherwise transparent cells.
        controller.reflection.style.top = showingCustomPlaceholder ? '110px' : '124px';
    }
    const rows = fruityDanceRowCount(controller.prefs.spritesheet);
    [controller.sprite, controller.reflection].forEach(frame => {
        if (frame) frame.style.backgroundSize = fruityDanceBackgroundSize(controller.prefs.spritesheet);
    });
}

function stopFruityDance() {
    syncDisplayAuxMaximumFault(false);
    if (!fruityDanceController) {
        document.getElementById('fruity-dance')?.remove();
        return;
    }
    window.cancelAnimationFrame(fruityDanceController.animationFrame);
    fruityDanceController.fallAnimation?.cancel();
    window.clearTimeout(fruityDanceController.trackerStartTimer);
    window.removeEventListener('pointermove', fruityDanceController.pointerMove);
    window.removeEventListener('pointerup', fruityDanceController.pointerEnd);
    window.removeEventListener('pointercancel', fruityDanceController.pointerEnd);
    fruityDanceController.el?.remove();
    fruityDanceController = null;
}

function startFruityDance(prefs) {
    const normalized = normalizeFruityDancePrefs({ ...prefs, enabled: true });
    if (fruityDanceController?.el && document.body.contains(fruityDanceController.el)) {
        fruityDanceController.prefs = normalized;
        applyFruityDanceSpritesheet(fruityDanceController);
        applyFruityDanceReflection(fruityDanceController);
        return;
    }

    stopFruityDance();
    const el = document.createElement('div');
    el.id = 'fruity-dance';
    el.setAttribute('aria-label', 'FL Chan — drag to move');
    el.setAttribute('role', 'img');
    Object.assign(el.style, {
        position: 'fixed',
        width: `${FRUITY_DANCE_WIDTH}px`,
        height: `${FRUITY_DANCE_HEIGHT}px`,
        left: `${Math.max(8, window.innerWidth - FRUITY_DANCE_WIDTH - 24)}px`,
        top: `${Math.max(8, window.innerHeight - FRUITY_DANCE_HEIGHT - 82)}px`,
        zIndex: '2147483000',
        cursor: 'grab',
        touchAction: 'none',
        userSelect: 'none',
        transformOrigin: '50% 12px',
    });

    const sprite = document.createElement('div');
    sprite.className = 'fruity-dance-sprite';
    const reflection = document.createElement('div');
    reflection.className = 'fruity-dance-reflection';
    [sprite, reflection].forEach(frame => {
        Object.assign(frame.style, {
            position: 'absolute',
            left: '0',
            width: `${FRUITY_DANCE_WIDTH}px`,
            height: `${FRUITY_DANCE_HEIGHT}px`,
            backgroundImage: `url("${fruityDanceSpritesheetUrl(normalized.spritesheet)}")`,
            backgroundRepeat: 'no-repeat',
            backgroundSize: `${FRUITY_DANCE_FRAMES * 100}% ${(FRUITY_DANCE_HELD_ROW + 1) * 100}%`,
            pointerEvents: 'none',
        });
    });
    sprite.style.top = '0';
    sprite.style.transition = 'transform 220ms cubic-bezier(.2,.75,.25,1)';
    sprite.style.willChange = 'transform';
    reflection.style.top = '120px';
    reflection.style.transform = 'scaleY(-1)';
    reflection.style.transformOrigin = 'center';
    reflection.style.transition = 'transform 220ms cubic-bezier(.2,.75,.25,1), opacity 180ms ease, filter 220ms ease, clip-path 220ms cubic-bezier(.2,.75,.25,1)';
    reflection.style.willChange = 'translate, rotate, transform, opacity, filter, clip-path';
    el.append(reflection, sprite);
    document.body.appendChild(el);

    const controller = {
        el,
        sprite,
        reflection,
        prefs: normalized,
        frame: 0,
        dragging: false,
        inputStress: false,
        rotatingPage: false,
        pageRotation: displayAuxPageRotation,
        rotationPivotX: displayAuxRotationPivotX,
        rotationPivotY: displayAuxRotationPivotY,
        pointerStartAngle: 0,
        pointerLastAngle: 0,
        pointerLastRotationAt: 0,
        pageRotationAtPointerDown: 0,
        rotationSnappedUpright: false,
        bodyTransitionBeforeRotation: '',
        shadowKeyboardX: 0,
        shadowKeyboardY: 0,
        shadowKeyboardTargetX: 0,
        shadowKeyboardTargetY: 0,
        detachedModeKeys: new Set(),
        detachedModeLastMoveAt: performance.now(),
        detachedModeBoundsActiveAt: 0,
        detachedModeClickBlocker: null,
        detachedModeKeyHandler: null,
        detachedModeKeyUpHandler: null,
        fallAnimationFrame: 0,
        fallAnimation: null,
        trackerStartTimer: 0,
        trackerActive: false,
        trackerX: 0,
        trackerY: 0,
        trackerVelocityX: 0,
        trackerVelocityY: 0,
        collisionLocked: false,
        journalActiveCard: null,
        journalCardOffset: 0,
        journalLastShadowX: null,
        terminalCameraX: 0,
        terminalCameraY: 0,
        terminalVoidProgress: 0,
        terminalRedirecting: false,
        terminalReflectionOpacity: 0.58,
        journalFallingCards: new Set(),
        detachedGeometryAt: 0,
        collisionGeometryAt: 0,
        pointerId: null,
        dragOffsetX: 0,
        dragOffsetY: 0,
        lastPointerX: 0,
        lastPointerAt: 0,
        rotation: 0,
        targetRotation: 0,
        lastFrameAt: performance.now(),
        animationFrame: 0,
        pointerMove: null,
        pointerEnd: null,
    };

    const clampPosition = (left, top) => ({
        left: Math.min(Math.max(0, left), Math.max(0, window.innerWidth - FRUITY_DANCE_WIDTH)),
        top: Math.min(Math.max(0, top), Math.max(0, window.innerHeight - FRUITY_DANCE_HEIGHT)),
    });
    const renderFrame = () => {
        const rows = fruityDanceRowCount(controller.prefs.spritesheet);
        const row = controller.dragging && controller.prefs.spritesheet !== FRUITY_DANCE_CUSTOM_SPRITESHEET ? FRUITY_DANCE_HELD_ROW : controller.prefs.loop;
        if (controller.prefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET && !fruityDanceCustomImage) {
            controller.sprite.style.backgroundPosition = '0 0';
            controller.reflection.style.backgroundPosition = controller.usingAlternateLayer ? '0 0px' : '0 0';
        } else {
            setFruityDanceFrame(controller.sprite, row, controller.frame, rows);
            setFruityDanceFrame(controller.reflection, row, controller.frame, rows);
        }
    };
    const scrollForDetachedModeShadow = suppliedRect => {
        if (performance.now() < controller.detachedModeBoundsActiveAt) return;
        const rect = suppliedRect || controller.reflection.getBoundingClientRect();
        if (rect.bottom <= 0 || rect.top >= window.innerHeight) {
            triggerDisplayAuxExitFault(true);
            return;
        }
        if (rect.top >= 0 && rect.bottom <= window.innerHeight) return;
        const overflow = rect.top < 0 ? -rect.top : rect.bottom - window.innerHeight;
        const amount = Math.min(12, Math.max(1, overflow * 0.16));
        // The shadow is outside these panes, so only the upside-down site content scrolls.
        const scrollDelta = rect.top < 0 ? amount : -amount;
        [document.getElementById('sidebar'), document.getElementById('container')].forEach(pane => {
            if (pane) pane.scrollTop += scrollDelta;
        });
    };
    const applyJournalBlueFault = () => {
        const progress = Math.min(1, displayAuxBlueFaultLevel / 10);
        document.body.style.setProperty('--display-blue-fault', progress.toFixed(3));
        document.body.classList.toggle('display-aux-blue-fault', progress > 0);
        displayAuxRotationFaultIntensity = Math.max(displayAuxRotationFaultIntensity, 0.2 + progress * 3.8);
        document.body.style.setProperty('--display-fault-intensity', displayAuxRotationFaultIntensity.toFixed(3));
        syncDisplayAuxCrtWave(Math.min(1, 0.32 + progress * 0.68));
        // The detached mix deliberately exceeds the ordinary wet-gain cap.
        // Reapplying the regular intensity curve here made the first card
        // remove most of that reverb.
        if (displayAuxDetachedModeState) submergeDisplayAuxMusic();
        else setDisplayAuxReverb(Math.min(1, 0.48 + progress * 0.52));
        let visualLayer = document.getElementById('display-aux-blue-fault-layer');
        if (!visualLayer) {
            visualLayer = document.createElement('div');
            visualLayer.id = 'display-aux-blue-fault-layer';
            visualLayer.setAttribute('aria-hidden', 'true');
            document.body.appendChild(visualLayer);
        }
        if (!displayAuxBlueVisualTimer && !displayAuxBlueTerminalState) {
            const disturbScreen = () => {
                const layer = document.getElementById('display-aux-blue-fault-layer');
                if (!layer || !displayAuxBlueFaultLevel || displayAuxBlueTerminalState) {
                    displayAuxBlueVisualTimer = 0;
                    return;
                }
                const strength = displayAuxBlueFaultLevel / 10;
                layer.style.backgroundImage = `url("${displayAuxRandomArt()}")`;
                layer.style.opacity = String(0.025 + strength * 0.48);
                randomizeDisplayAuxElement(layer, 0.12 + strength * 1.35);
                window.setTimeout(() => {
                    if (!displayAuxBlueTerminalState) layer.style.opacity = '0';
                }, 25 + strength * 105 + Math.random() * 65);
                displayAuxBlueVisualTimer = window.setTimeout(disturbScreen, 1500 - strength * 1340 + Math.random() * (900 - strength * 720));
            };
            disturbScreen();
        }
        if (!displayAuxBlueAudioTimer && !displayAuxBlueTerminalState) {
            const disturbAudio = () => {
                if (!displayAuxBlueFaultLevel || displayAuxBlueTerminalState) {
                    displayAuxBlueAudioTimer = 0;
                    return;
                }
                const audio = document.getElementById('mini-player-audio');
                if (audio && !audio.paused && !audio.ended && audio.readyState >= 2) {
                    const strength = displayAuxBlueFaultLevel / 10;
                    const originalRate = audio.playbackRate;
                    try {
                        if (Math.random() < 0.72) {
                            displayAuxInternalSeekUntil = performance.now() + 250;
                            audio.currentTime = Math.max(0, audio.currentTime - (0.025 + Math.random() * 0.13) * strength);
                        }
                        audio.playbackRate = 1 + (Math.random() - 0.5) * strength * 0.55;
                    } catch (_) { /* ignore unavailable media operations */ }
                    window.setTimeout(() => {
                        if (!displayAuxBlueTerminalState) audio.playbackRate = originalRate;
                    }, 35 + Math.random() * 90);
                }
                const strength = displayAuxBlueFaultLevel / 10;
                displayAuxBlueAudioTimer = window.setTimeout(disturbAudio, 520 - strength * 430 + Math.random() * 360);
            };
            disturbAudio();
        }
    };
    const enterJournalBlueTerminalState = () => {
        if (displayAuxBlueTerminalState) return;
        displayAuxBlueTerminalState = true;
        controller.collisionLocked = false;
        controller.trackerActive = false;
        controller.trackerVelocityX = 0;
        controller.trackerVelocityY = 0;
        window.clearTimeout(controller.trackerStartTimer);
        if (displayAuxCrtWaveFrame) {
            window.cancelAnimationFrame(displayAuxCrtWaveFrame);
            displayAuxCrtWaveFrame = 0;
        }
        controller.reflection.classList.remove('display-aux-collisionLocked');
        document.body.classList.add('display-aux-blue-terminal');
        document.body.classList.remove('display-aux-upright-effect');
        document.body.style.setProperty('--display-rotation-intensity', '0');
        document.documentElement.style.background = '#000';
        document.body.style.background = '#000';
        const terminalPage = document.getElementById('page-wrapper');
        if (terminalPage) {
            terminalPage.style.filter = 'sepia(1) saturate(4.5) hue-rotate(158deg) brightness(.72) contrast(1.15)';
        }
        const terminalFaultLayer = document.getElementById('display-aux-blue-fault-layer');
        if (terminalFaultLayer) terminalFaultLayer.style.display = 'none';
        controller.terminalReflectionOpacity = Number.parseFloat(getComputedStyle(controller.reflection).opacity) || 0.58;
        let terminalShade = document.getElementById('display-aux-terminal-shade');
        if (!terminalShade) {
            terminalShade = document.createElement('div');
            terminalShade.id = 'display-aux-terminal-shade';
            terminalShade.setAttribute('aria-hidden', 'true');
            terminalShade.style.cssText = 'position:fixed;inset:0;z-index:2147483000;pointer-events:none;background:#000;opacity:0';
            document.documentElement.appendChild(terminalShade);
        }
        const audio = document.getElementById('mini-player-audio');
        if (audio) {
            displayAuxDanceTrackArmed = false;
            audio.pause();
        }
    };
    const updateJournalTerminalCamera = suppliedShadowRect => {
        if (!displayAuxBlueTerminalState || controller.terminalRedirecting) return;
        const shadowRect = suppliedShadowRect || controller.reflection.getBoundingClientRect();
        const insetX = Math.min(150, window.innerWidth * 0.24);
        const insetY = Math.min(130, window.innerHeight * 0.22);
        let correctionX = 0;
        let correctionY = 0;
        if (shadowRect.left < insetX) correctionX = insetX - shadowRect.left;
        else if (shadowRect.right > window.innerWidth - insetX) correctionX = window.innerWidth - insetX - shadowRect.right;
        if (shadowRect.top < insetY) correctionY = insetY - shadowRect.top;
        else if (shadowRect.bottom > window.innerHeight - insetY) correctionY = window.innerHeight - insetY - shadowRect.bottom;

        // At 180 degrees a local translation has the opposite screen-space
        // direction. Accumulating the screen correction as camera position
        // keeps the actor bounded while allowing its world position to grow.
        controller.terminalCameraX += correctionX;
        controller.terminalCameraY += correctionY;
        const renderedX = controller.shadowKeyboardX - controller.terminalCameraX;
        const renderedY = controller.shadowKeyboardY - controller.terminalCameraY;
        controller.reflection.style.translate = `${renderedX.toFixed(2)}px ${renderedY.toFixed(2)}px`;

        // The pursuer is frozen in world space. Offset its layout position by
        // the camera rather than keeping it pinned alongside the controlled
        // actor, allowing the camera to leave it behind.
        controller.sprite.style.left = `${(controller.trackerX - controller.terminalCameraX).toFixed(2)}px`;
        controller.sprite.style.top = `${(controller.trackerY - controller.terminalCameraY).toFixed(2)}px`;

        const page = document.getElementById('page-wrapper');
        if (!page) return;
        page.style.translate = `${(-controller.terminalCameraX).toFixed(2)}px ${(-controller.terminalCameraY).toFixed(2)}px`;
        const pageRect = page.getBoundingClientRect();
        const gapX = pageRect.right < 0 ? -pageRect.right : (pageRect.left > window.innerWidth ? pageRect.left - window.innerWidth : 0);
        const gapY = pageRect.bottom < 0 ? -pageRect.bottom : (pageRect.top > window.innerHeight ? pageRect.top - window.innerHeight : 0);
        const pageEntirelyOutside = gapX > 0 || gapY > 0;
        const depth = pageEntirelyOutside ? Math.hypot(gapX, gapY) : 0;
        controller.terminalVoidProgress = Math.max(0, Math.min(1, depth / 720));
        const remainingLight = 1 - controller.terminalVoidProgress;
        controller.reflection.style.filter = `brightness(${remainingLight.toFixed(3)})`;
        controller.reflection.style.opacity = (controller.terminalReflectionOpacity * remainingLight).toFixed(3);
        const terminalShade = document.getElementById('display-aux-terminal-shade');
        if (terminalShade) terminalShade.style.opacity = controller.terminalVoidProgress.toFixed(3);

        if (controller.terminalVoidProgress < 1
            || Number.parseFloat(controller.reflection.style.opacity || '1') > 0
            || Number.parseFloat(terminalShade?.style.opacity || '0') < 1) return;
        controller.terminalRedirecting = true;
        try {
            localStorage.setItem(DISPLAY_AUX_UNKNOWN_ACCESS_KEY, '1');
        } catch (_) { /* the route remains locked when durable storage is unavailable */ }
        window.location.assign('/error/unknown');
    };
    const finishJournalCardFall = card => {
        card.getAnimations().forEach(animation => animation.cancel());
        card.style.visibility = 'hidden';
        card.style.transform = 'none';
        card.classList.add('display-aux-journal-placeholder');
        controller.journalFallingCards.delete(card);
        if (displayAuxBlueFaultLevel === 0) activateDisplayAuxJournalAccess();
        displayAuxBlueFaultLevel = Math.min(10, displayAuxBlueFaultLevel + 1);
        applyJournalBlueFault();
        if (displayAuxBlueFaultLevel >= 10) enterJournalBlueTerminalState();
    };
    const dropJournalCard = card => {
        controller.journalActiveCard = null;
        controller.journalCardOffset = 0;
        controller.journalFallingCards.add(card);
        const currentTransform = card.style.transform || 'translateX(0)';
        const fallDistance = -(window.innerHeight + card.getBoundingClientRect().height * 2);
        const fall = card.animate([
            { transform: currentTransform, rotate: '0deg' },
            { transform: `${currentTransform} translateY(${(fallDistance * 0.12).toFixed(1)}px)`, rotate: '3deg', offset: 0.28 },
            { transform: `${currentTransform} translateY(${fallDistance.toFixed(1)}px)`, rotate: '18deg' },
        ], { duration: 1150, easing: 'cubic-bezier(.42,0,1,1)', fill: 'forwards' });
        fall.finished.then(() => finishJournalCardFall(card)).catch(() => {});
    };
    const pushJournalCard = suppliedShadowRect => {
        if (!displayAuxDetachedModeState || displayAuxBlueTerminalState) return;
        const path = (window.location.pathname || '/').replace(/\/+$/, '') || '/';
        if (path !== '/journal') {
            controller.journalLastShadowX = null;
            return;
        }
        const shadowRect = suppliedShadowRect || controller.reflection.getBoundingClientRect();
        const shadowHitbox = {
            left: shadowRect.left + 32,
            right: shadowRect.right - 32,
            top: shadowRect.top + 34,
            bottom: shadowRect.bottom - 34,
        };
        const shadowCenterX = shadowRect.left + shadowRect.width / 2;
        const previousX = controller.journalLastShadowX;
        controller.journalLastShadowX = shadowCenterX;
        const screenDeltaX = previousX === null ? 0 : shadowCenterX - previousX;
        const content = document.getElementById('content-main');
        if (!content) return;
        const candidates = [...content.querySelectorAll('a.journal-post-link')]
            .filter(card => !controller.journalFallingCards.has(card) && !card.classList.contains('display-aux-journal-placeholder'));
        if (!controller.journalActiveCard && screenDeltaX < -0.05) {
            controller.journalActiveCard = candidates.find(card => {
                const rect = card.getBoundingClientRect();
                const verticalOverlap = shadowHitbox.top < rect.bottom - 18 && shadowHitbox.bottom > rect.top + 18;
                const atVisibleRightSide = shadowHitbox.left <= rect.right + 5 && shadowHitbox.left >= rect.right - 18;
                return verticalOverlap && atVisibleRightSide;
            }) || null;
            if (controller.journalActiveCard) {
                controller.journalCardOffset = Number.parseFloat(controller.journalActiveCard.dataset.displayAuxOffset || '0') || 0;
                controller.journalActiveCard.classList.add('display-aux-journal-pushed');
            }
        }
        // Only the visible right edge is permeable, and only after that card
        // has become the active push target. Resolve overlap against every
        // other edge/card along the shallowest axis. Since the document is
        // inverted, screen-space correction is the inverse of local movement.
        const blockingCard = candidates.find(card => {
            const rect = card.getBoundingClientRect();
            if (card === controller.journalActiveCard) {
                const stillAtPushFace = shadowHitbox.top < rect.bottom - 18
                    && shadowHitbox.bottom > rect.top + 18
                    && shadowHitbox.left <= rect.right + 8
                    && shadowHitbox.right >= rect.right - 34;
                if (stillAtPushFace) return false;
            }
            return shadowHitbox.left < rect.right && shadowHitbox.right > rect.left
                && shadowHitbox.top < rect.bottom && shadowHitbox.bottom > rect.top;
        });
        if (blockingCard) {
            const rect = blockingCard.getBoundingClientRect();
            const overlapX = Math.min(shadowHitbox.right, rect.right) - Math.max(shadowHitbox.left, rect.left);
            const overlapY = Math.min(shadowHitbox.bottom, rect.bottom) - Math.max(shadowHitbox.top, rect.top);
            let screenCorrectionX = 0;
            let screenCorrectionY = 0;
            if (overlapX < overlapY) {
                screenCorrectionX = (shadowHitbox.left + shadowHitbox.right) / 2 < (rect.left + rect.right) / 2
                    ? -(overlapX + 1)
                    : overlapX + 1;
            } else {
                screenCorrectionY = (shadowHitbox.top + shadowHitbox.bottom) / 2 < (rect.top + rect.bottom) / 2
                    ? -(overlapY + 1)
                    : overlapY + 1;
            }
            controller.shadowKeyboardX -= screenCorrectionX;
            controller.shadowKeyboardY -= screenCorrectionY;
            controller.shadowKeyboardTargetX -= screenCorrectionX;
            controller.shadowKeyboardTargetY -= screenCorrectionY;
            controller.reflection.style.translate = `${controller.shadowKeyboardX.toFixed(2)}px ${controller.shadowKeyboardY.toFixed(2)}px`;
            controller.journalLastShadowX = null;
            return;
        }
        const card = controller.journalActiveCard;
        if (!card) return;
        const touchingRect = card.getBoundingClientRect();
        const stillTouching = shadowHitbox.top < touchingRect.bottom - 18
            && shadowHitbox.bottom > touchingRect.top + 18
            && shadowHitbox.left <= touchingRect.right + 8
            && shadowHitbox.right >= touchingRect.right - 34;
        if (!stillTouching) {
            card.dataset.displayAuxOffset = String(controller.journalCardOffset);
            controller.journalActiveCard = null;
            controller.journalCardOffset = 0;
            return;
        }
        if (screenDeltaX < 0) controller.journalCardOffset += -screenDeltaX;
        card.dataset.displayAuxOffset = String(controller.journalCardOffset);
        card.style.transform = `translateX(${controller.journalCardOffset.toFixed(2)}px)`;
        const cardRect = card.getBoundingClientRect();
        const contentRect = content.getBoundingClientRect();
        if (cardRect.right <= contentRect.left) dropJournalCard(card);
    };
    const animate = timestamp => {
        if (displayAuxDetachedModeState) {
            const movementElapsed = Math.min(0.04, Math.max(0, timestamp - controller.detachedModeLastMoveAt) / 1000);
            controller.detachedModeLastMoveAt = timestamp;
            const horizontal = (controller.detachedModeKeys.has('d') ? 1 : 0) - (controller.detachedModeKeys.has('a') ? 1 : 0);
            const vertical = (controller.detachedModeKeys.has('s') ? 1 : 0) - (controller.detachedModeKeys.has('w') ? 1 : 0);
            const movementSpeed = 190;
            // The body is upside down, so local movement is the inverse of the intended screen direction.
            if (!controller.collisionLocked) {
                controller.shadowKeyboardTargetX -= horizontal * movementSpeed * movementElapsed;
                controller.shadowKeyboardTargetY -= vertical * movementSpeed * movementElapsed;
            }
            const movementEase = 1 - Math.pow(0.0008, movementElapsed);
            controller.shadowKeyboardX += (controller.shadowKeyboardTargetX - controller.shadowKeyboardX) * movementEase;
            controller.shadowKeyboardY += (controller.shadowKeyboardTargetY - controller.shadowKeyboardY) * movementEase;
            if (!displayAuxBlueTerminalState) {
                controller.reflection.style.translate = `${controller.shadowKeyboardX.toFixed(2)}px ${controller.shadowKeyboardY.toFixed(2)}px`;
            }
            controller.targetRotation = controller.collisionLocked ? 0 : horizontal * 14;
            let sampledShadowRect = null;
            if (timestamp - controller.detachedGeometryAt >= 40) {
                sampledShadowRect = controller.reflection.getBoundingClientRect();
                controller.detachedGeometryAt = timestamp;
                if (!controller.collisionLocked && !displayAuxBlueTerminalState) scrollForDetachedModeShadow(sampledShadowRect);
                if (!controller.collisionLocked) pushJournalCard(sampledShadowRect);
                if (!controller.collisionLocked && displayAuxBlueTerminalState) updateJournalTerminalCamera(sampledShadowRect);
            }

            if (controller.trackerActive && !controller.collisionLocked && !displayAuxBlueTerminalState) {
                const targetX = controller.shadowKeyboardX;
                const targetY = (Number.parseFloat(controller.reflection.dataset.detachedModeBaseTop) || 0) + controller.shadowKeyboardY;
                const differenceX = targetX - controller.trackerX;
                const differenceY = targetY - controller.trackerY;
                const distance = Math.max(0.001, Math.hypot(differenceX, differenceY));
                const trackerSpeed = DISPLAY_AUX_TRACKER_SPEED;
                const desiredVelocityX = differenceX / distance * trackerSpeed;
                const desiredVelocityY = differenceY / distance * trackerSpeed;
                // Slow steering preserves momentum, allowing abrupt direction changes to dodge him.
                const steering = 1 - Math.exp(-0.92 * movementElapsed);
                controller.trackerVelocityX += (desiredVelocityX - controller.trackerVelocityX) * steering;
                controller.trackerVelocityY += (desiredVelocityY - controller.trackerVelocityY) * steering;
                controller.trackerX += controller.trackerVelocityX * movementElapsed;
                controller.trackerY += controller.trackerVelocityY * movementElapsed;
                // The sprite's memory-fault animation owns `translate`; use
                // layout coordinates so that animation cannot erase pursuit.
                controller.sprite.style.left = `${controller.trackerX.toFixed(2)}px`;
                controller.sprite.style.top = `${controller.trackerY.toFixed(2)}px`;
                // Counter-rotate the pursuer against the upside-down page while preserving its velocity lean.
                controller.sprite.style.rotate = `${(180 - controller.trackerVelocityX / trackerSpeed * 14).toFixed(2)}deg`;

                if (timestamp - controller.collisionGeometryAt >= 40) {
                const trackerRect = controller.sprite.getBoundingClientRect();
                const shadowRect = sampledShadowRect || controller.reflection.getBoundingClientRect();
                controller.collisionGeometryAt = timestamp;
                const insetHitbox = rect => ({
                    left: rect.left + 30,
                    right: rect.right - 30,
                    top: rect.top + 27,
                    bottom: rect.bottom - 27,
                });
                const trackerHitbox = insetHitbox(trackerRect);
                const shadowHitbox = insetHitbox(shadowRect);
                const touching = trackerHitbox.left < shadowHitbox.right
                    && trackerHitbox.right > shadowHitbox.left
                    && trackerHitbox.top < shadowHitbox.bottom
                    && trackerHitbox.bottom > shadowHitbox.top;
                if (touching) {
                    controller.collisionLocked = true;
                    controller.detachedModeKeys.clear();
                    controller.trackerVelocityX = 0;
                    controller.trackerVelocityY = 0;
                    controller.targetRotation = 0;
                    controller.reflection.classList.add('display-aux-collisionLocked');
                    controller.sprite.classList.add('display-aux-collisionLocked');
                    window.setTimeout(triggerDisplayAuxExitFault, 520);
                }
                }
            }
        }
        const frameDuration = 100 * (100 / controller.prefs.speed);
        if (!displayAuxBlueTerminalState && timestamp - controller.lastFrameAt >= frameDuration) {
            const elapsedFrames = Math.max(1, Math.floor((timestamp - controller.lastFrameAt) / frameDuration));
            controller.frame = (controller.frame + elapsedFrames) % FRUITY_DANCE_FRAMES;
            controller.lastFrameAt = timestamp;
            renderFrame();
        }
        controller.rotation += (controller.targetRotation - controller.rotation) * 0.18;
        if (!displayAuxDetachedModeState) {
            controller.sprite.style.transform = `translateY(${controller.dragging ? -8 : 0}px) rotate(${controller.rotation.toFixed(2)}deg)`;
            controller.reflection.style.setProperty('--display-aux-drag-shift', `${controller.dragging ? 8 : 0}px`);
        } else {
            controller.reflection.style.rotate = `${controller.rotation.toFixed(2)}deg`;
        }
        controller.animationFrame = window.requestAnimationFrame(animate);
    };

    const resetIncompletePageRotation = () => {
        const startingRotation = controller.pageRotation;
        const startedAt = performance.now();
        controller.rotatingPage = false;
        const settle = timestamp => {
            if (displayAuxDetachedModeState) return;
            const progress = Math.min(1, (timestamp - startedAt) / 560);
            const eased = 1 - Math.pow(1 - progress, 3);
            const rotation = startingRotation * (1 - eased);
            controller.pageRotation = rotation;
            displayAuxPageRotation = rotation;
            document.body.style.transform = `rotate(${rotation.toFixed(3)}deg)`;
            applyDisplayAuxUprightIntensity(rotation);
            if (progress < 1) {
                window.requestAnimationFrame(settle);
                return;
            }
            controller.pageRotation = 0;
            displayAuxPageRotation = 0;
            document.body.style.removeProperty('transform');
            document.body.style.removeProperty('transform-origin');
            document.body.style.removeProperty('transition');
            document.body.classList.remove('display-aux-upright-effect');
            document.body.style.setProperty('--display-rotation-intensity', '0');
            syncDisplayAuxCrtWave(0);
            setDisplayAuxReverb(0);
        };
        window.requestAnimationFrame(settle);
    };

    const beginDetachedMode = pointerId => {
        if (displayAuxDetachedModeState) return;
        displayAuxDetachedModeState = true;
        controller.rotationSnappedUpright = true;
        controller.rotatingPage = false;
        controller.pageRotation = controller.pageRotation < 0 ? -180 : 180;
        displayAuxPageRotation = controller.pageRotation;
        controller.pointerId = null;
        if (pointerId !== null && pointerId !== undefined) el.releasePointerCapture?.(pointerId);
        controller.detachedModeBoundsActiveAt = performance.now() + 900;
        controller.reflection.dataset.detachedModeBaseTop = String(Number.parseFloat(controller.reflection.style.top) || FRUITY_DANCE_HEIGHT);
        controller.reflection.style.transformOrigin = '50% 0';
        document.body.classList.add('display-aux-detachedMode', 'display-aux-upright-effect');
        document.body.style.setProperty('--display-rotation-intensity', '1');
        document.body.style.transition = 'transform 420ms cubic-bezier(.2,.75,.25,1)';
        document.body.style.transformOrigin = '50vw 50vh';
        document.body.style.transform = `rotate(${controller.pageRotation}deg)`;
        applyDisplayAuxUprightIntensity(controller.pageRotation);
        dissolveDisplayAuxMaximumFault();
        lockDisplayAuxDocumentTitle();
        submergeDisplayAuxMusic();
        controller.detachedModeKeyHandler = event => {
            if (controller.collisionLocked || !['w', 'a', 's', 'd'].includes(event.key.toLowerCase())) return;
            event.preventDefault();
            controller.detachedModeKeys.add(event.key.toLowerCase());
        };
        controller.detachedModeKeyUpHandler = event => controller.detachedModeKeys.delete(event.key.toLowerCase());
        window.addEventListener('keydown', controller.detachedModeKeyHandler, true);
        window.addEventListener('keyup', controller.detachedModeKeyUpHandler, true);
        const startingTransform = sprite.style.transform || 'translateY(0)';
        const fallenY = -(window.innerHeight + FRUITY_DANCE_HEIGHT * 2);
        controller.fallAnimation = sprite.animate([
            { transform: startingTransform, offset: 0 },
            { transform: startingTransform, offset: 0.22 },
            { transform: `translateY(${(fallenY * 0.08).toFixed(1)}px) rotate(-3deg)`, offset: 0.34 },
            { transform: `translateY(${(fallenY * 0.42).toFixed(1)}px) rotate(8deg)`, offset: 0.62 },
            { transform: `translateY(${fallenY}px) rotate(17deg)`, offset: 1 },
        ], {
            duration: 1450,
            easing: 'cubic-bezier(.42,0,1,1)',
            fill: 'forwards',
        });
        controller.fallAnimation.finished.then(() => {
            sprite.style.transform = `translateY(${fallenY}px) rotate(17deg)`;
            controller.fallAnimation?.cancel();
            controller.fallAnimation = null;
            controller.trackerStartTimer = window.setTimeout(() => {
                controller.trackerX = 0;
                controller.trackerY = fallenY;
                controller.trackerVelocityX = 0;
                controller.trackerVelocityY = 0;
                sprite.style.transition = 'none';
                sprite.style.transform = '';
                sprite.style.left = '0px';
                sprite.style.top = `${fallenY}px`;
                sprite.style.translate = '';
                sprite.style.rotate = '180deg';
                controller.detachedModeLastMoveAt = performance.now();
                controller.trackerActive = true;
            }, 7000);
        }).catch(() => {});
    };

    controller.pointerMove = event => {
        if (event.pointerId !== controller.pointerId) return;
        if (controller.rotatingPage) {
            const angle = Math.atan2(event.clientY - controller.rotationPivotY, event.clientX - controller.rotationPivotX) * 180 / Math.PI;
            let angularDelta = angle - controller.pointerLastAngle;
            if (angularDelta > 180) angularDelta -= 360;
            if (angularDelta < -180) angularDelta += 360;
            const elapsedSeconds = Math.max(0.001, (event.timeStamp - controller.pointerLastRotationAt) / 1000);
            const maximumStep = 48 * elapsedSeconds;
            const requestedStep = angularDelta * 0.06;
            const appliedStep = Math.max(-maximumStep, Math.min(maximumStep, requestedStep));
            const rotation = Math.max(-180, Math.min(180, controller.pageRotation + appliedStep));
            controller.pointerLastAngle = angle;
            controller.pointerLastRotationAt = event.timeStamp;
            controller.pageRotation = rotation;
            displayAuxPageRotation = rotation;
            applyDisplayAuxUprightIntensity(rotation);
            document.body.style.transformOrigin = `${controller.rotationPivotX}px ${controller.rotationPivotY}px`;
            document.body.style.transform = `rotate(${rotation}deg)`;
            if (Math.abs(rotation) >= 180) beginDetachedMode(event.pointerId);
            return;
        }
        if (!controller.dragging) return;
        const position = clampPosition(event.clientX - controller.dragOffsetX, event.clientY - controller.dragOffsetY);
        el.style.left = `${position.left}px`;
        el.style.top = `${position.top}px`;
        const elapsed = Math.max(1, event.timeStamp - controller.lastPointerAt);
        controller.targetRotation = Math.max(-28, Math.min(28, ((event.clientX - controller.lastPointerX) / elapsed) * 50));
        controller.lastPointerX = event.clientX;
        controller.lastPointerAt = event.timeStamp;
    };

    controller.pointerEnd = event => {
        if (event.pointerId !== controller.pointerId) return;
        if (controller.rotatingPage) {
            resetIncompletePageRotation();
        }
        controller.dragging = false;
        controller.inputStress = false;
        controller.sprite.classList.remove('display-aux-inputStress');
        controller.pointerId = null;
        controller.targetRotation = 0;
        el.style.cursor = 'grab';
        el.releasePointerCapture?.(event.pointerId);
        syncDisplayAuxMaximumFault(displayAuxVarianceLevel());
        applyFruityDanceReflection(controller);
    };

    el.addEventListener('pointerdown', event => {
        if (event.button !== 0 || controller.rotationSnappedUpright) return;
        const reflectionRect = reflection.getBoundingClientRect();
        const onReflection = event.clientX >= reflectionRect.left && event.clientX <= reflectionRect.right
            && event.clientY >= reflectionRect.top && event.clientY <= reflectionRect.bottom;
        const maximum = displayAuxVarianceLevel() === 10
            && controller.prefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
            && !fruityDanceCustomImage;
        if (onReflection && maximum && displayAuxCorruptedShadowLatched && normalized.reflection === 100) {
            controller.rotatingPage = true;
            controller.pointerId = event.pointerId;
            const rect = el.getBoundingClientRect();
            controller.rotationPivotX = rect.left + FRUITY_DANCE_WIDTH / 2;
            controller.rotationPivotY = rect.top + (FRUITY_DANCE_HEIGHT + (Number.parseFloat(reflection.style.top) || FRUITY_DANCE_HEIGHT)) / 2;
            controller.pointerStartAngle = Math.atan2(event.clientY - controller.rotationPivotY, event.clientX - controller.rotationPivotX) * 180 / Math.PI;
            controller.pointerLastAngle = controller.pointerStartAngle;
            controller.pointerLastRotationAt = event.timeStamp;
            controller.pageRotationAtPointerDown = controller.pageRotation;
            document.body.style.transition = 'none';
            el.setPointerCapture?.(event.pointerId);
            event.preventDefault();
            return;
        }
        if (onReflection) return;
        if (maximum) {
            controller.inputStress = true;
            controller.pointerId = event.pointerId;
            controller.sprite.classList.add('display-aux-inputStress');
            el.setPointerCapture?.(event.pointerId);
            syncDisplayAuxMaximumFault(10);
            event.preventDefault();
            return;
        }
        controller.dragging = true;
        controller.pointerId = event.pointerId;
        const rect = el.getBoundingClientRect();
        controller.dragOffsetX = event.clientX - rect.left;
        controller.dragOffsetY = event.clientY - rect.top;
        controller.lastPointerX = event.clientX;
        controller.lastPointerAt = event.timeStamp;
        el.style.cursor = 'grabbing';
        el.setPointerCapture?.(event.pointerId);
        applyFruityDanceReflection(controller);
        renderFrame();
        event.preventDefault();
    });

    window.addEventListener('pointermove', controller.pointerMove);
    window.addEventListener('pointerup', controller.pointerEnd);
    window.addEventListener('pointercancel', controller.pointerEnd);
    fruityDanceController = controller;
    applyFruityDanceSpritesheet(controller);
    applyFruityDanceReflection(controller);
    renderFrame();
    controller.animationFrame = window.requestAnimationFrame(animate);
}

function setFruityDancePrefs(prefs, opts = {}) {
    const normalized = opts.persistLocal === false
        ? normalizeFruityDancePrefs(prefs)
        : saveLocalFruityDancePrefs(prefs);
    const showingCustomPlaceholder = normalized.enabled
        && normalized.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
        && !fruityDanceCustomImage;
    const showingCustomSelection = normalized.enabled
        && normalized.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET;
    const previousPrefs = fruityDanceController?.prefs;
    const changingAwayAfterMaximum = displayAuxVarianceLevel() === 10
        && previousPrefs?.enabled
        && previousPrefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
        && (!normalized.enabled || normalized.spritesheet !== FRUITY_DANCE_CUSTOM_SPRITESHEET);
    syncDisplayAuxContactTimer(showingCustomPlaceholder, normalized.enabled);
    syncDisplayAuxDebugDiagnostics(showingCustomPlaceholder, displayAuxDebugModeEnabled());
    syncDisplayAuxConsoleCommand(showingCustomPlaceholder);
    if (showingCustomSelection && displayAuxJournalAccessActive()) displayAuxDanceEntryUnlocked = true;
    syncDisplayAuxDanceTrackEntry(showingCustomPlaceholder || (showingCustomSelection && displayAuxJournalAccessActive()));
    syncDisplayAuxMaximumFault(showingCustomPlaceholder ? displayAuxVarianceLevel() : 0);
    if (changingAwayAfterMaximum) {
        triggerDisplayAuxExitFault();
        return normalized;
    }
    if (normalized.enabled) startFruityDance(normalized);
    else stopFruityDance();
    settingsDebugLog(`fruity dance ${normalized.enabled ? 'enabled' : 'disabled'} (loop ${normalized.loop + 1}, speed ${normalized.speed}%, reflection ${normalized.reflection}%)`);
    return normalized;
}

function syncFruityDancePreference() {
    const localPrefs = readLocalFruityDancePrefs();
    setFruityDancePrefs(localPrefs, { persistLocal: false });
    if (!document.getElementById('user-greeting') || !window.fetch) return;
    fetch('/api/settings', {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(response => response.ok ? response.json() : null).then(data => {
        if (!data?.ok || !data.settings || typeof data.settings.fruityDanceEnabled !== 'boolean') return;
        setFruityDancePrefs({
            enabled: data.settings.fruityDanceEnabled,
            spritesheet: localPrefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
                ? FRUITY_DANCE_CUSTOM_SPRITESHEET
                : data.settings.fruityDanceSpritesheet,
            animations: localPrefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
                ? localPrefs.animations
                : data.settings.fruityDanceAnimations,
            loop: localPrefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
                ? localPrefs.loop
                : data.settings.fruityDanceLoop,
            speed: data.settings.fruityDanceSpeed,
            reflection: data.settings.fruityDanceReflection,
        });
    }).catch(() => {});
}

function initFruityDanceSettings() {
    bindDisplayAuxDanceTrackMonitor();
    enforceDisplayAuxDebugDismissal();
    // This initializer also runs after SPA content swaps. Recreate or remove
    // the detached music entry now that the route's grids are in the DOM.
    syncDisplayAuxDanceTrackEntryForCurrentPage();
    const fruityDanceToggle = document.getElementById('fruity-dance-toggle');
    const fruityDanceSettings = document.getElementById('fruity-dance-settings');
    const fruityDanceSpritesheet = document.getElementById('fruity-dance-spritesheet');
    const fruityDanceCustomUpload = document.getElementById('fruity-dance-custom-upload');
    const fruityDanceUploadButton = document.getElementById('fruity-dance-upload-button');
    const fruityDanceRemoveButton = document.getElementById('fruity-dance-remove-button');
    const fruityDanceUploadInput = document.getElementById('fruity-dance-upload-input');
    const fruityDanceMetaInput = document.getElementById('fruity-dance-meta-input');
    const fruityDanceUploadStatus = document.getElementById('fruity-dance-upload-status');
    const fruityDanceLoop = document.getElementById('fruity-dance-loop');
    const fruityDanceSpeed = document.getElementById('fruity-dance-speed');
    const fruityDanceSpeedValue = document.getElementById('fruity-dance-speed-value');
    const fruityDanceReflection = document.getElementById('fruity-dance-reflection');
    const fruityDanceReflectionValue = document.getElementById('fruity-dance-reflection-value');
    if (!fruityDanceToggle || fruityDanceToggle.dataset.bound === '1') return;
    fruityDanceToggle.dataset.bound = '1';

    const isLoggedIn = !!document.getElementById('user-greeting');
    const isToastSession = !!document.querySelector('#toast-settings[data-toast-session="1"]');
    let fruityDancePicker = null;
    let fruityDancePickerTitle = null;
    let fruityDancePickerDescription = null;
    let fruityDancePickerPreview = null;
    let fruityDanceLoopPicker = null;
    let fruityDanceLoopPickerTitle = null;
    let fruityDanceLoopPickerPreview = null;
    let fruityDanceSaveTimer = 0;
    const defaultFruityDanceLoopOptions = fruityDanceLoop
        ? [...fruityDanceLoop.options].map(option => ({ value: option.value, label: option.textContent }))
        : [];
    const getSpritesheetOptionAnimations = option => {
        if (!option || option.value === FRUITY_DANCE_CUSTOM_SPRITESHEET) {
            return fruityDanceCustomAnimations.length ? fruityDanceCustomAnimations : FRUITY_DANCE_DEFAULT_ANIMATIONS;
        }
        try {
            const parsed = JSON.parse(option.dataset.animations || '[]');
            return Array.isArray(parsed) && parsed.length
                ? parsed.map(name => String(name).trim()).filter(Boolean)
                : FRUITY_DANCE_DEFAULT_ANIMATIONS;
        } catch (_) {
            return FRUITY_DANCE_DEFAULT_ANIMATIONS;
        }
    };
    [...(fruityDanceSpritesheet?.options || [])].forEach(option => {
        if (option.value !== FRUITY_DANCE_CUSTOM_SPRITESHEET) {
            fruityDanceAnimationsBySheet.set(option.value, getSpritesheetOptionAnimations(option));
        }
    });

        const setFruityDanceControls = prefs => {
            const incoming = prefs && typeof prefs === 'object' ? { ...prefs } : {};
            const catalogOption = [...(fruityDanceSpritesheet?.options || [])].find(option => option.value === incoming.spritesheet);
            if (catalogOption && catalogOption.value !== FRUITY_DANCE_CUSTOM_SPRITESHEET) {
                incoming.animations = getSpritesheetOptionAnimations(catalogOption);
            }
            const normalized = normalizeFruityDancePrefs(incoming);
            const previousSpritesheet = fruityDanceSpritesheet?.value;
            if (fruityDanceSpritesheet) {
                const available = [...fruityDanceSpritesheet.options].map(option => option.value);
                fruityDanceSpritesheet.value = available.includes(normalized.spritesheet) ? normalized.spritesheet : (available[0] || normalized.spritesheet);
                normalized.spritesheet = fruityDanceSpritesheet.value || normalized.spritesheet;
            }
            if (fruityDanceToggle) fruityDanceToggle.checked = normalized.enabled;
            if (fruityDanceLoop) fruityDanceLoop.value = String(normalized.loop);
            if (fruityDanceSpeed) fruityDanceSpeed.value = String(normalized.speed);
            if (fruityDanceReflection) fruityDanceReflection.value = String(normalized.reflection);
            if (fruityDanceSpeed) fruityDanceSpeed.style.setProperty('--fruity-slider-fill', `${((normalized.speed - 25) / 175) * 100}%`);
            if (fruityDanceReflection) fruityDanceReflection.style.setProperty('--fruity-slider-fill', `${normalized.reflection}%`);
            if (fruityDanceSpeedValue) fruityDanceSpeedValue.textContent = `${normalized.speed}%`;
            if (fruityDanceReflectionValue) fruityDanceReflectionValue.textContent = `${normalized.reflection}%`;
            if (fruityDanceSettings) fruityDanceSettings.hidden = !normalized.enabled;
            if (fruityDanceCustomUpload) fruityDanceCustomUpload.hidden = normalized.spritesheet !== FRUITY_DANCE_CUSTOM_SPRITESHEET;
            if (fruityDanceUploadStatus) fruityDanceUploadStatus.textContent = fruityDanceCustomImage ? 'custom image stored in this browser' : 'no custom image selected';
            if (fruityDanceRemoveButton) fruityDanceRemoveButton.hidden = !fruityDanceCustomImage;
            if (fruityDanceLoopPicker && previousSpritesheet !== normalized.spritesheet) rebuildFruityDanceLoopPicker();
            const showingDisplayAux = normalized.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET && !fruityDanceCustomImage;
            if (fruityDanceSettings) fruityDanceSettings.classList.toggle('display-aux-controls-disabled', showingDisplayAux);
            [fruityDanceLoop, fruityDanceSpeed].forEach(control => {
                if (control) control.disabled = showingDisplayAux;
            });
            if (fruityDanceReflection) fruityDanceReflection.disabled = false;
            if (fruityDanceLoopPicker) {
                fruityDanceLoopPicker.classList.toggle('is-disabled', showingDisplayAux);
                const pickerButton = fruityDanceLoopPicker.querySelector('.theme-picker-button');
                if (pickerButton) pickerButton.disabled = showingDisplayAux;
            }
            updateFruityDancePicker();
            updateFruityDanceLoopPicker();
            return normalized;
        };

        const getFruityDanceValues = () => normalizeFruityDancePrefs({
            enabled: !!(fruityDanceToggle && fruityDanceToggle.checked),
            spritesheet: fruityDanceSpritesheet ? fruityDanceSpritesheet.value : FRUITY_DANCE_DEFAULTS.spritesheet,
            animations: getSpritesheetOptionAnimations(fruityDanceSpritesheet?.selectedOptions?.[0]),
            loop: fruityDanceLoop ? fruityDanceLoop.value : FRUITY_DANCE_DEFAULTS.loop,
            speed: fruityDanceSpeed ? fruityDanceSpeed.value : FRUITY_DANCE_DEFAULTS.speed,
            reflection: fruityDanceReflection ? fruityDanceReflection.value : FRUITY_DANCE_DEFAULTS.reflection,
        });

        const applyFruityDanceSettingsImmediately = () => {
            const normalized = setFruityDanceControls(getFruityDanceValues());
            setFruityDancePrefs(normalized);
            if (!isLoggedIn || isToastSession || !window.fetch) return normalized;
            window.clearTimeout(fruityDanceSaveTimer);
            fruityDanceSaveTimer = window.setTimeout(() => {
                const latest = getFruityDanceValues();
                const params = new URLSearchParams();
                params.append('fruityDanceEnabled', latest.enabled ? 'on' : 'off');
                if (latest.spritesheet !== FRUITY_DANCE_CUSTOM_SPRITESHEET) params.append('fruityDanceSpritesheet', latest.spritesheet);
                if (latest.spritesheet !== FRUITY_DANCE_CUSTOM_SPRITESHEET) params.append('fruityDanceLoop', String(latest.loop));
                params.append('fruityDanceSpeed', String(latest.speed));
                params.append('fruityDanceReflection', String(latest.reflection));
                fetch('/api/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: params.toString(),
                }).catch(() => {});
            }, 180);
            return normalized;
        };

        const createFruityDanceMiniPreview = filename => {
            const preview = document.createElement('span');
            preview.className = 'fruity-dance-picker-preview';
            preview.dataset.spritesheet = filename;
            preview.style.backgroundImage = `url("${fruityDanceSpritesheetUrl(filename)}")`;
            preview.style.backgroundSize = fruityDanceBackgroundSize(filename);
            preview.setAttribute('aria-hidden', 'true');
            return preview;
        };

        const updateFruityDancePicker = () => {
            if (!fruityDancePicker || !fruityDanceSpritesheet) return;
            const selected = fruityDanceSpritesheet.value;
            const option = [...fruityDanceSpritesheet.options].find(item => item.value === selected);
            if (fruityDancePickerTitle) fruityDancePickerTitle.textContent = option?.textContent || selected;
            if (fruityDancePickerDescription) fruityDancePickerDescription.textContent = option?.dataset.description || '';
            if (fruityDancePickerPreview) {
                fruityDancePickerPreview.dataset.spritesheet = selected;
                fruityDancePickerPreview.style.backgroundImage = `url("${fruityDanceSpritesheetUrl(selected)}")`;
            }
            fruityDancePicker.querySelectorAll('.theme-picker-option').forEach(item => {
                const active = item.dataset.spritesheet === selected;
                item.classList.toggle('selected', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            fruityDanceLoopPicker?.querySelectorAll('.fruity-dance-picker-preview').forEach(preview => {
                preview.dataset.spritesheet = selected;
                preview.style.backgroundImage = `url("${fruityDanceSpritesheetUrl(selected)}")`;
            });
        };

        const ensureFruityDancePicker = () => {
            if (!fruityDanceSpritesheet || fruityDancePicker) return;
            fruityDanceSpritesheet.classList.add('native-theme-select');
            fruityDancePicker = document.createElement('div');
            fruityDancePicker.className = 'theme-picker title-animation-picker fruity-dance-picker';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'theme-picker-button';
            button.setAttribute('aria-haspopup', 'listbox');
            button.setAttribute('aria-expanded', 'false');
            fruityDancePickerPreview = createFruityDanceMiniPreview(fruityDanceSpritesheet.value);
            const copy = document.createElement('span');
            copy.className = 'theme-picker-copy';
            fruityDancePickerTitle = document.createElement('span');
            fruityDancePickerTitle.className = 'theme-picker-title';
            fruityDancePickerDescription = document.createElement('span');
            fruityDancePickerDescription.className = 'theme-picker-description';
            copy.append(fruityDancePickerTitle, fruityDancePickerDescription);
            const chevron = document.createElement('span');
            chevron.className = 'theme-picker-chevron';
            chevron.textContent = '▾';
            button.append(fruityDancePickerPreview, copy, chevron);
            const menu = document.createElement('div');
            menu.className = 'theme-picker-menu';
            menu.setAttribute('role', 'listbox');
            [...fruityDanceSpritesheet.options].forEach(selectOption => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'theme-picker-option';
                item.dataset.spritesheet = selectOption.value;
                item.setAttribute('role', 'option');
                const label = document.createElement('span');
                label.className = 'theme-picker-copy';
                const title = document.createElement('span');
                title.className = 'theme-picker-option-title';
                title.textContent = selectOption.textContent;
                const description = document.createElement('span');
                description.className = 'theme-picker-option-description';
                description.textContent = selectOption.dataset.description || '';
                label.append(title, description);
                item.append(createFruityDanceMiniPreview(selectOption.value), label);
                item.addEventListener('click', () => {
                    fruityDanceSpritesheet.value = selectOption.value;
                    fruityDanceSpritesheet.dispatchEvent(new Event('change', { bubbles: true }));
                    fruityDancePicker.classList.remove('open');
                    button.setAttribute('aria-expanded', 'false');
                });
                menu.append(item);
            });
            fruityDancePicker.append(button, menu);
            fruityDanceSpritesheet.insertAdjacentElement('afterend', fruityDancePicker);
            button.addEventListener('click', () => {
                const open = fruityDancePicker.classList.toggle('open');
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', event => {
                if (!fruityDancePicker.contains(event.target)) fruityDancePicker.classList.remove('open');
            });
            window.setInterval(() => {
                const frame = Math.floor(performance.now() / 100) % FRUITY_DANCE_FRAMES;
                document.querySelectorAll('.fruity-dance-picker-preview').forEach(preview => {
                    const rows = fruityDanceRowCount(preview.dataset.spritesheet || FRUITY_DANCE_DEFAULT_SPRITESHEET);
                    setFruityDanceFrame(preview, Number.parseInt(preview.dataset.loop || '0', 10), frame, rows);
                });
            }, 100);
            updateFruityDancePicker();
        };

        const updateFruityDanceLoopPicker = () => {
            if (!fruityDanceLoopPicker || !fruityDanceLoop) return;
            const selected = fruityDanceLoop.value;
            const option = [...fruityDanceLoop.options].find(item => item.value === selected);
            const showingDisplayAux = fruityDanceSpritesheet?.value === FRUITY_DANCE_CUSTOM_SPRITESHEET && !fruityDanceCustomImage;
            if (fruityDanceLoopPickerTitle) fruityDanceLoopPickerTitle.textContent = showingDisplayAux ? '' : (option?.textContent || selected);
            if (fruityDanceLoopPickerPreview) fruityDanceLoopPickerPreview.dataset.loop = selected;
            fruityDanceLoopPicker.querySelectorAll('.theme-picker-option').forEach(item => {
                const active = item.dataset.loop === selected;
                item.classList.toggle('selected', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        };

        const ensureFruityDanceLoopPicker = () => {
            if (!fruityDanceLoop || fruityDanceLoopPicker) return;
            fruityDanceLoop.classList.add('native-theme-select');
            fruityDanceLoopPicker = document.createElement('div');
            fruityDanceLoopPicker.className = 'theme-picker title-animation-picker fruity-dance-picker fruity-dance-loop-picker';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'theme-picker-button';
            button.setAttribute('aria-haspopup', 'listbox');
            button.setAttribute('aria-expanded', 'false');
            fruityDanceLoopPickerPreview = createFruityDanceMiniPreview(fruityDanceSpritesheet?.value || FRUITY_DANCE_DEFAULTS.spritesheet);
            fruityDanceLoopPickerPreview.dataset.loop = fruityDanceLoop.value;
            const copy = document.createElement('span');
            copy.className = 'theme-picker-copy';
            fruityDanceLoopPickerTitle = document.createElement('span');
            fruityDanceLoopPickerTitle.className = 'theme-picker-title';
            copy.append(fruityDanceLoopPickerTitle);
            const chevron = document.createElement('span');
            chevron.className = 'theme-picker-chevron';
            chevron.textContent = '▾';
            button.append(fruityDanceLoopPickerPreview, copy, chevron);
            const menu = document.createElement('div');
            menu.className = 'theme-picker-menu';
            menu.setAttribute('role', 'listbox');
            [...fruityDanceLoop.options].forEach(selectOption => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'theme-picker-option';
                item.dataset.loop = selectOption.value;
                item.setAttribute('role', 'option');
                const preview = createFruityDanceMiniPreview(fruityDanceSpritesheet?.value || FRUITY_DANCE_DEFAULTS.spritesheet);
                preview.dataset.loop = selectOption.value;
                const copy = document.createElement('span');
                copy.className = 'theme-picker-copy';
                const title = document.createElement('span');
                title.className = 'theme-picker-option-title';
                title.textContent = selectOption.textContent;
                copy.append(title);
                item.append(preview, copy);
                item.addEventListener('click', () => {
                    fruityDanceLoop.value = selectOption.value;
                    fruityDanceLoop.dispatchEvent(new Event('change', { bubbles: true }));
                    fruityDanceLoopPicker.classList.remove('open');
                    button.setAttribute('aria-expanded', 'false');
                });
                menu.append(item);
            });
            fruityDanceLoopPicker.append(button, menu);
            fruityDanceLoop.insertAdjacentElement('afterend', fruityDanceLoopPicker);
            button.addEventListener('click', () => {
                const open = fruityDanceLoopPicker.classList.toggle('open');
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', event => {
                if (!fruityDanceLoopPicker.contains(event.target)) fruityDanceLoopPicker.classList.remove('open');
            });
            updateFruityDanceLoopPicker();
        };

        const rebuildFruityDanceLoopPicker = () => {
            if (!fruityDanceLoop) return;
            const selectedOption = fruityDanceSpritesheet?.selectedOptions?.[0];
            const animations = getSpritesheetOptionAnimations(selectedOption);
            const definitions = animations.length
                ? animations.map((label, index) => ({ value: String(index), label }))
                : defaultFruityDanceLoopOptions;
            fruityDanceLoop.replaceChildren(...definitions.map(definition => {
                const option = document.createElement('option');
                option.value = definition.value;
                option.textContent = definition.label;
                return option;
            }));
            fruityDanceLoopPicker?.remove();
            fruityDanceLoopPicker = null;
            fruityDanceLoopPickerTitle = null;
            fruityDanceLoopPickerPreview = null;
            ensureFruityDanceLoopPicker();
        };

        const readFileAsDataUrl = file => new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.addEventListener('load', () => resolve(String(reader.result || '')));
            reader.addEventListener('error', () => reject(reader.error));
            reader.readAsDataURL(file);
        });

        const readFileAsText = file => new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.addEventListener('load', () => resolve(String(reader.result || '')));
            reader.addEventListener('error', () => reject(reader.error));
            reader.readAsText(file);
        });

        const storeCustomFruityDanceAsset = () => {
            localStorage.setItem(FRUITY_DANCE_CUSTOM_IMAGE_KEY, fruityDanceCustomImage);
            localStorage.setItem(FRUITY_DANCE_CUSTOM_META_KEY, JSON.stringify(fruityDanceCustomAnimations));
        };


    ensureFruityDancePicker();
    ensureFruityDanceLoopPicker();
    setFruityDanceControls(readLocalFruityDancePrefs());

    if (isLoggedIn && window.fetch) {
        fetch('/api/settings', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then(response => response.ok ? response.json() : null).then(data => {
            if (!data?.ok || !data.settings || typeof data.settings.fruityDanceEnabled !== 'boolean') return;
            const localPrefs = readLocalFruityDancePrefs();
            setFruityDanceControls({
                enabled: data.settings.fruityDanceEnabled,
                spritesheet: localPrefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
                    ? FRUITY_DANCE_CUSTOM_SPRITESHEET
                    : data.settings.fruityDanceSpritesheet,
                animations: localPrefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
                    ? localPrefs.animations
                    : data.settings.fruityDanceAnimations,
                loop: localPrefs.spritesheet === FRUITY_DANCE_CUSTOM_SPRITESHEET
                    ? localPrefs.loop
                    : data.settings.fruityDanceLoop,
                speed: data.settings.fruityDanceSpeed,
                reflection: data.settings.fruityDanceReflection,
            });
        }).catch(() => {});
    }

        if (fruityDanceToggle) {
            fruityDanceToggle.addEventListener('change', applyFruityDanceSettingsImmediately);
        }
        if (fruityDanceSpritesheet) {
            fruityDanceSpritesheet.addEventListener('change', () => {
                rebuildFruityDanceLoopPicker();
                setFruityDanceControls(getFruityDanceValues());
            });
        }
        fruityDanceUploadButton?.addEventListener('click', () => {
            if (displayAuxVarianceLevel() === 10) return;
            fruityDanceUploadInput?.click();
        });
        fruityDanceRemoveButton?.addEventListener('click', () => {
            const removedCustomImage = !!fruityDanceCustomImage;
            fruityDanceCustomImage = '';
            fruityDanceCustomAnimations = [];
            if (removedCustomImage && displayAuxDanceTrackAllowsCorruption()) {
                displayAuxRemovalCount = displayAuxJournalAccessActive() ? 7 : displayAuxRemovalCount + 1;
            }
            try {
                localStorage.removeItem(FRUITY_DANCE_CUSTOM_IMAGE_KEY);
                localStorage.removeItem(FRUITY_DANCE_CUSTOM_META_KEY);
            } catch (_) { /* ignore */ }
            if (fruityDanceUploadInput) fruityDanceUploadInput.value = '';
            if (fruityDanceMetaInput) fruityDanceMetaInput.value = '';
            document.querySelectorAll('.fruity-dance-picker-preview[data-spritesheet="__custom__"]').forEach(preview => {
                preview.style.backgroundImage = `url("${FRUITY_DANCE_CUSTOM_PLACEHOLDER_URL}")`;
                preview.style.backgroundSize = '100% 100%';
                preview.style.backgroundPosition = '0 0';
            });
            rebuildFruityDanceLoopPicker();
            applyFruityDanceSettingsImmediately();
        });
        fruityDanceUploadInput?.addEventListener('change', async () => {
            const file = fruityDanceUploadInput.files?.[0];
            if (!file) return;
            try {
                if (!['image/png', 'image/jpeg', 'image/webp', 'image/gif'].includes(file.type)) throw new Error('unsupported image type');
                fruityDanceCustomImage = await readFileAsDataUrl(file);
                fruityDanceCustomAnimations = [];
                storeCustomFruityDanceAsset();
                rebuildFruityDanceLoopPicker();
                document.querySelectorAll('.fruity-dance-picker-preview[data-spritesheet="__custom__"]').forEach(preview => {
                    preview.style.backgroundImage = `url("${fruityDanceCustomImage}")`;
                    preview.style.backgroundSize = `${FRUITY_DANCE_FRAMES * 100}% ${fruityDanceRowCount(FRUITY_DANCE_CUSTOM_SPRITESHEET) * 100}%`;
                });
                applyFruityDanceSettingsImmediately();
                const addMeta = await showSitePopup({
                    title: 'custom animation names',
                    detail: 'would you like to provide a .txt metadata file? put one animation name on each line, in spritesheet row order.',
                    okText: 'choose meta file',
                    cancelText: 'use defaults',
                });
                if (addMeta) {
                    fruityDanceMetaInput.value = '';
                    fruityDanceMetaInput.click();
                }
            } catch (_) {
                await showSitePopup({ title: 'could not store image', detail: 'use a PNG, JPEG, WebP, or GIF file. if it is already supported, try a smaller image.', okText: 'okay' });
            }
        });
        fruityDanceMetaInput?.addEventListener('change', async () => {
            const file = fruityDanceMetaInput.files?.[0];
            if (!file) return;
            try {
                const text = await readFileAsText(file);
                const names = text.split(/\r?\n/).map(name => name.trim()).filter(Boolean);
                if (!names.length) throw new Error('empty metadata');
                fruityDanceCustomAnimations = names;
                storeCustomFruityDanceAsset();
                rebuildFruityDanceLoopPicker();
                applyFruityDanceSettingsImmediately();
            } catch (_) {
                fruityDanceCustomAnimations = [];
                storeCustomFruityDanceAsset();
                rebuildFruityDanceLoopPicker();
                applyFruityDanceSettingsImmediately();
                await showSitePopup({ title: 'invalid metadata', detail: 'no animation names were found, so the default list will be used.', okText: 'okay' });
            }
        });
        [fruityDanceSpritesheet, fruityDanceLoop, fruityDanceSpeed, fruityDanceReflection].forEach(control => {
            control?.addEventListener('input', applyFruityDanceSettingsImmediately);
            control?.addEventListener('change', applyFruityDanceSettingsImmediately);
        });


}

window.addEventListener('fridg3:accessibility-change', event => {
    syncDisplayAuxDebugDiagnostics(displayAuxCustomPlaceholderSelected(), event.detail?.debugMode === true);
    enforceDisplayAuxDebugDismissal();
});
window.fridg3InitFruityDanceSettings = initFruityDanceSettings;
window.fridg3SyncFruityDancePage = syncDisplayAuxDanceTrackEntryForCurrentPage;
bindDisplayAuxDanceTrackMonitor();
window.addEventListener('DOMContentLoaded', syncFruityDancePreference);
window.addEventListener('DOMContentLoaded', bindDisplayAuxDanceTrackMonitor);
window.addEventListener('DOMContentLoaded', initFruityDanceSettings);

})();
