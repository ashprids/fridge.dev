(function() {
    'use strict';

    const TRUTHY_VALUES = new Set(['1', 'true', 'yes', 'y', 'on', 'enabled', 'wip']);
    const CHECK_INTERVAL_MS = 10000;
    const debugLog = message => window.fridg3DebugClientLog?.(`[maintenance] ${message}`);

    async function checkMaintenanceState() {
        try {
            const response = await fetch('/data/etc/wip', { cache: 'no-store' });
            if (!response.ok) {
                debugLog(`state check failed with HTTP ${response.status}`);
                return;
            }

            const text = (await response.text()).trim().toLowerCase();
            if (!TRUTHY_VALUES.has(text)) {
                debugLog('maintenance ended; redirecting to homepage');
                window.location.replace('/');
            }
        } catch (_) {
            debugLog('state check could not reach the server');
            /* stay on the maintenance page if the check cannot complete */
        }
    }

    checkMaintenanceState();
    debugLog('maintenance-state polling started');
    window.setInterval(checkMaintenanceState, CHECK_INTERVAL_MS);
}());
