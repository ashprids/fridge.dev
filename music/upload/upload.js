(function() {
    const debugLog = message => window.fridg3DebugClientLog?.(`[music upload] ${message}`);
    const form = document.querySelector('[data-music-upload-form]');
    if (!form || form.dataset.musicUploadBound === '1') return;
    form.dataset.musicUploadBound = '1';

    const typeSelect = form.querySelector('[data-music-release-type]');
    const list = form.querySelector('[data-music-track-list]');
    const template = document.querySelector('[data-music-track-template]');
    const addButton = form.querySelector('[data-music-add-track]');
    if (!typeSelect || !list || !template || !addButton) return;

    const updateRows = () => {
        const rows = Array.from(list.querySelectorAll('[data-music-track-row]'));
        rows.forEach((row, index) => {
            const number = row.querySelector('[data-music-track-number]');
            const remove = row.querySelector('[data-music-track-remove]');
            const up = row.querySelector('[data-music-track-up]');
            const down = row.querySelector('[data-music-track-down]');
            if (number) number.textContent = String(index + 1);
            if (remove) remove.disabled = rows.length <= 1;
            if (up) up.disabled = index === 0;
            if (down) down.disabled = index === rows.length - 1;
        });
    };

    const addTrack = () => {
        const fragment = template.content.cloneNode(true);
        list.appendChild(fragment);
        updateRows();
        debugLog(`track row added (${list.querySelectorAll('[data-music-track-row]').length} total)`);
    };

    if (!list.querySelector('[data-music-track-row]')) {
        addTrack();
    }

    addButton.addEventListener('click', () => {
        addTrack();
        const rows = list.querySelectorAll('[data-music-track-row]');
        const newest = rows[rows.length - 1];
        const title = newest ? newest.querySelector('input[type="text"]') : null;
        if (title) title.focus();
    });

    typeSelect.addEventListener('change', updateRows);
    list.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        const row = button.closest('[data-music-track-row]');
        if (!row) return;

        if (button.matches('[data-music-track-up]') && row.previousElementSibling) {
            list.insertBefore(row, row.previousElementSibling);
        } else if (button.matches('[data-music-track-down]') && row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        } else if (button.matches('[data-music-track-remove]')) {
            row.remove();
            debugLog('track row removed');
            if (!list.querySelector('[data-music-track-row]')) addTrack();
        }
        updateRows();
    });

    updateRows();
    debugLog('release editor initialized');
})();
