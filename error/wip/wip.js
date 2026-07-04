(function() {
    'use strict';

    const TRUTHY_VALUES = new Set(['1', 'true', 'yes', 'y', 'on', 'enabled', 'wip']);
    const CHECK_INTERVAL_MS = 10000;

    async function checkMaintenanceState() {
        try {
            const response = await fetch('/data/etc/wip', { cache: 'no-store' });
            if (!response.ok) return;

            const text = (await response.text()).trim().toLowerCase();
            if (!TRUTHY_VALUES.has(text)) {
                window.location.replace('/');
            }
        } catch (_) {
            /* stay on the maintenance page if the check cannot complete */
        }
    }

    checkMaintenanceState();
    window.setInterval(checkMaintenanceState, CHECK_INTERVAL_MS);
}());
