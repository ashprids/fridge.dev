(function() {
    const debugLog = message => window.fridg3DebugClientLog?.(`[off-topic archive] ${message}`);
    'use strict';

    // Render the #off-topic archive in a Discord-like view.
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
            let filteredMessages = [];
            let renderedCount = 0;
            let sortOrder = 'desc'; // 'desc' newest to oldest, 'asc' oldest to newest
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
                lines.forEach(function(line) {
                    let cleaned = stripTenorLinks(line);
                    cleaned = stripGifLinks(cleaned);
                    if (cleaned === '') return;
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

                if (/^#?[0-9a-f]{6}$/.test(lower)) {
                    return lower.startsWith('#') ? lower : '#' + lower;
                }

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
                sortBtn.textContent = sortOrder === 'desc' ? 'Sort: Newest \u2192 Oldest' : 'Sort: Oldest \u2192 Newest';
                applyFilter(searchInput.value || '');
            });

            statusEl.textContent = 'Loading archive...';
            debugLog('archive request started');

            fetch(ARCHIVE_URL, { cache: 'default' })
                .then(function(res) {
                    if (!res.ok) throw new Error('failed to load archive');
                    return res.json();
                })
                .then(function(data) {
                    rawMessages = (data && Array.isArray(data.messages)) ? data.messages : [];
                    debugLog(`archive loaded (${rawMessages.length} messages)`);
                    filteredMessages = sortMessages(rawMessages, sortOrder);
                    renderChunk(true);
                    applyTwemoji();
                })
                .catch(function() {
                    debugLog('archive request failed');
                    errorEl.style.display = 'block';
                    errorEl.textContent = 'Could not load archive right now. Please try again later.';
                    statusEl.textContent = '';
                    loadMoreBtn.style.display = 'none';
                });
        } catch (error) {
            debugLog(`archive initialization failed: ${error.message || 'unknown error'}`);
        }
    }

    window.fridg3InitOffTopicArchive = initOffTopicArchive;
    initOffTopicArchive();
}());
