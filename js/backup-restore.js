(function () {
    'use strict';
    if (window.fridgeBackupRestoreLoaded) return;
    window.fridgeBackupRestoreLoaded = true;
    const endpoint = '/api/restore-backup/index.php';
    const developmentCopyMessage = 'the operation was cancelled because you selected a copy of /data/ intended for development only.\n\ndevelopment copies cannot be used as backups because confidental information (API keys, IPs, etc.) is redacted from them for privacy and security reasons.\n\nif you\'re a developer and you\'re trying to apply a specific version of development data, you can extract the zip file\'s "data" folder into the website root.';
    let csrf = '', busy = false, selecting = false, popup = null, uploading = false, current = null;
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const dismissed = id => { try { return sessionStorage.getItem(`restore-dismissed-${id}`) === '1'; } catch (_) { return false; } };
    async function request(action, data, query = '') {
        const response = await fetch(`${endpoint}?action=${action}${query}`, {
            method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'X-Restore-CSRF': csrf, 'Content-Type': data instanceof Blob ? 'application/octet-stream' : 'application/json' },
            body: data === undefined ? undefined : data instanceof Blob ? data : JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok || result.ok !== true) {
            const error = new Error(result.error || 'restore request failed'); error.status = response.status; throw error;
        }
        if (result.csrf) csrf = result.csrf;
        return result;
    }
    async function localFile(operation, value) {
        const db = await new Promise((resolve, reject) => {
            const open = indexedDB.open('fridg3-backup-restore', 1);
            open.onupgradeneeded = () => open.result.createObjectStore('archive');
            open.onsuccess = () => resolve(open.result);
            open.onerror = () => reject(new Error('could not store backup locally for resumable upload'));
        });
        try {
            return await new Promise((resolve, reject) => {
                const tx = db.transaction('archive', operation === 'get' ? 'readonly' : 'readwrite');
                const store = tx.objectStore('archive');
                const action = operation === 'get' ? store.get('pending') : operation === 'put' ? store.put(value, 'pending') : store.delete('pending');
                tx.oncomplete = () => resolve(action.result);
                tx.onerror = tx.onabort = () => reject(new Error('could not store backup locally for resumable upload'));
            });
        } finally { db.close(); }
    }
    function chooseZip() {
        return new Promise(resolve => {
            const picker = document.createElement('input');
            picker.type = 'file'; picker.accept = '.zip,application/zip'; picker.hidden = true;
            const finish = value => { picker.remove(); resolve(value); };
            picker.addEventListener('change', () => finish(picker.files?.[0] || null), { once: true });
            picker.addEventListener('cancel', () => finish(null), { once: true });
            document.body.append(picker); picker.click();
        });
    }
    async function inspect(file) {
        if (!window.FridgeBackupArchive) {
            await new Promise((resolve, reject) => {
                const script = document.createElement('script'); script.src = '/js/backup-archive.js?v=20260915-2';
                script.onload = resolve; script.onerror = () => reject(new Error('could not load backup reader')); document.head.append(script);
            });
        }
        return window.FridgeBackupArchive.inspect(file);
    }
    async function fingerprint(file) {
        const sample = new Blob([file.slice(0, 1048576), file.slice(Math.max(0, file.size - 1048576))]);
        const bytes = new Uint8Array(await sample.arrayBuffer());
        if (window.crypto?.subtle) {
            const hash = await window.crypto.subtle.digest('SHA-256', bytes);
            return Array.from(new Uint8Array(hash), value => value.toString(16).padStart(2, '0')).join('');
        }
        // Web Crypto is unavailable on plain-HTTP LAN development origins. This
        // checksum is only an identity check for resumable uploads; the worker
        // separately verifies the ZIP and every entry CRC before restoring it.
        const words = new Uint32Array([0x811c9dc5, 0x9e3779b9, 0x85ebca6b, 0xc2b2ae35, 0x27d4eb2f, 0x165667b1, 0xd3a2646c, 0xfd7046c5]);
        for (const byte of bytes) {
            for (let index = 0; index < words.length; index++) {
                words[index] = Math.imul(words[index] ^ ((byte + index * 29) & 255), 0x01000193) >>> 0;
                words[index] = (words[index] << 7 | words[index] >>> 25) >>> 0;
            }
        }
        return Array.from(words, word => word.toString(16).padStart(8, '0')).join('');
    }
    const notice = (title, detail) => window.showSitePopup({ className: 'backup-restore-dialog', title, detail, okText: 'ok' });
    async function begin() {
        if (selecting || current?.active) return;
        selecting = true;
        try {
            const accepted = await window.showSitePopup({ className: 'backup-restore-dialog', title: 'restore backup', detail: 'you will now be prompted to select a zip file to upload.\n\nmake sure you only select a zip file generated by the automatic backup script to prevent issues.', cancelText: 'cancel', okText: 'ok' });
            if (!accepted) return;
            const file = await chooseZip(); if (!file) return;
            window.showSitePopup({ className: 'backup-inspecting', title: 'reading backup', detail: 'reading archive information on this device...', noButtons: true });
            let summary;
            try {
                summary = await inspect(file);
            } catch (error) {
                if (error?.code === 'development-copy') {
                    document.querySelector('.backup-inspecting')?.remove();
                    await notice('error', developmentCopyMessage);
                    return;
                }
                throw error;
            } finally { document.querySelector('.backup-inspecting')?.remove(); }
            const detail = `you have provided a backup from ${summary.date} (${summary.days} days ago). the following information has been gathered from the archive:\n\naccounts: ${summary.accounts}\nfeed posts: ${summary.feed}\nfeed post replies: ${summary.replies}\njournal posts: ${summary.journal}\nguestbook entries: ${summary.guestbook}\nchat threads: ${summary.chats}\nimages: ${summary.images}\nmdpaste uploads: ${summary.mdpaste}\n\ndouble-check this is the backup you want to restore, and then press continue.`;
            if (!await window.showSitePopup({ className: 'backup-restore-dialog', title: 'backup selected', detail, cancelText: 'cancel', okText: 'continue' })) return;
            const password = await window.showSitePopup({ title: 'authentication required', detail: 'to perform this action, please provide your account password.', input: true, inputType: 'password', cancelText: 'cancel', okText: 'ok' });
            if (password === null || password === false) return;
            await request('status');
            await request('authenticate', { password });
            if (!await window.showSitePopup({ className: 'backup-restore-dialog backup-restore-confirm', title: 'confirmation', detail: 'by pressing confirm, the provided backup will begin uploading and will then replace the current /data/ directory. the website will be in maintenance mode while this is happening.\n\nare you sure you want to continue? this cannot be cancelled or undone past this point!', cancelText: 'cancel', okText: 'confirm' })) return;
            const identity = await fingerprint(file);
            const result = await request('start', { name: file.name, size: file.size, fingerprint: identity });
            current = result;
            render(result);
            // Persisting a large File can take several seconds. It is useful for
            // automatic resumption, but must not delay the visible progress UI or upload.
            localFile('put', { id: result.state.id, file }).catch(() => {});
            await upload(result.state, file);
        } catch (error) {
            await notice('restore backup', error.message);
        } finally {
            selecting = false;
        }
    }
    function lockSettings(active) {
        document.querySelectorAll('[name="maintenance-mode"]').forEach(radio => {
            radio.disabled = active;
            if (active) radio.checked = radio.value === 'on';
        });
        document.querySelector('#maintenance-mode-group')?.classList.toggle('backup-maintenance-locked', active);
        if (popup?.isConnected) {
            document.body.classList.add('backup-restore-locked');
            for (const child of document.body.children) child.inert = child !== popup;
        }
    }
    function render(result) {
        current = result;
        lockSettings(result.active);
        const state = result.state || {};
        if (!result.active && (state.stage !== 'complete' || dismissed(state.id))) return;
        if (!popup?.isConnected) {
            window.showSitePopup({ className: 'backup-restore-progress backup-restore-dialog', title: 'restore backup', detail: '', noButtons: true });
            popup = document.querySelector('.backup-restore-progress');
            const dialog = popup.querySelector('.site-popup-dialog');
            dialog.classList.add('dev-bootstrap-progress-dialog');
            const logLine = document.createElement('div');
            logLine.className = 'dev-bootstrap-progress-log backup-restore-progress-log';
            const meter = document.createElement('div');
            meter.className = 'dev-bootstrap-progress backup-restore-meter';
            meter.setAttribute('role', 'progressbar');
            meter.setAttribute('aria-label', 'backup restoration progress');
            meter.setAttribute('aria-valuemin', '0');
            meter.setAttribute('aria-valuemax', '100');
            const bar = document.createElement('div');
            bar.className = 'dev-bootstrap-progress-bar backup-restore-progress-bar';
            meter.append(bar);
            const percent = document.createElement('div'); percent.className = 'dev-bootstrap-progress-percent backup-restore-percent';
            const actions = document.createElement('div'); actions.className = 'site-popup-actions';
            dialog.append(logLine, meter, percent, actions);
        }
        lockSettings(result.active);
        popup.querySelector('.site-popup-detail').textContent = state.message || 'restoring backup...';
        const progress = Math.max(0, Math.min(100, Number(state.percent) || 0));
        const meter = popup.querySelector('.backup-restore-meter');
        meter.setAttribute('aria-valuenow', String(progress));
        popup.querySelector('.backup-restore-progress-bar').style.width = `${progress}%`;
        popup.querySelector('.backup-restore-percent').textContent = `${Math.round(progress)}%`;
        const progressDetail = state.stage === 'uploading' && state.size
            ? `${state.received || 0}/${state.size} bytes uploaded`
            : (state.stage || 'waiting');
        popup.querySelector('.backup-restore-progress-log').textContent = progressDetail;
        const actions = popup.querySelector('.site-popup-actions'); actions.replaceChildren();
        const button = (label, handler) => {
            const element = document.createElement('button'); element.type = 'button'; element.className = 'site-popup-button site-popup-ok'; element.textContent = label;
            element.onclick = handler; actions.append(element);
        };
        if (state.stage === 'complete') {
            button('ok', () => {
                try { sessionStorage.setItem(`restore-dismissed-${state.id}`, '1'); } catch (_) { /* optional storage */ }
                localFile('delete').finally(() => window.location.assign('/'));
            });
        } else if (state.stage === 'error') {
            button('retry', async () => { try { await request('retry', {}, `&id=${state.id}`); } catch (error) { popup.querySelector('.site-popup-detail').textContent = error.message; } });
        } else if (state.stage === 'uploading' && !uploading) {
            button('resume upload', async () => {
                const file = await chooseZip(); if (!file) return;
                if (file.name !== state.name || file.size !== state.size || await fingerprint(file) !== state.fingerprint) { await notice('backup mismatch', 'select the same backup ZIP to resume this restore.'); return; }
                await localFile('put', { id: state.id, file });
                upload(state);
            });
        }
    }
    async function upload(state, selectedFile = null) {
        if (uploading) return;
        uploading = true;
        try {
            const saved = selectedFile ? { id: state.id, file: selectedFile } : await localFile('get');
            if (!saved?.file || (saved.id && saved.id !== state.id) || saved.file.size !== state.size || saved.file.name !== state.name) return;
            if (await fingerprint(saved.file) !== state.fingerprint) return;
            while (state.stage === 'uploading') {
                const offset = state.received;
                try {
                    const result = await request('chunk', saved.file.slice(offset, offset + 2097152), `&id=${state.id}&offset=${offset}`);
                    state = result.state;
                    render({ ...result, active: true });
                } catch (_) {
                    await sleep(1500);
                    const result = await request('status'); state = result.state;
                    if (!result.active) break;
                }
            }
        } catch (_) { /* poll retries from the last acknowledged chunk */ }
        finally { uploading = false; }
    }
    async function poll() {
        if (busy || selecting) return;
        busy = true;
        try {
            const result = await request('status'); render(result);
            if (result.active && result.state?.stage === 'uploading') upload(result.state);
        } catch (_) { /* retain progress until the connection returns */ }
        finally { busy = false; }
    }
    document.addEventListener('click', event => {
        if (!event.target.closest('[data-action="restore-backup"]')) return;
        event.preventDefault(); begin();
    });
    function init() {
        poll(); setInterval(poll, 1500);
        new MutationObserver(() => { if (current?.active) lockSettings(true); }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
})();
