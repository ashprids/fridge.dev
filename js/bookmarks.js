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

