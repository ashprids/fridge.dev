(() => {
    const form = document.getElementById('toast-credentials-form');
    if (!form || form.dataset.bound) return;
    form.dataset.bound = '1';
    const input = form.querySelector('input');
    const button = form.querySelector('button');
    const notice = form.querySelector('[role="status"]');
    let csrf = '';
    async function request(options = {}) {
        const response = await fetch('/api/toast-credentials/', {credentials:'same-origin', cache:'no-store', ...options});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Could not save API key.');
        return data;
    }
    request().then(data => {
        csrf = data.csrf;
        notice.textContent = data.configured ? 'A key is already configured. Leave this field empty to keep it.' : 'No key is configured.';
        button.disabled = false;
    }).catch(error => { notice.textContent = error.message; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled || !input.value.trim()) return;
        button.disabled = true;
        try {
            await request({method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({csrf,apiKey:input.value.trim()})});
            input.value = '';
            location.reload(); // Refresh model availability using the new key, without exposing it in the page.
        } catch (error) { notice.textContent = error.message; }
        finally { button.disabled = false; }
    });
})();
