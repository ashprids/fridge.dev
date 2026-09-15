(() => {
    const blockedTip = "this page is not accessible while you're logged in as toast";
    const active = () => document.body.classList.contains('bot-mode');
    function disable(link) {
        link.classList.add('bot-mode-disabled');
        link.setAttribute('aria-disabled', 'true');
        const target = link.querySelector('[data-tooltip]') || link;
        if (target.dataset.tooltip !== blockedTip) target.dataset.tooltip = blockedTip;
    }
    function refresh() {
        if (!active()) return;
        document.querySelectorAll('#sidebar a[href]').forEach(link => {
            const path = new URL(link.href, location.href).pathname.replace(/\/$/, '') || '/';
            if (!['/', '/feed', '/settings', '/account/logout', '/account', '/others'].includes(path)) disable(link);
        });
        if (location.pathname.replace(/\/$/, '') === '/others') {
            document.querySelectorAll('#posts a[href]').forEach(link => {
                if (new URL(link.href, location.href).pathname.replace(/\/$/, '') !== '/others/toast-discord-bot') disable(link);
            });
        }
        if (new URLSearchParams(location.search).has('bot_mode_blocked')) {
            const url = new URL(location.href); url.searchParams.delete('bot_mode_blocked');
            history.replaceState(history.state, '', url);
            window.showSiteNotice?.('bot mode is active', 'you can only access certain pages while logged in as the bot. to access this page, log out of this account.');
        }
    }
    window.fridgeRefreshBotMode = refresh;
    window.fridgeSyncBotMode = doc => {
        const enabled = doc.querySelector('meta[name="fridge-bot-mode"]')?.content === '1';
        // Login/logout changes require a fresh shell (including the persistent audio player).
        if (enabled !== active()) { location.reload(); return; }
        requestAnimationFrame(refresh);
    };
    document.addEventListener('click', event => {
        if (active() && event.target.closest('.bot-mode-disabled, [data-bot-disabled]')) {
            event.preventDefault(); event.stopImmediatePropagation();
        }
    }, true);
    window.addEventListener('DOMContentLoaded', () => {
        refresh();
        const sidebar = document.getElementById('sidebar');
        if (sidebar) new MutationObserver(refresh).observe(sidebar, { childList: true, subtree: true });
    });
})();
