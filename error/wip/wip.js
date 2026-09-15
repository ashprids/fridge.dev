(function() {
    'use strict';

    const CHECK_INTERVAL_MS = 10000;
    const debugLog = message => window.fridgeDebugClientLog?.(`[maintenance] ${message}`);

    async function checkMaintenanceState() {
        try {
            const response = await fetch('/api/maintenance-status/index.php', { cache: 'no-store' });
            if (!response.ok) {
                debugLog(`state check failed with HTTP ${response.status}`);
                return;
            }

            const state = await response.json();
            if (state.enabled === false) {
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
