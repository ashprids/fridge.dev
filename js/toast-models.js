(() => {
    async function initModelControls() {
        const form = document.getElementById('toast-model-form');
        const fields = document.getElementById('toast-model-fields');
        const status = document.getElementById('toast-model-status');
        if (!form || !fields || !status) return;
        if (form.dataset.initialized) return;
        form.dataset.initialized = '1';
        form.hidden = false;
        const save = form.querySelector('button[type="submit"]');
        save.disabled = true;
        const scenarios = {
            discord_text: 'Discord text', discord_images: 'Discord images (vision model required)',
            website_chat_text: 'Website chat text', website_chat_images: 'Website chat images (vision model required)',
            feed_drafts: 'Feed drafts', feed_replies: 'Feed replies (manual and automatic)'
        };
        const controls = {};
        let csrf = '';
        try {
            const response = await fetch('/api/toast-models/', {credentials: 'same-origin', cache: 'no-store'});
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Could not load model settings.');
            csrf = data.csrf;
            Object.entries(scenarios).forEach(([key, labelText]) => {
                const label = document.createElement('label');
                label.textContent = labelText;
                const select = document.createElement('select');
                const wrap = document.createElement('span'); wrap.className = 'toast-model-select-wrap';
                const ids = data.available;
                ids.forEach(id => select.add(new Option(id, id)));
                select.add(new Option('Custom model ID…', '__custom__'));
                if (!ids.includes(data.models[key])) { const unavailable = new Option(data.models[key] + ' (unavailable)', data.models[key]); unavailable.disabled = true; select.add(unavailable); }
                select.value = data.models[key];
                const custom = document.createElement('input');
                custom.type = 'text'; custom.maxLength = 200; custom.hidden = true;
                custom.placeholder = 'Enter model ID'; custom.setAttribute('aria-label', labelText + ' custom model ID');

                select.addEventListener('change', () => {
                    custom.hidden = select.value !== '__custom__';
                    custom.required = !custom.hidden;
                    if (!custom.hidden) custom.focus();
                });
                wrap.append(select); label.append(wrap, custom); fields.append(label);
                controls[key] = {select, custom};
            });
            status.textContent = data.listError || '';
            save.disabled = false;
        } catch (error) { status.textContent = error.message; }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (save.disabled) return;
            save.disabled = true;
            const models = {};
            Object.entries(controls).forEach(([key, control]) => {
                models[key] = control.select.value === '__custom__' ? control.custom.value.trim() : control.select.value;
            });
            try {
                const response = await fetch('/api/toast-models/', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf, models})
                });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.error || 'Could not save model settings.');
                status.textContent = 'Models saved. New requests will use these selections.';
            } catch (error) { status.textContent = error.message; }
            finally { save.disabled = false; }
        });
    }

initModelControls();
})();
