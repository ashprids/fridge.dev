(function() {
const debugLog = message => window.fridg3DebugClientLog?.(`[toast controls] ${message}`);
function initToastDiscordBotPage() {
    const root = document.getElementById('control-panel-container');
    const listenButton = document.getElementById('listen-along-button');
    const bindRoot = root || listenButton;
    if (!bindRoot || bindRoot.dataset.toastDiscordBotBound === '1') return;
    bindRoot.dataset.toastDiscordBotBound = '1';

    const statusToggle = document.getElementById('status-toggle');
    const statusDot = document.querySelector('.status-dot');
    const statusText = document.querySelector('.status-line .muted');
    const nowPlayingEl = document.querySelector('.now-playing');
    const nowPlayingName = document.getElementById('now-playing-name');
    const urlInput = document.getElementById('stream-url-input');
    const nameInput = document.getElementById('stream-name-input');
    const updateButton = document.getElementById('update-stream-button');
    const statusDiv = document.getElementById('stream-update-status');
    let lastLoggedBotStatus = null;

    function setLiveControls(isLive) {
        const miniPlayerEl = document.getElementById('mini-player');
        const seekEl = document.getElementById('mini-player-seek');
        const downloadBtn = document.getElementById('mini-player-download');
        if (miniPlayerEl) miniPlayerEl.classList.toggle('live-stream', !!isLive);
        if (seekEl) seekEl.style.display = isLive ? 'none' : '';
        if (downloadBtn) downloadBtn.style.display = isLive ? 'none' : '';
    }

    function applyManualStatus(isOnline) {
        if (statusDot) statusDot.style.background = isOnline ? '#6ccf6c' : '#cf6c6c';
        if (statusText) statusText.textContent = isOnline ? 'Online' : 'Offline';
        if (nowPlayingEl) nowPlayingEl.style.display = isOnline ? 'block' : 'none';
        if (listenButton) listenButton.style.display = isOnline ? '' : 'none';
    }

    function setStatusUI(isOnline) {
        if (statusToggle) statusToggle.checked = !!isOnline;
        applyManualStatus(!!isOnline);
    }

    function showStatus(message, isError = false) {
        if (!statusDiv) return;
        statusDiv.textContent = message || '';
        statusDiv.style.color = isError ? '#cf6c6c' : '#6ccf6c';
        statusDiv.style.display = message ? 'block' : 'none';
    }

    async function checkAdminStatus() {
        try {
            const response = await fetch('/api/account/is-admin/');
            const data = await response.json();
            const isAdmin = data && data.isAdmin === true;

            if (root) root.style.display = isAdmin ? 'block' : 'none';
            debugLog(`admin controls ${isAdmin ? 'enabled' : 'hidden'}`);
            if (statusToggle) statusToggle.disabled = !isAdmin;
            if (updateButton) {
                updateButton.disabled = !isAdmin;
                updateButton.style.opacity = isAdmin ? '' : '0.5';
                updateButton.style.cursor = isAdmin ? '' : 'not-allowed';
            }
            return isAdmin;
        } catch (err) {
            console.error('Failed to check admin status:', err);
            if (root) root.style.display = 'none';
            debugLog(`admin check failed: ${err.message || 'unknown error'}`);
            return false;
        }
    }

    async function updateNowPlaying() {
        try {
            const response = await fetch('/api/discord-bot-status/');
            const data = await response.json();
            const streamName = data && data.stream && data.stream.name ? data.stream.name : null;
            const isOnline = data && data.bot && data.bot.status
                ? String(data.bot.status).toLowerCase() === 'online'
                : false;

            if (nowPlayingName) {
                nowPlayingName.textContent = streamName ? `Listening to ${streamName}` : 'Unknown';
            }
            setStatusUI(isOnline);
            if (lastLoggedBotStatus !== isOnline) {
                debugLog(`bot status changed to ${isOnline ? 'online' : 'offline'}`);
                lastLoggedBotStatus = isOnline;
            }
        } catch (err) {
            console.error('Failed to fetch bot status:', err);
            if (nowPlayingName) nowPlayingName.textContent = 'Unknown';
            setStatusUI(false);
            debugLog(`bot status refresh failed: ${err.message || 'unknown error'}`);
        }
    }

    async function persistStatus(isOnline) {
        if (!statusToggle) return;
        statusToggle.disabled = true;
        showStatus(`Setting status to ${isOnline ? 'online' : 'offline'}...`);
        try {
            const response = await fetch('/api/discord-bot-control/status/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status: isOnline ? 'online' : 'offline' }),
            });
            const data = await response.json();
            if (!response.ok || data.ok !== true) {
                throw new Error(data.error || 'Failed to update status');
            }
            showStatus(`Status set to ${isOnline ? 'online' : 'offline'}.`);
            debugLog(`bot status changed to ${isOnline ? 'online' : 'offline'}`);
        } catch (err) {
            showStatus(err.message, true);
            setStatusUI(!isOnline);
            debugLog(`bot status change failed: ${err.message || 'unknown error'}`);
        } finally {
            statusToggle.disabled = false;
        }
    }

    if (statusToggle) {
        statusToggle.addEventListener('change', () => {
            const isOnline = !!statusToggle.checked;
            setStatusUI(isOnline);
            persistStatus(isOnline);
        });
    }

    if (updateButton && urlInput && nameInput) {
        updateButton.addEventListener('click', async () => {
            const isAdmin = await checkAdminStatus();
            if (!isAdmin) {
                showStatus('You do not have permission to update the stream', true);
                return;
            }

            const url = urlInput.value.trim();
            const name = nameInput.value.trim();
            if (!url || !name) {
                showStatus('Please fill in both URL and name', true);
                return;
            }

            updateButton.disabled = true;
            showStatus('Updating stream...');
            try {
                const response = await fetch('/api/discord-bot-control/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ url, name }),
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.error || 'Failed to update stream');
                }
                showStatus('Stream updated and bot restarted!');
                debugLog('stream configuration updated; bot restart requested');
                urlInput.value = '';
                nameInput.value = '';
                updateNowPlaying();
                setTimeout(() => location.reload(), 1500);
            } catch (err) {
                showStatus('Error: ' + err.message, true);
                debugLog(`stream update failed: ${err.message || 'unknown error'}`);
            } finally {
                updateButton.disabled = false;
            }
        });
    }

    setStatusUI(false);
    setLiveControls(false);
    checkAdminStatus();
    updateNowPlaying();
    if (window.__toastStatusInterval) {
        clearInterval(window.__toastStatusInterval);
    }
    window.__toastStatusInterval = setInterval(updateNowPlaying, 5000);
    debugLog('page controls initialized');
}

window.fridg3InitToastDiscordBotPage = initToastDiscordBotPage;
initToastDiscordBotPage();
})();
