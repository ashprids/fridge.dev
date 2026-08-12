// Sidebar toggle functionality
const sidebarDebugLog = message => window.fridg3DebugClientLog?.(`[sidebar/player] ${message}`);
const hideSidebarBtn = document.getElementById('hide-sidebar');
const showSidebarBtn = document.getElementById('show-sidebar');
const sidebar = document.getElementById('sidebar');
const mobileCollapsedHeader = document.getElementById('mobile-collapsed-header');
const mobileMenuBackdrop = document.getElementById('mobile-menu-backdrop');
const SIDEBAR_KEY = 'sidebarVisible';

function setSidebarVisible(visible, persist = true) {
    if (sidebar) {
        sidebar.style.display = 'flex';
    }
    document.body.classList.toggle('sidebar-is-hidden', !visible);
    document.documentElement.classList.toggle('mobile-menu-is-open', isMobileTemplateActive() && visible);
    if (mobileMenuBackdrop) mobileMenuBackdrop.setAttribute('aria-hidden', visible ? 'false' : 'true');
    if (showSidebarBtn) {
        const mobileToggle = isMobileTemplateActive();
        showSidebarBtn.style.display = mobileToggle ? 'inline-flex' : (visible ? 'none' : 'inline-block');
        showSidebarBtn.setAttribute('aria-expanded', visible ? 'true' : 'false');
        showSidebarBtn.setAttribute('aria-label', visible ? 'hide menu' : 'show menu');
    }
    updateMobileCollapsedHeader(!visible);
    if (persist && !isMobileTemplateActive()) {
        try {
            localStorage.setItem(SIDEBAR_KEY, visible ? 'true' : 'false');
        } catch (_) { /* no-op */ }
    }
    if (persist) sidebarDebugLog(`sidebar ${visible ? 'shown' : 'hidden'}`);
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
    if (isMobileTemplateActive()) {
        // Mobile menus are deliberately closed on every fresh page load.
        setSidebarVisible(false, false);
    } else {
        const isSidebarVisible = localStorage.getItem(SIDEBAR_KEY);
        const defaultSidebarVisible = 'true';
        if (isSidebarVisible === null) {
            localStorage.setItem(SIDEBAR_KEY, defaultSidebarVisible);
            setSidebarVisible(true, false);
        } else if (isSidebarVisible === 'false') {
            setSidebarVisible(false, false);
        } else {
            setSidebarVisible(true, false);
        }
    }
    sidebarDebugLog('sidebar and shared content initialized');

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
        document.querySelectorAll('.feed-post-link[data-post-href]').forEach(card => {
            if (card.dataset.postNavigationBound === '1') return;
            card.dataset.postNavigationBound = '1';
            const openPost = () => {
                const href = card.dataset.postHref || '';
                if (!href) return;
                if (typeof loadPageIntoContent === 'function') loadPageIntoContent(href);
                else window.location.href = href;
            };
            card.addEventListener('click', openPost);
            card.addEventListener('keydown', event => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                openPost();
            });
        });

        const targets = document.querySelectorAll('#post-content, .post-content');
        targets.forEach(el => {
            const markdownContent = el.matches('[data-feed-format="v2"]')
                ? el
                : el.querySelector('[data-feed-format="v2"]');
            if (markdownContent) {
                initInlineMediaPlayers(markdownContent);
                if (typeof hljs !== 'undefined') {
                    markdownContent.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                }
                return;
            }
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

    if (typeof initInlineMediaPlayers === 'function') {
        initInlineMediaPlayers(document);
    }
}

// Run once on initial load
window.addEventListener('DOMContentLoaded', initSidebarAndBBCode);

// Global mini player track library for autoplay logic
const MINI_PLAYER_LIBRARY = {
    tracks: [],
    autoPlayedIds: new Set()
};
const DISPLAY_AUX_DANCE_TRACK_PATH = '/resources/images/fruity-dance/dance.mp3';
const DISPLAY_AUX_DEVICE_ART_PATH = '/resources/images/device-cover.svg';

function isDisplayAuxDanceTrackSrc(src) {
    if (!src) return false;
    try {
        return new URL(src, window.location.href).pathname === DISPLAY_AUX_DANCE_TRACK_PATH;
    } catch (_) {
        return false;
    }
}

function displayAuxDeviceMetadataText(minLength = 12, maxLength = 24) {
    const glyphs = '▓▒░█▄▀╬╫┼┤├┐└┘01?#%&@ΞЖ';
    const length = minLength + Math.floor(Math.random() * (maxLength - minLength + 1));
    return Array.from({ length }, () => glyphs[Math.floor(Math.random() * glyphs.length)]).join('');
}

// Mini music player wiring
function initMiniPlayer() {
    try {
        const audio = document.getElementById('mini-player-audio');
        const miniPlayerEl = document.getElementById('mini-player');
        const playBtn = document.getElementById('mini-player-play');
        const muteBtn = document.getElementById('mini-player-mute');
        const titleContainerEl = document.getElementById('mini-player-title');
        const titleEl = document.getElementById('mini-player-title-inner');
        const artistEl = document.getElementById('mini-player-artist');
        const tracklistEl = document.getElementById('mini-player-tracks');
        const downloadBtn = document.getElementById('mini-player-download');
        const closeBtn = document.getElementById('mini-player-close');
        const artEl = document.getElementById('mini-player-art');
        const seekEl = document.getElementById('mini-player-seek');
        const volumeEl = document.getElementById('mini-player-volume');
        const volumeWrapperEl = document.getElementById('mini-player-volume-wrapper');
        const playerRowEl = document.getElementById('mini-player-row');

        const setLiveMode = (isLive) => {
            if (!miniPlayerEl) return;
            const isolatedTrack = isDisplayAuxDanceTrackSrc(audio?.src);
            if (seekEl) seekEl.disabled = isLive || isolatedTrack;
            if (isLive) {
                miniPlayerEl.classList.add('live-stream');
                if (seekEl) seekEl.style.display = 'none';
                if (downloadBtn) downloadBtn.style.display = 'none';
            } else {
                miniPlayerEl.classList.remove('live-stream');
                if (seekEl) seekEl.style.display = '';
                if (downloadBtn) downloadBtn.style.display = isDisplayAuxDanceTrackSrc(audio?.src) ? 'none' : '';
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
        let lastTrackArtist = '';
        let playbackVolume = DEFAULT_VOLUME;
        let volumeFadeFrame = null;
        let fadeOutActive = false;
        let fallbackSelectionRequired = false;

        setLiveMode(false);

        // Optional initial state from body data attributes
        const body = document.body;
        const initialSrc = body.getAttribute('data-mini-player-src');
        const initialTitle = body.getAttribute('data-mini-player-title');
        const initialArt = body.getAttribute('data-mini-player-art');
        if (initialSrc) audio.src = initialSrc;
        if (initialTitle) {
            setNowPlayingTitle(initialTitle, body.getAttribute('data-mini-player-artist') || '');
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
                    const savedIsolatedTrack = isDisplayAuxDanceTrackSrc(saved.src);
                    if (savedIsolatedTrack) fallbackSelectionRequired = true;
                    if (saved.src && !savedIsolatedTrack) audio.src = saved.src;
                    if (!savedIsolatedTrack && typeof saved.currentTime === 'number' && !Number.isNaN(saved.currentTime)) {
                        audio.currentTime = saved.currentTime;
                    }
                    if (typeof saved.volume === 'number' && saved.volume >= 0 && saved.volume <= 1) {
                        audio.volume = saved.volume;
                    }
                    if (typeof saved.muted === 'boolean') {
                        audio.muted = saved.muted;
                    }
                    if (!savedIsolatedTrack && saved.title && titleEl) {
                        setNowPlayingTitle(saved.title, saved.artist || '');
                    }
                    if (!savedIsolatedTrack && saved.art && artEl) {
                        artEl.src = saved.art;
                    }
                }
            }
        } catch (_) { /* no-op */ }

        playbackVolume = audio.volume;

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

        const cancelVolumeFade = () => {
            if (volumeFadeFrame !== null) {
                window.cancelAnimationFrame(volumeFadeFrame);
                volumeFadeFrame = null;
            }
        };

        const rampAudioVolume = (from, to, duration, complete) => {
            cancelVolumeFade();
            const startedAt = performance.now();
            const tick = now => {
                const progress = Math.min(1, (now - startedAt) / duration);
                audio.volume = Math.max(0, Math.min(1, from + ((to - from) * progress)));
                if (progress < 1) {
                    volumeFadeFrame = window.requestAnimationFrame(tick);
                    return;
                }
                volumeFadeFrame = null;
                if (complete) complete();
            };
            volumeFadeFrame = window.requestAnimationFrame(tick);
        };

        const fadeInPlayback = () => {
            const resumeDuringFadeOut = fadeOutActive;
            fadeOutActive = false;
            const startVolume = resumeDuringFadeOut ? audio.volume : 0;
            cancelVolumeFade();
            audio.volume = startVolume;
            return audio.play().then(() => {
                setPlayIcon(true);
                updatePlayingClass();
                rampAudioVolume(startVolume, playbackVolume, 180, () => {
                    audio.volume = playbackVolume;
                    savePlayerState();
                });
            }).catch(() => {
                audio.volume = playbackVolume;
                setPlayIcon(false);
                updatePlayingClass();
            });
        };

        const fadeOutPlayback = () => {
            fadeOutActive = true;
            const startVolume = audio.volume;
            rampAudioVolume(startVolume, 0, 180, () => {
                if (!fadeOutActive) return;
                fadeOutActive = false;
                audio.pause();
                audio.volume = playbackVolume;
                setPlayIcon(false);
                updatePlayingClass();
                savePlayerState();
            });
        };

        const clearActiveTracks = () => {
            if (!tracklistEl) return;
            tracklistEl.querySelectorAll('.mini-track.active').forEach(el => el.classList.remove('active'));
        };

        const savePlayerState = () => {
            try {
                if (!audio || !audio.src) return;
                const replacementCandidates = isDisplayAuxDanceTrackSrc(audio.src)
                    ? trackLibrary.tracks.filter(track => track?.src && !isDisplayAuxDanceTrackSrc(track.src))
                    : [];
                const replacement = replacementCandidates.length
                    ? replacementCandidates[Math.floor(Math.random() * replacementCandidates.length)]
                    : null;
                if (isDisplayAuxDanceTrackSrc(audio.src) && !replacement) {
                    window.localStorage.removeItem(PLAYER_STATE_KEY);
                    return;
                }
                const state = {
                    src: replacement ? replacement.src : audio.src,
                    currentTime: replacement ? 0 : (audio.currentTime || 0),
                    paused: replacement ? true : audio.paused,
                    volume: playbackVolume,
                    muted: audio.muted,
                    title: replacement ? replacement.name : (titleEl ? titleEl.textContent : ''),
                    artist: replacement ? replacement.albumArtist : (artistEl ? artistEl.textContent.trim() : ''),
                    art: replacement ? replacement.albumArt : (artEl ? artEl.src : '')
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

        function setNowPlayingTitle(label, artist = '') {
            if (!titleEl) return;
            if (!artist && typeof label === 'string' && label.includes(' - ')) {
                const parts = label.split(' - ');
                artist = parts.pop() || '';
                label = parts.join(' - ');
            }
            titleEl.textContent = label;
            lastTrackLabel = label || '';
            lastTrackArtist = artist || '';
            if (artistEl) {
                artistEl.textContent = lastTrackArtist || '\u00a0';
                artistEl.hidden = !lastTrackArtist;
            }
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
            const concealMetadata = isDisplayAuxDanceTrackSrc(audio?.src);
            const artworkUrl = normalizeArtUrl(concealMetadata
                ? DISPLAY_AUX_DEVICE_ART_PATH
                : (meta.albumArt || meta.art || ''));
            const artwork = artworkUrl ? [
                { src: artworkUrl, sizes: '512x512', type: concealMetadata ? 'image/svg+xml' : 'image/png' }
            ] : [];
            const concealedTitle = concealMetadata ? displayAuxDeviceMetadataText(16, 28) : '';
            const concealedArtist = concealMetadata ? displayAuxDeviceMetadataText(10, 20) : '';
            try {
                navigator.mediaSession.metadata = new MediaMetadata({
                    title: concealMetadata ? concealedTitle : (meta.name || meta.title || 'Unknown'),
                    artist: concealMetadata ? concealedArtist : (meta.albumArtist || meta.artist || ''),
                    album: concealMetadata ? '' : (meta.albumName || meta.album || ''),
                    artwork
                });
            } catch (_) { /* no-op */ }
        };

        const bindMediaSessionActions = () => {
            if (!('mediaSession' in navigator)) return;
            try {
                navigator.mediaSession.setActionHandler('play', fadeInPlayback);
                navigator.mediaSession.setActionHandler('pause', fadeOutPlayback);
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

            trackLibrary.autoPlayedIds.add(track.id);

            audio.src = track.src;
            audio.play().catch(() => {});
            setLiveMode(false);
            setPlayIcon(true);
            if (artEl && track.albumArt) {
                artEl.src = track.albumArt;
            }
            setNowPlayingTitle(labelName, track.albumArtist || '');
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
            sidebarDebugLog('automatic track playback started');
        };

        const playAlbumTrack = (track, idx, albumMeta) => {
            if (!track) return;
            const src = track.directory || track.file_directory || '';
            if (!src) return;

            const meta = albumMeta || {};
            const name = track.name || `Track ${idx + 1}`;

            audio.src = src;
            audio.play().catch(() => {});
            sidebarDebugLog(`album track ${idx + 1} playback requested`);
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
            setNowPlayingTitle(name, meta.albumArtist || '');
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
                    if (isDisplayAuxDanceTrackSrc(audio.src)) return;
                    const src = audio.src;
                    // Derive a safe filename from the last track label
                    let baseName = [lastTrackLabel, lastTrackArtist].filter(Boolean).join(' - ') || 'track';
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

        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                cancelVolumeFade();
                fadeOutActive = false;
                audio.volume = playbackVolume;
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                audio.removeAttribute('data-toast-live');
                window.__toastStreamCandidates = [];
                setPlayIcon(false);
                setLiveMode(false);
                clearActiveTracks();
                if (tracklistEl) {
                    tracklistEl.innerHTML = '';
                    tracklistEl.style.display = 'none';
                }
                if (artEl) artEl.removeAttribute('src');
                setNowPlayingTitle('nothing selected', '');
                if (seekEl) seekEl.value = '0';
                miniPlayerEl?.classList.add('mini-inactive');
                try {
                    window.localStorage.removeItem(PLAYER_STATE_KEY);
                    if ('mediaSession' in navigator) navigator.mediaSession.metadata = null;
                } catch (_) { /* no-op */ }
                sidebarDebugLog('mini player closed');
            });
        }

        playBtn.addEventListener('click', () => {
            if (!audio.src) return;
            if (audio.paused || fadeOutActive) {
                fadeInPlayback();
            } else {
                fadeOutPlayback();
            }
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
                seekEl.disabled = isDisplayAuxDanceTrackSrc(audio.src);
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
                if (isDisplayAuxDanceTrackSrc(audio.src)) {
                    seekEl.value = String(audio.currentTime || 0);
                    return;
                }
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
                playbackVolume = vol;
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

        if (volumeWrapperEl && playerRowEl) {
            const openVolume = () => playerRowEl.classList.add('mini-player-volume-expanded');
            const closeVolume = () => {
                if (!volumeWrapperEl.matches(':hover') && !volumeWrapperEl.contains(document.activeElement)) {
                    playerRowEl.classList.remove('mini-player-volume-expanded');
                }
            };
            volumeWrapperEl.addEventListener('mouseenter', openVolume);
            volumeWrapperEl.addEventListener('mouseleave', closeVolume);
            volumeWrapperEl.addEventListener('focusin', openVolume);
            volumeWrapperEl.addEventListener('focusout', () => window.setTimeout(closeVolume, 0));
        }

        // Album grid integration: clicking entries controls the mini player.
        const bindAlbumLinks = () => {
            const albumLinks = document.querySelectorAll('.album-link');
            if (!albumLinks.length) return;

            // Rebuild track library from current album definitions
            trackLibrary.tracks = [];

            albumLinks.forEach(link => {
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

                // Metadata is rebuilt on every pass, but click handlers are bound once.
                if (link.dataset.miniPlayerBound === '1') return;
                link.dataset.miniPlayerBound = '1';

                link.addEventListener('click', (e) => {
                    e.preventDefault();

                    // Single-track releases play directly; multi-track releases
                    // use the picker so singles/remixes can expose every track.
                    if (albumType !== 'album' && tracks.length === 1) {
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
                        setNowPlayingTitle(name, albumArtist);
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

                    // For full Albums and multi-track releases: show the track picker.
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

        if (fallbackSelectionRequired) {
            const candidates = trackLibrary.tracks.filter(track => track?.src && !isDisplayAuxDanceTrackSrc(track.src));
            const replacement = candidates.length ? candidates[Math.floor(Math.random() * candidates.length)] : null;
            fallbackSelectionRequired = false;
            if (replacement) {
                audio.src = replacement.src;
                if (artEl) artEl.src = replacement.albumArt || '';
                setNowPlayingTitle(replacement.name || 'Untitled', replacement.albumArtist || '');
                setPlayIcon(false);
                updateVisibility();
                updatePlayingClass();
                savePlayerState();
            } else {
                window.localStorage.removeItem(PLAYER_STATE_KEY);
            }
        }

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
                        playbackVolume = audio.volume;
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
                        setNowPlayingTitle(state.title, state.artist || '');
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
        accountButton.setAttribute('data-tooltip', isLoggedInNow ? 'log out' : 'access your fridge.dev account');
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
                sidebarDebugLog('active chat shortcut synchronized');

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
                sidebarDebugLog('active chat shortcut request failed');
                const current = document.getElementById('sidebar-active-chat');
                if (current) current.remove();
            });
    } catch (_) { /* no-op */ }
}

window.addEventListener('DOMContentLoaded', syncActiveChatSidebarButton);

let notificationRevisionPollTimer = null;
let notificationRevisionKnown = null;
let notificationRevisionRequestActive = false;
let notificationStreamRefreshTimer = null;
let notificationInboxKnownKeys = null;
let siteNotificationToastActive = false;
let initialUnreadSummaryShown = false;
const siteNotificationToastQueue = [];
let siteNotificationAudio = null;
let siteNotificationAudioUnlocked = false;
const SITE_NOTIFICATION_VOLUME = 0.3;

function siteNotificationSoundDisabled() {
    if (document.body?.classList.contains('mobile-template')) return true;
    try {
        return localStorage.getItem('notificationSoundsEnabled') === 'false';
    } catch (_) {
        return false;
    }
}

function getSiteNotificationAudio() {
    if (siteNotificationSoundDisabled()) return null;
    if (!siteNotificationAudio) {
        siteNotificationAudio = new Audio('/resources/notification.mp3');
        siteNotificationAudio.preload = 'auto';
        siteNotificationAudio.volume = SITE_NOTIFICATION_VOLUME;
    }
    return siteNotificationAudio;
}

function unlockSiteNotificationAudio() {
    if (siteNotificationAudioUnlocked || siteNotificationSoundDisabled()) return;
    const audio = getSiteNotificationAudio();
    if (!audio) return;
    const previousVolume = audio.volume;
    audio.volume = 0;
    const playPromise = audio.play();
    if (!playPromise || typeof playPromise.then !== 'function') return;
    playPromise.then(() => {
        audio.pause();
        audio.currentTime = 0;
        audio.volume = previousVolume;
        siteNotificationAudioUnlocked = true;
    }).catch(() => {
        audio.volume = previousVolume;
    });
}

function playSiteNotificationSound() {
    if (siteNotificationSoundDisabled()) return;
    const audio = getSiteNotificationAudio();
    if (!audio) return;
    try { audio.currentTime = 0; } catch (_) { /* ignore */ }
    audio.volume = SITE_NOTIFICATION_VOLUME;
    const playPromise = audio.play();
    if (!playPromise || typeof playPromise.then !== 'function') return;
    playPromise.then(() => {
        siteNotificationAudioUnlocked = true;
    }).catch(() => {});
}

document.addEventListener('pointerdown', unlockSiteNotificationAudio, { passive: true });
document.addEventListener('keydown', unlockSiteNotificationAudio);
window.addEventListener('fridg3:new-notifications', playSiteNotificationSound);

async function markSiteNotificationsRead(keys, csrfToken = '') {
    const notificationKeys = Array.from(new Set((Array.isArray(keys) ? keys : []).map(String).filter(Boolean)));
    if (!notificationKeys.length) return;
    const params = new URLSearchParams({ view: 'inbox', keys: JSON.stringify(notificationKeys) });
    if (csrfToken) params.set('csrf_token', csrfToken);
    if (!document.getElementById('user-greeting') && typeof getFeedGuestBrowserId === 'function') {
        const guestBrowserId = getFeedGuestBrowserId();
        if (guestBrowserId) params.set('guestBrowserId', guestBrowserId);
    }
    await fetch('/api/feed-notifications', {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: params.toString()
    });
}

function showNextSiteNotificationToast() {
    if (siteNotificationToastActive || siteNotificationToastQueue.length === 0) return;
    siteNotificationToastActive = true;
    const event = siteNotificationToastQueue.shift();
    const toast = document.createElement('a');
    toast.className = 'site-notification-toast';
    toast.href = '/notifications';
    toast.setAttribute('role', 'status');

    const title = document.createElement('strong');
    const compactPreview = (value, limit) => {
        const text = String(value || '').replace(/\s+/g, ' ').trim();
        return text.length > limit ? `${text.slice(0, Math.max(0, limit - 1)).trimEnd()}…` : text;
    };
    title.textContent = compactPreview(event.title || 'new notification', 100);
    const body = document.createElement('span');
    body.textContent = compactPreview(event.body || '', 180);
    toast.append(title, body);
    document.body.append(toast);
    let swipeStartX = 0;
    let swipeStartY = 0;
    let swipeDistanceX = 0;
    let swipeDistance = 0;
    let swipePointerId = null;
    let suppressToastClick = false;

    toast.addEventListener('pointerdown', pointerEvent => {
        if (pointerEvent.pointerType !== 'touch' || !pointerEvent.isPrimary) return;
        swipeStartX = pointerEvent.clientX;
        swipeStartY = pointerEvent.clientY;
        swipeDistanceX = 0;
        swipeDistance = 0;
        swipePointerId = pointerEvent.pointerId;
        suppressToastClick = false;
        toast.style.transform = 'translate(-50%, 0)';
        toast.style.opacity = '1';
        toast.classList.add('is-swiping');
        toast.setPointerCapture?.(pointerEvent.pointerId);
    });
    toast.addEventListener('pointermove', pointerEvent => {
        if (pointerEvent.pointerId !== swipePointerId) return;
        pointerEvent.preventDefault();
        swipeDistanceX = pointerEvent.clientX - swipeStartX;
        swipeDistance = Math.min(0, pointerEvent.clientY - swipeStartY);
        if (swipeDistance < -6 || Math.abs(swipeDistanceX) > 6) suppressToastClick = true;
        toast.style.transform = `translate(calc(-50% + ${swipeDistanceX}px), ${swipeDistance}px)`;
        toast.style.opacity = String(Math.max(0.15, 1 - (Math.max(Math.abs(swipeDistanceX), Math.abs(swipeDistance)) / 110)));
    });
    const finishToastSwipe = pointerEvent => {
        if (pointerEvent.pointerId !== swipePointerId) return;
        toast.releasePointerCapture?.(pointerEvent.pointerId);
        swipePointerId = null;
        if (swipeDistance <= -32 || Math.abs(swipeDistanceX) >= 55) {
            suppressToastClick = true;
            finish();
            return;
        }
        toast.classList.add('is-swipe-resetting');
        toast.style.transform = 'translate(-50%, 0)';
        toast.style.opacity = '1';
        window.setTimeout(() => toast.classList.remove('is-swipe-resetting'), 150);
    };
    toast.addEventListener('pointerup', finishToastSwipe);
    toast.addEventListener('pointercancel', finishToastSwipe);
    toast.addEventListener('click', clickEvent => {
        clickEvent.preventDefault();
        if (suppressToastClick) {
            suppressToastClick = false;
            return;
        }
        finish();
        if (typeof loadPageIntoContent === 'function') loadPageIntoContent('/notifications', true);
        else window.location.assign('/notifications');
    });

    let finished = false;
    let dismissTimer = null;
    let visibleCountdownStartedAt = 0;
    let visibleCountdownRemaining = 6200;
    const syncToastVisibilityCountdown = () => {
        if (finished) return;
        if (document.hidden) {
            toast.style.animationPlayState = 'paused';
            if (dismissTimer) {
                window.clearTimeout(dismissTimer);
                dismissTimer = null;
                visibleCountdownRemaining = Math.max(0, visibleCountdownRemaining - (performance.now() - visibleCountdownStartedAt));
            }
            return;
        }
        toast.style.animationPlayState = 'running';
        if (dismissTimer) return;
        visibleCountdownStartedAt = performance.now();
        dismissTimer = window.setTimeout(finish, visibleCountdownRemaining);
    };
    const finish = () => {
        if (finished) return;
        finished = true;
        if (dismissTimer) window.clearTimeout(dismissTimer);
        document.removeEventListener('visibilitychange', syncToastVisibilityCountdown);
        toast.remove();
        siteNotificationToastActive = false;
        showNextSiteNotificationToast();
    };
    toast.addEventListener('animationend', finish, { once: true });
    document.addEventListener('visibilitychange', syncToastVisibilityCountdown);
    syncToastVisibilityCountdown();
}

function notificationKeysForVisitedPage(events, visitedUrl) {
    let visitedPath = '';
    try { visitedPath = new URL(visitedUrl || window.location.href, window.location.href).pathname.replace(/\/+$/, ''); } catch (_) { return []; }
    if (!visitedPath.startsWith('/feed/posts/')) return [];
    return (Array.isArray(events) ? events : []).filter(event => {
        if (!event || !event.unread || !event.key || !event.url) return false;
        try { return new URL(event.url, window.location.href).pathname.replace(/\/+$/, '') === visitedPath; } catch (_) { return false; }
    }).map(event => String(event.key));
}

function observeNewInboxNotifications(events) {
    const currentEvents = Array.isArray(events) ? events : [];
    const currentKeys = currentEvents.map(event => String(event && event.key || '')).filter(Boolean);
    if (notificationInboxKnownKeys === null) {
        notificationInboxKnownKeys = new Set(currentKeys);
        return false;
    }

    const newEvents = [];
    let reachedKnownEvent = false;
    for (const event of currentEvents) {
        const key = String(event && event.key || '');
        if (!key) continue;
        if (notificationInboxKnownKeys.has(key)) {
            reachedKnownEvent = true;
            break;
        }
        if (event.unread) newEvents.push(event);
    }
    if (!reachedKnownEvent) newEvents.length = 0;
    currentKeys.forEach(key => notificationInboxKnownKeys.add(key));
    if (notificationInboxKnownKeys.size > 500) {
        notificationInboxKnownKeys = new Set(Array.from(notificationInboxKnownKeys).slice(-500));
    }
    if (!newEvents.length) return false;
    newEvents.slice().reverse().forEach(event => siteNotificationToastQueue.push(event));
    showNextSiteNotificationToast();
    return true;
}

function notificationInboxUrl() {
    const params = new URLSearchParams({ view: 'inbox' });
    if (!document.getElementById('user-greeting') && typeof getFeedGuestBrowserId === 'function') {
        const guestBrowserId = getFeedGuestBrowserId();
        if (guestBrowserId) params.set('guestBrowserId', guestBrowserId);
    }
    return `/api/feed-notifications?${params.toString()}`;
}

function applyNotificationTitleCount(unreadCount) {
    const cleanTitle = document.title.replace(/^\(\d+\)\s*/, '');
    document.title = unreadCount > 0 ? `(${unreadCount}) ${cleanTitle}` : cleanTitle;
}

function updateMobileMenuNotificationBadge(unreadCount) {
    const menuButton = document.getElementById('show-sidebar');
    if (!menuButton || !document.body.classList.contains('mobile-template')) return;
    const count = Math.max(0, Number.parseInt(unreadCount, 10) || 0);
    let badge = menuButton.querySelector('.mobile-menu-notification-badge');
    if (count === 0) {
        badge?.remove();
        return;
    }
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'mobile-menu-notification-badge';
        badge.setAttribute('aria-hidden', 'true');
        menuButton.appendChild(badge);
    }
    badge.textContent = count > 99 ? '99+' : String(count);
}

function syncNotificationsSidebarButton(options = {}) {
    try {
        const sidebarEl = document.getElementById('sidebar');
        if (!sidebarEl || !window.fetch) return;
        fetch(notificationInboxUrl(), {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(response => {
                if (!response.ok) throw new Error('notifications unavailable');
                return response.json();
            })
            .then(data => {
                let unreadCount = Math.max(0, Number.parseInt(data.unreadCount, 10) || 0);
                const loggedIn = data.loggedIn === true;
                const current = document.getElementById('sidebar-notifications');
                const inboxEvents = Array.isArray(data.events)
                    ? data.events.map(event => ({ ...event, csrfToken: data.csrfToken || '' }))
                    : [];
                const visitedKeys = notificationKeysForVisitedPage(inboxEvents, options.visitedUrl);
                if (visitedKeys.length) {
                    const visitedKeySet = new Set(visitedKeys);
                    inboxEvents.forEach(event => {
                        if (visitedKeySet.has(String(event.key || ''))) event.unread = false;
                    });
                    unreadCount = Math.max(0, unreadCount - visitedKeySet.size);
                    markSiteNotificationsRead(visitedKeys, data.csrfToken || '').catch(() => {});
                }
                const hasNewNotifications = observeNewInboxNotifications(inboxEvents);
                if (hasNewNotifications) window.dispatchEvent(new CustomEvent('fridg3:new-notifications'));
                if (options.initialPageLoad === true && !initialUnreadSummaryShown && unreadCount > 0) {
                    initialUnreadSummaryShown = true;
                    siteNotificationToastQueue.push({
                        title: 'you have unread notifications',
                        body: `${unreadCount} unread notification${unreadCount === 1 ? '' : 's'}`,
                        url: '/notifications'
                    });
                    showNextSiteNotificationToast();
                }
                applyNotificationTitleCount(unreadCount);
                updateMobileMenuNotificationBadge(unreadCount);
                if (!loggedIn && unreadCount === 0) {
                    if (current) current.remove();
                    return;
                }

                const link = current || document.createElement('a');
                link.id = 'sidebar-notifications';
                link.href = '/notifications';
                link.setAttribute('data-tooltip', 'open your notifications');
                link.setAttribute('aria-label', `${unreadCount} unread notification${unreadCount === 1 ? '' : 's'}`);
                link.classList.toggle('sidebar-notifications-unread', unreadCount > 0);
                link.innerHTML = `<i class="fa-solid fa-bell"></i><span class="sidebar-notification-label">notifications</span><strong class="sidebar-notification-count">${unreadCount}</strong>`;
                const footer = document.getElementById('sidebar-footer');
                if (footer && (link.parentNode !== sidebarEl || link.nextElementSibling !== footer)) {
                    sidebarEl.insertBefore(link, footer);
                } else if (!current && !footer) {
                    sidebarEl.append(link);
                }
                if (hasNewNotifications) {
                    link.classList.remove('sidebar-notifications-new');
                    void link.offsetWidth;
                    link.classList.add('sidebar-notifications-new');
                    window.setTimeout(() => link.classList.remove('sidebar-notifications-new'), 2200);
                }
            })
            .catch(() => {
                const current = document.getElementById('sidebar-notifications');
                if (current && !document.getElementById('user-greeting')) current.remove();
            });
    } catch (_) { /* no-op */ }
}

window.syncNotificationsSidebarButton = syncNotificationsSidebarButton;
async function checkNotificationRevision() {
    if (notificationRevisionRequestActive || !window.fetch) return;
    notificationRevisionRequestActive = true;
    try {
        const response = await fetch('/api/notification-revision', {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) return;
        const data = await response.json();
        const revision = String(data && data.revision || '0');
        if (notificationRevisionKnown === null) {
            notificationRevisionKnown = revision;
            return;
        }
        if (revision === notificationRevisionKnown) return;
        notificationRevisionKnown = revision;
        window.clearTimeout(notificationStreamRefreshTimer);
        notificationStreamRefreshTimer = window.setTimeout(() => syncNotificationsSidebarButton(), 80);
    } catch (_) { /* retry on the next lightweight check */ }
    finally { notificationRevisionRequestActive = false; }
}

window.addEventListener('DOMContentLoaded', async () => {
    await checkNotificationRevision();
    syncNotificationsSidebarButton({ initialPageLoad: true });
    if (notificationRevisionPollTimer === null) {
        notificationRevisionPollTimer = window.setInterval(checkNotificationRevision, 10000);
    }
});
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        checkNotificationRevision();
    }
});

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
        sidebarDebugLog('live stream playback requested');
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
        const artistEl = document.getElementById('mini-player-artist');
        if (artistEl) {
            artistEl.textContent = 'toast radio';
            artistEl.hidden = false;
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
        sidebarDebugLog(`live stream started with ${candidates.length} source candidate(s)`);

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
                artist: artistEl ? artistEl.textContent : '',
                art: streamArt
            };
            window.localStorage.setItem('miniPlayerStateV1', JSON.stringify(state));
        } catch (_) { /* no-op */ }
    } catch (err) {
        console.error('Failed to start listen-along:', err);
        sidebarDebugLog(`listen-along failed: ${err.message || 'unknown error'}`);
    }
}

function setToastLiveControls(isLive) {
    const miniPlayerEl = document.getElementById('mini-player');
    const seekEl = document.getElementById('mini-player-seek');
    const downloadBtn = document.getElementById('mini-player-download');
    if (miniPlayerEl) {
        miniPlayerEl.classList.toggle('live-stream', !!isLive);
        if (isLive) miniPlayerEl.classList.remove('mini-inactive');
    }
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
    } catch (error) {
        sidebarDebugLog(`live stream failed: ${error.message || 'unknown error'}`);
    }
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
    const toggleSidebarFromMenuButton = function() {
        if (isMobileTemplateActive()) {
            setSidebarVisible(document.body.classList.contains('sidebar-is-hidden'));
            return;
        }
        setSidebarVisible(true);
    };
    showSidebarBtn.addEventListener('click', toggleSidebarFromMenuButton);
    showSidebarBtn.addEventListener('keydown', function(event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        toggleSidebarFromMenuButton();
    });
}

if (mobileMenuBackdrop) {
    mobileMenuBackdrop.addEventListener('click', function() {
        setSidebarVisible(false);
    });
}

// Detect logged-in state based on presence of the Logout footer link
