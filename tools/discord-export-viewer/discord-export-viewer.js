(function() {
    'use strict';

    const debugLog = message => window.fridgeDebugClientLog?.(`[discord export viewer] ${message}`);

    function initDiscordExportViewer() {
        try {
            const path = ((window.location && window.location.pathname) || '/').replace(/\/+$/, '') || '/';
            if (!path.startsWith('/tools/discord-export-viewer')) return;

            const root = document.getElementById('discord-export-viewer');
            const fileInput = document.getElementById('discord-export-files');
            const fileNameEl = document.getElementById('discord-export-file-name');
            const controlsEl = document.getElementById('discord-export-controls');
            const messagesEl = document.getElementById('discord-export-messages');
            const searchInput = document.getElementById('discord-export-search');
            const afterInput = document.getElementById('discord-export-after');
            const beforeInput = document.getElementById('discord-export-before');
            const statusEl = document.getElementById('discord-export-status');
            const sortBtn = document.getElementById('discord-export-sort');
            const imagesBtn = document.getElementById('discord-export-images');
            const videosBtn = document.getElementById('discord-export-videos');
            const loadMoreBtn = document.getElementById('discord-export-load-more');
            const errorEl = document.getElementById('discord-export-error');

            if (!root || !fileInput || !fileNameEl || !controlsEl || !messagesEl || !searchInput || !afterInput || !beforeInput || !statusEl || !sortBtn || !imagesBtn || !videosBtn || !loadMoreBtn || !errorEl) return;
            if (root.dataset.bound === '1') return;
            root.dataset.bound = '1';

            const PAGE_SIZE = 120;
            const DEFAULT_AVATAR = 'https://cdn.discordapp.com/embed/avatars/0.png';
            let rawMessages = [];
            let filteredMessages = [];
            let renderedCount = 0;
            let sortOrder = 'desc';
            let imagesOnly = false;
            let videosOnly = false;
            let searchActive = false;

            function safeText(value) { return typeof value === 'string' ? value : ''; }
            function safeRemoteUrl(value) {
                try {
                    const url = new URL(safeText(value));
                    return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : '';
                } catch (_) { return ''; }
            }
            function escapeHtml(str) {
                return safeText(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            function formatInlineMarkdown(str) {
                let out = escapeHtml(str);
                out = out.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                return out.replace(/(^|[^*])\*(?!\*)([^*]+?)\*(?!\*)/g, (_, prefix, content) => prefix + '<em>' + content + '</em>');
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
            function getAuthorName(msg) {
                const author = msg && msg.author ? msg.author : {};
                return safeText(author.nickname || author.name || 'Unknown');
            }
            function normalizeColorValue(value) {
                const raw = safeText(value).trim().toLowerCase();
                if (/^#?[0-9a-f]{6}$/.test(raw)) return raw.startsWith('#') ? raw : '#' + raw;
                const match = raw.match(/^rgb\s*\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/);
                if (!match) return null;
                const clamp = number => Math.max(0, Math.min(255, parseInt(number, 10)));
                return `rgb(${clamp(match[1])}, ${clamp(match[2])}, ${clamp(match[3])})`;
            }
            function getAuthorColor(msg) {
                const roles = msg && msg.author && Array.isArray(msg.author.roles) ? msg.author.roles : [];
                const role = roles.find(item => item && safeText(item.color).trim() && safeText(item.color).toLowerCase() !== 'null');
                const color = normalizeColorValue(role && role.color);
                return color === '#8799ae' || color === 'rgb(135, 153, 174)' ? '#cacaca' : color;
            }
            function formatTimestamp(value) {
                const date = new Date(value);
                return isNaN(date.getTime()) ? '' : date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
            }
            function extractMatches(text, regex, group) {
                const values = [];
                let match;
                while ((match = regex.exec(text || '')) !== null) {
                    const value = match[group];
                    if (value && values.indexOf(value) === -1) values.push(value);
                }
                return values;
            }
            function extractTenorIds(text) { return extractMatches(text, /(https?:\/\/(?:www\.)?tenor\.com\/[^\s]*?-)(\d+)(?=[^\d]|$)/gi, 2); }
            function extractGifLinks(text) { return extractMatches(text, /(https?:\/\/\S+?\.gif)(?=\s|$)/gi, 1); }
            function makeContentNode(msg) {
                const node = document.createElement('div');
                node.className = 'discord-message-content';
                node.innerHTML = safeText(msg && msg.content).split('\n').map(function(line) {
                    return line.replace(/https?:\/\/(?:www\.)?tenor\.com\/\S+/gi, '').replace(/https?:\/\/\S+?\.gif(?=\s|$)/gi, '').trim();
                }).filter(Boolean).map(formatInlineMarkdown).join('<br>');
                return node;
            }
            function appendRemoteImage(url, name, wrap) {
                const safeUrl = safeRemoteUrl(url);
                if (!safeUrl) return;
                const image = document.createElement('img');
                image.className = 'discord-attachment-image';
                image.src = safeUrl;
                image.alt = name || 'attachment';
                image.loading = 'lazy';
                image.referrerPolicy = 'no-referrer';
                image.onerror = () => image.remove();
                wrap.appendChild(image);
            }
            function appendEmbeds(msg, body) {
                const text = safeText(msg && msg.content);
                const tenorIds = extractTenorIds(text);
                if (tenorIds.length) {
                    const wrap = document.createElement('div');
                    wrap.className = 'discord-tenor-wrap';
                    tenorIds.forEach(function(id) {
                        const outer = document.createElement('div');
                        outer.className = 'discord-tenor';
                        const iframe = document.createElement('iframe');
                        iframe.src = 'https://tenor.com/embed/' + encodeURIComponent(id);
                        iframe.allowFullscreen = true;
                        iframe.loading = 'lazy';
                        iframe.referrerPolicy = 'no-referrer';
                        outer.appendChild(iframe);
                        wrap.appendChild(outer);
                    });
                    body.appendChild(wrap);
                }
                const gifs = extractGifLinks(text);
                if (gifs.length) {
                    const wrap = document.createElement('div');
                    wrap.className = 'discord-attachments';
                    gifs.forEach(url => appendRemoteImage(url, 'gif', wrap));
                    if (wrap.children.length) body.appendChild(wrap);
                }
            }
            function extensionMatches(value, extensions) {
                const clean = safeText(value).split('?')[0].split('#')[0];
                return extensions.test(clean);
            }
            function hasImageAttachment(msg) {
                return Boolean(msg && Array.isArray(msg.attachments) && msg.attachments.some(function(attachment) {
                    const url = attachment && (attachment.url || attachment.proxyUrl);
                    const name = attachment && (attachment.fileName || attachment.filename);
                    return extensionMatches(url, /\.(png|jpe?g|gif|webp|bmp|tiff)$/i) || extensionMatches(name, /\.(png|jpe?g|gif|webp|bmp|tiff)$/i);
                }));
            }
            function hasVideoAttachment(msg) {
                return Boolean(msg && Array.isArray(msg.attachments) && msg.attachments.some(function(attachment) {
                    const url = attachment && (attachment.url || attachment.proxyUrl);
                    const name = attachment && (attachment.fileName || attachment.filename);
                    return extensionMatches(url, /\.(mp4|mov|webm)$/i) || extensionMatches(name, /\.(mp4|mov|webm)$/i);
                }));
            }
            function renderAttachments(msg, body) {
                if (!msg || !Array.isArray(msg.attachments)) return;
                const wrap = document.createElement('div');
                wrap.className = 'discord-attachments';
                msg.attachments.forEach(function(attachment) {
                    const rawUrl = attachment && (attachment.url || attachment.proxyUrl);
                    const url = safeRemoteUrl(rawUrl);
                    if (!url) return;
                    const name = safeText(attachment && (attachment.fileName || attachment.filename)) || url;
                    if (extensionMatches(rawUrl, /\.(png|jpe?g|gif|webp|bmp|tiff)$/i) || extensionMatches(name, /\.(png|jpe?g|gif|webp|bmp|tiff)$/i)) {
                        appendRemoteImage(url, name, wrap);
                    } else if (extensionMatches(rawUrl, /\.(mp4|mov|webm)$/i) || extensionMatches(name, /\.(mp4|mov|webm)$/i)) {
                        const video = document.createElement('video');
                        video.className = 'discord-attachment-video';
                        video.src = url;
                        video.controls = true;
                        video.preload = 'metadata';
                        video.playsInline = true;
                        video.referrerPolicy = 'no-referrer';
                        video.onerror = () => video.remove();
                        wrap.appendChild(video);
                    } else {
                        const link = document.createElement('a');
                        link.href = url;
                        link.textContent = name;
                        link.target = '_blank';
                        link.rel = 'noreferrer noopener';
                        wrap.appendChild(link);
                    }
                });
                if (wrap.children.length) body.appendChild(wrap);
            }
            function createMessageEl(msg) {
                const row = document.createElement('div');
                row.className = 'discord-message';
                row.dataset.id = safeText(msg && msg.id);
                const avatar = document.createElement('img');
                avatar.className = 'discord-avatar';
                avatar.src = safeRemoteUrl(msg && msg.author && msg.author.avatarUrl) || DEFAULT_AVATAR;
                avatar.alt = getAuthorName(msg);
                avatar.loading = 'lazy';
                avatar.referrerPolicy = 'no-referrer';
                avatar.onerror = function() { avatar.onerror = null; avatar.src = DEFAULT_AVATAR; };
                const body = document.createElement('div');
                body.className = 'discord-body';
                const header = document.createElement('div');
                header.className = 'discord-header';
                const author = document.createElement('span');
                author.className = 'discord-author';
                author.textContent = getAuthorName(msg);
                const color = getAuthorColor(msg);
                if (color) author.style.color = color;
                const timestamp = document.createElement('span');
                timestamp.className = 'discord-timestamp';
                timestamp.textContent = formatTimestamp(msg && msg.timestamp);
                timestamp.dataset.exactDatetime = safeText(msg && msg.timestamp);
                header.append(author, timestamp);
                if (searchActive || imagesOnly) {
                    const jumpButton = document.createElement('button');
                    jumpButton.className = 'discord-message-jump';
                    jumpButton.type = 'button';
                    jumpButton.textContent = 'Show in list';
                    jumpButton.addEventListener('click', function() { jumpToMessage(msg); });
                    header.appendChild(jumpButton);
                }
                body.append(header, makeContentNode(msg));
                appendEmbeds(msg, body);
                renderAttachments(msg, body);
                row.append(avatar, body);
                return row;
            }
            function updateStatus() {
                statusEl.textContent = filteredMessages.length ? `${renderedCount} of ${filteredMessages.length} messages` : 'No messages found';
            }
            function renderChunk(reset) {
                if (reset) { messagesEl.innerHTML = ''; renderedCount = 0; }
                filteredMessages.slice(renderedCount, renderedCount + PAGE_SIZE).forEach(msg => messagesEl.appendChild(createMessageEl(msg)));
                if (typeof window.initTooltips === 'function') window.initTooltips();
                renderedCount = Math.min(renderedCount + PAGE_SIZE, filteredMessages.length);
                loadMoreBtn.style.display = renderedCount < filteredMessages.length ? 'block' : 'none';
                updateStatus();
                if (window.twemoji) window.twemoji.parse(root);
            }
            function applyFilter(term) {
                const needle = safeText(term).trim().toLowerCase();
                searchActive = Boolean(needle);
                const afterTime = afterInput.value ? new Date(afterInput.value + 'T00:00:00').getTime() : null;
                const beforeTime = beforeInput.value ? new Date(beforeInput.value + 'T23:59:59.999').getTime() : null;
                const base = rawMessages.filter(function(msg) {
                    const matchesSearch = !needle || safeText(msg && msg.content).toLowerCase().includes(needle) || getAuthorName(msg).toLowerCase().includes(needle);
                    const messageTime = new Date(msg && msg.timestamp).getTime();
                    const matchesDates = (afterTime === null || (!isNaN(messageTime) && messageTime >= afterTime))
                        && (beforeTime === null || (!isNaN(messageTime) && messageTime <= beforeTime));
                    return matchesSearch && matchesDates && (!imagesOnly || hasImageAttachment(msg)) && (!videosOnly || hasVideoAttachment(msg));
                });
                filteredMessages = sortMessages(base, sortOrder);
                renderChunk(true);
            }
            function jumpToMessage(msg) {
                searchInput.value = '';
                imagesOnly = false;
                imagesBtn.setAttribute('aria-pressed', 'false');
                imagesBtn.textContent = 'Images only: Off';
                applyFilter('');
                const targetIndex = filteredMessages.indexOf(msg);
                if (targetIndex < 0) return;
                while (renderedCount <= targetIndex) renderChunk(false);
                const target = messagesEl.children[targetIndex];
                if (!target) return;
                target.classList.add('discord-message-target');
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                window.setTimeout(() => target.classList.remove('discord-message-target'), 1800);
            }
            let filterTimer;
            searchInput.addEventListener('input', event => {
                clearTimeout(filterTimer);
                filterTimer = setTimeout(() => applyFilter(event.target.value), 200);
            });
            afterInput.addEventListener('change', () => applyFilter(searchInput.value));
            beforeInput.addEventListener('change', () => applyFilter(searchInput.value));
            loadMoreBtn.addEventListener('click', () => renderChunk(false));
            sortBtn.addEventListener('click', function() {
                sortOrder = sortOrder === 'desc' ? 'asc' : 'desc';
                sortBtn.textContent = sortOrder === 'desc' ? 'Sort: Newest → Oldest' : 'Sort: Oldest → Newest';
                applyFilter(searchInput.value);
            });
            imagesBtn.addEventListener('click', function() {
                imagesOnly = !imagesOnly;
                if (imagesOnly) videosOnly = false;
                imagesBtn.setAttribute('aria-pressed', imagesOnly ? 'true' : 'false');
                imagesBtn.textContent = imagesOnly ? 'Images only: On' : 'Images only: Off';
                videosBtn.setAttribute('aria-pressed', 'false');
                videosBtn.textContent = 'Videos only: Off';
                applyFilter(searchInput.value);
            });
            videosBtn.addEventListener('click', function() {
                videosOnly = !videosOnly;
                if (videosOnly) imagesOnly = false;
                videosBtn.setAttribute('aria-pressed', videosOnly ? 'true' : 'false');
                videosBtn.textContent = videosOnly ? 'Videos only: On' : 'Videos only: Off';
                imagesBtn.setAttribute('aria-pressed', 'false');
                imagesBtn.textContent = 'Images only: Off';
                applyFilter(searchInput.value);
            });
            fileInput.addEventListener('change', async function() {
                const files = Array.from(fileInput.files || []);
                if (!files.length) return;
                errorEl.style.display = 'none';
                controlsEl.hidden = true;
                messagesEl.innerHTML = '';
                loadMoreBtn.style.display = 'none';
                fileNameEl.textContent = files.length === 1 ? files[0].name : `${files.length} files selected`;
                try {
                    const exports = await Promise.all(files.map(async function(file) {
                        const data = JSON.parse(await file.text());
                        const messages = Array.isArray(data) ? data : data && data.messages;
                        if (!Array.isArray(messages)) throw new Error(`${file.name} does not contain a messages array.`);
                        return messages;
                    }));
                    rawMessages = exports.flat();
                    filteredMessages = sortMessages(rawMessages, sortOrder);
                    controlsEl.hidden = false;
                    searchInput.value = '';
                    afterInput.value = '';
                    beforeInput.value = '';
                    applyFilter('');
                    debugLog(`loaded ${files.length} local file(s) with ${rawMessages.length} messages`);
                } catch (error) {
                    rawMessages = [];
                    filteredMessages = [];
                    errorEl.textContent = error instanceof SyntaxError ? 'One of the selected files is not valid JSON.' : (error.message || 'Could not read the selected file.');
                    errorEl.style.display = 'block';
                    debugLog(`local file read failed: ${error.message || 'unknown error'}`);
                }
            });
        } catch (error) {
            debugLog(`initialization failed: ${error.message || 'unknown error'}`);
        }
    }

    window.fridgeInitDiscordExportViewer = initDiscordExportViewer;
    initDiscordExportViewer();
}());
