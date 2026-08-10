(function () {
    function guestBrowserId() {
        return typeof getFeedGuestBrowserId === 'function' ? getFeedGuestBrowserId() : '';
    }

    function inboxUrl() {
        const params = new URLSearchParams({ view: 'inbox' });
        const page = Math.max(1, Number.parseInt(new URLSearchParams(window.location.search).get('page'), 10) || 1);
        params.set('page', String(page));
        if (!document.getElementById('user-greeting')) {
            const guestId = guestBrowserId();
            if (guestId) params.set('guestBrowserId', guestId);
        }
        return `/api/feed-notifications?${params.toString()}`;
    }

    function notificationDate(rawDate) {
        const value = String(rawDate || '').trim();
        const localMatch = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
        const parsed = localMatch
            ? new Date(
                Number(localMatch[1]),
                Number(localMatch[2]) - 1,
                Number(localMatch[3]),
                Number(localMatch[4]),
                Number(localMatch[5]),
                Number(localMatch[6] || 0)
            )
            : new Date(value);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function notificationDateLabel(date) {
        const elapsedSeconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
        if (elapsedSeconds < 60) return 'just now';
        if (elapsedSeconds < 3600) {
            const minutes = Math.floor(elapsedSeconds / 60);
            return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
        }
        if (elapsedSeconds < 86400) {
            const hours = Math.floor(elapsedSeconds / 3600);
            return `${hours} hour${hours === 1 ? '' : 's'} ago`;
        }
        if (elapsedSeconds <= 7 * 86400) {
            const days = Math.floor(elapsedSeconds / 86400);
            return `${days} day${days === 1 ? '' : 's'} ago`;
        }
        return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function notificationCard(event) {
        const link = document.createElement('a');
        link.className = `notification-card${event.unread ? ' notification-card-unread' : ''}`;
        link.href = event.url || '/notifications';
        link.dataset.notificationKey = event.key || '';
        link.dataset.noSpa = '1';
        link.draggable = false;

        const heading = document.createElement('strong');
        if (event.actor && event.action) {
            const actor = document.createElement(event.actorIsGuest ? 'em' : 'span');
            actor.className = event.actorIsGuest ? 'notification-actor notification-actor-guest' : 'notification-actor';
            actor.textContent = event.actorIsGuest ? String(event.actor) : `@${String(event.actor).replace(/^@+/, '')}`;
            heading.append(actor, ` ${event.action}`);
        } else {
            heading.textContent = event.title || 'fridge.dev notification';
        }
        const body = document.createElement('div');
        body.className = 'notification-card-body';
        if (event.bodyHtml) body.innerHTML = event.bodyHtml;
        else body.textContent = event.body || '';
        window.requestAnimationFrame(() => {
            if (body.scrollHeight <= body.clientHeight + 1) return;
            body.classList.add('notification-card-body-truncated');
        });
        const date = document.createElement('time');
        date.className = 'notification-card-date';
        const parsedDate = notificationDate(event.date);
        if (parsedDate) {
            date.dateTime = parsedDate.toISOString();
            date.textContent = notificationDateLabel(parsedDate);
            date.dataset.tooltip = parsedDate.toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'medium' });
        } else {
            date.textContent = event.date || '';
        }

        const remove = document.createElement('span');
        remove.className = 'notification-card-remove';
        remove.setAttribute('role', 'button');
        remove.setAttribute('tabindex', '0');
        remove.setAttribute('aria-label', 'remove notification');
        remove.dataset.tooltip = 'remove notification';
        remove.textContent = '×';

        link.append(heading, body, date, remove);
        return link;
    }

    function pagination(currentPage, totalPages) {
        if (totalPages <= 1) return document.createDocumentFragment();
        const nav = document.createElement('nav');
        nav.className = 'guestbook-pagination content-pagination notifications-pagination';
        nav.setAttribute('aria-label', 'notification pages');

        const addLink = (label, page, className = '') => {
            const link = document.createElement('a');
            link.className = `guestbook-page-btn${className ? ` ${className}` : ''}`;
            link.href = `/notifications?page=${page}`;
            link.textContent = label;
            nav.append(link);
        };
        if (currentPage > 1) addLink('‹', currentPage - 1, 'pagination-arrow');
        const pages = Array.from(new Set([1, currentPage - 1, currentPage, currentPage + 1, totalPages]))
            .filter(page => page >= 1 && page <= totalPages)
            .sort((a, b) => a - b);
        let previousPage = 0;
        for (const page of pages) {
            if (previousPage && page - previousPage > 1) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'pagination-ellipsis';
                ellipsis.textContent = '…';
                nav.append(ellipsis);
            }
            if (page === currentPage) {
                const current = document.createElement('span');
                current.className = 'guestbook-page-btn current';
                current.setAttribute('aria-current', 'page');
                current.textContent = String(page);
                nav.append(current);
            } else {
                addLink(String(page), page);
            }
            previousPage = page;
        }
        if (currentPage < totalPages) addLink('›', currentPage + 1, 'pagination-arrow');
        return nav;
    }

    async function markRead(page, keys, markAll = false) {
        if (!keys.length && !markAll) return;
        const params = new URLSearchParams({ view: 'inbox', keys: JSON.stringify(keys) });
        if (markAll) params.set('markAll', '1');
        const csrf = page.dataset.csrfToken || '';
        if (document.getElementById('user-greeting') && csrf) params.set('csrf_token', csrf);
        if (!document.getElementById('user-greeting')) {
            const guestId = guestBrowserId();
            if (guestId) params.set('guestBrowserId', guestId);
        }
        await fetch('/api/feed-notifications', {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: params.toString(),
        });
    }

    async function dismiss(page, keys, dismissAll = false) {
        const params = new URLSearchParams({ view: 'inbox', keys: JSON.stringify(keys), dismiss: '1' });
        if (dismissAll) params.set('dismissAll', '1');
        const csrf = page.dataset.csrfToken || '';
        if (document.getElementById('user-greeting') && csrf) params.set('csrf_token', csrf);
        if (!document.getElementById('user-greeting')) params.set('guestBrowserId', guestBrowserId());
        const response = await fetch('/api/feed-notifications', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: params.toString(),
        });
        if (!response.ok) throw new Error('notification removal failed');
    }

    async function initNotificationsPage() {
        const page = document.querySelector('[data-notifications-page]');
        if (!page || page.dataset.initialized === '1') return;
        page.dataset.initialized = '1';
        const list = page.querySelector('[data-notifications-list]');
        const status = page.querySelector('[data-notifications-status]');
        const paginationWrap = page.querySelector('[data-notifications-pagination]');
        const markAllButton = page.querySelector('[data-notifications-mark-all]');
        const removeAllButton = page.querySelector('[data-notifications-remove-all]');

        try {
            const response = await fetch(inboxUrl(), {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error('notification request failed');
            const data = await response.json();
            const events = data && Array.isArray(data.events) ? data.events : [];
            list.replaceChildren(...events.map(notificationCard));
            if (typeof window.initTooltips === 'function') window.initTooltips();
            const paginationData = data && data.pagination ? data.pagination : {};
            const totalEvents = Math.max(0, Number.parseInt(paginationData.totalEvents, 10) || events.length);
            const currentPage = Math.max(1, Number.parseInt(paginationData.page, 10) || 1);
            const totalPages = Math.max(1, Number.parseInt(paginationData.totalPages, 10) || 1);
            status.hidden = totalEvents > 0;
            status.textContent = totalEvents > 0 ? '' : 'you have no notifications.';
            if (markAllButton) markAllButton.disabled = !(Number.parseInt(data.unreadCount, 10) > 0);
            if (removeAllButton) removeAllButton.disabled = totalEvents === 0;
            paginationWrap.replaceChildren(pagination(currentPage, totalPages));

            const updateAfterCardRemoval = () => {
                if (list.children.length === 0 && totalPages === 1) {
                    if (removeAllButton) removeAllButton.disabled = true;
                    if (markAllButton) markAllButton.disabled = true;
                    status.hidden = false;
                    status.textContent = 'you have no notifications.';
                }
                if (typeof window.syncNotificationsSidebarButton === 'function') window.syncNotificationsSidebarButton();
            };

            const persistCardRemoval = async card => {
                await dismiss(page, [card.dataset.notificationKey]);
                card.remove();
                updateAfterCardRemoval();
            };

            const bindCardDragDismiss = card => {
                let pointerId = null;
                let startX = 0;
                let startY = 0;
                let distanceX = 0;
                let dragging = false;

                card.addEventListener('dragstart', event => event.preventDefault());
                card.addEventListener('pointerdown', event => {
                    if (!event.isPrimary || event.button !== 0 || event.target.closest('.notification-card-remove')) return;
                    pointerId = event.pointerId;
                    startX = event.clientX;
                    startY = event.clientY;
                    distanceX = 0;
                    dragging = false;
                });
                card.addEventListener('pointermove', event => {
                    if (event.pointerId !== pointerId) return;
                    const deltaX = event.clientX - startX;
                    const deltaY = event.clientY - startY;
                    if (!dragging) {
                        if (Math.abs(deltaY) > 8 && Math.abs(deltaY) >= Math.abs(deltaX)) {
                            pointerId = null;
                            return;
                        }
                        if (Math.abs(deltaX) <= 8 || Math.abs(deltaX) <= Math.abs(deltaY)) return;
                        dragging = true;
                        card.classList.add('notification-card-dragging');
                        card.setPointerCapture?.(event.pointerId);
                    }
                    event.preventDefault();
                    distanceX = deltaX;
                    card.style.transform = `translateX(${distanceX}px)`;
                    card.style.opacity = String(Math.max(0.18, 1 - (Math.abs(distanceX) / Math.max(140, card.offsetWidth * 0.7))));
                });
                const finishDrag = event => {
                    if (event.pointerId !== pointerId) return;
                    card.releasePointerCapture?.(event.pointerId);
                    pointerId = null;
                    if (!dragging) return;
                    dragging = false;
                    card.dataset.suppressSwipeClick = '1';
                    card.classList.remove('notification-card-dragging');
                    const shouldDismiss = Math.abs(distanceX) >= Math.min(90, card.offsetWidth * 0.22);
                    if (!shouldDismiss) {
                        card.classList.add('notification-card-drag-reset');
                        card.style.transform = 'translateX(0)';
                        card.style.opacity = '';
                        window.setTimeout(() => {
                            card.classList.remove('notification-card-drag-reset');
                            card.style.removeProperty('transform');
                        }, 150);
                        return;
                    }
                    const direction = distanceX < 0 ? -1 : 1;
                    card.classList.add('notification-card-dismissing');
                    card.style.transform = `translateX(${direction * (card.offsetWidth + 40)}px)`;
                    card.style.opacity = '0';
                    persistCardRemoval(card).catch(() => {
                        card.classList.remove('notification-card-dismissing');
                        delete card.dataset.suppressSwipeClick;
                        card.style.transform = 'translateX(0)';
                        card.style.opacity = '';
                    });
                };
                card.addEventListener('pointerup', finishDrag);
                card.addEventListener('pointercancel', finishDrag);
            };
            list.querySelectorAll('.notification-card').forEach(bindCardDragDismiss);

            list.addEventListener('click', async event => {
                const remove = event.target.closest('.notification-card-remove');
                if (remove) {
                    event.preventDefault();
                    event.stopPropagation();
                    const removeCard = remove.closest('.notification-card[data-notification-key]');
                    if (!removeCard) return;
                    try {
                        await persistCardRemoval(removeCard);
                    } catch (_) { /* leave the card in place */ }
                    return;
                }
                const card = event.target.closest('.notification-card[data-notification-key]');
                if (!card) return;
                event.preventDefault();
                if (card.dataset.suppressSwipeClick === '1') {
                    delete card.dataset.suppressSwipeClick;
                    event.stopPropagation();
                    return;
                }
                const destination = card.href;
                try {
                    if (card.classList.contains('notification-card-unread')) {
                        await markRead(page, [card.dataset.notificationKey]);
                    }
                } finally {
                    window.location.assign(destination);
                }
            });
            list.addEventListener('keydown', event => {
                const remove = event.target.closest('.notification-card-remove');
                if (!remove || (event.key !== 'Enter' && event.key !== ' ')) return;
                event.preventDefault();
                remove.click();
            });
            if (markAllButton) {
                markAllButton.addEventListener('click', async () => {
                    markAllButton.disabled = true;
                    try {
                        await markRead(page, [], true);
                        list.querySelectorAll('.notification-card-unread').forEach(card => card.classList.remove('notification-card-unread'));
                        markAllButton.disabled = true;
                        if (typeof window.syncNotificationsSidebarButton === 'function') window.syncNotificationsSidebarButton();
                    } catch (_) {
                        markAllButton.disabled = false;
                    }
                });
            }
            if (removeAllButton) {
                removeAllButton.addEventListener('click', async () => {
                    const confirmed = typeof window.showSitePopup === 'function'
                        ? await window.showSitePopup({
                            title: 'clear all notifications?',
                            detail: 'this permanently removes every notification from your list.',
                            okText: 'clear all',
                            cancelText: 'cancel',
                        })
                        : false;
                    if (!confirmed) return;
                    removeAllButton.disabled = true;
                    try {
                        await dismiss(page, [], true);
                        list.replaceChildren();
                        removeAllButton.disabled = true;
                        if (markAllButton) markAllButton.disabled = true;
                        status.hidden = false;
                        status.textContent = 'you have no notifications.';
                        paginationWrap.replaceChildren();
                        if (typeof window.syncNotificationsSidebarButton === 'function') window.syncNotificationsSidebarButton();
                    } catch (_) { removeAllButton.disabled = false; }
                });
            }
        } catch (_) {
            status.textContent = 'notifications could not be loaded. please try again.';
        }
    }

    window.initNotificationsPage = initNotificationsPage;
    if (!window.__notificationsRealtimePageBound) {
        window.__notificationsRealtimePageBound = true;
        window.addEventListener('fridg3:new-notifications', () => {
            if (!window.location.pathname.startsWith('/notifications')) return;
            if (typeof loadPageIntoContent === 'function') {
                loadPageIntoContent(window.location.pathname + window.location.search, false);
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initNotificationsPage);
    else initNotificationsPage();
}());
