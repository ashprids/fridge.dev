(() => {
    const form = document.getElementById('toast-radio-form');
    if (!form || form.dataset.bound) return;
    form.dataset.bound = '1';
    const button = form.querySelector('button');
    const notice = document.getElementById('toast-radio-status');
    let csrf = '';
    async function request(options = {}) {
        const response = await fetch('/api/discord-bot-control/', {credentials: 'same-origin', cache: 'no-store', ...options});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Could not update Toast radio.');
        return data;
    }
    request().then(data => {
        csrf = data.csrf;
        form.elements.name.value = data.stream.name || '';
        form.elements.url.value = data.stream.url || '';
        form.elements.status.checked = data.status === 'online';
        button.disabled = false;
    }).catch(error => { notice.textContent = error.message; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled) return;
        button.disabled = true;
        try {
            await request({method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({csrf, name: form.elements.name.value.trim(), url: form.elements.url.value.trim(), status: form.elements.status.checked ? 'online' : 'offline'})});
            notice.textContent = 'Radio saved. Toast will reload the stream.';
            if (typeof playToastStreamInMiniPlayer === 'function') await playToastStreamInMiniPlayer(!document.getElementById('mini-player-audio')?.paused);
        } catch (error) { notice.textContent = error.message; }
        finally { button.disabled = false; }
    });
})();
