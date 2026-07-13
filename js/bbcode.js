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

    if (bbcodeVoiceBtn && bbcodeVoiceInput && bbcodeVoiceRecorder && !bbcodeTextbox.disabled) {
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
