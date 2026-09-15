"""Check private rotation and session-selected PHP/Python log access without production data."""
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('diagnostics', root / 'others/toast-discord-bot/bot/diagnostics.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory() as directory:
    sandbox = Path(directory)
    logs = sandbox / 'data/etc'
    logger = module.configure_diagnostics(logs)
    handler = logger.handlers[-1]
    handler.maxBytes = 300
    for index in range(30):
        logger.debug('Request completed HTTP 200 sequence=%s', index)
    files = list(logs.iterdir())
    assert len(files) == 3
    for path in files:
        assert path.name.endswith('.json')
        assert path.stat().st_mode & 0o777 == 0o640
        for line in path.read_text().splitlines():
            entry = json.loads(line)
            assert entry['level'] == 'DEBUG'
            assert entry['timestamp'].endswith('Z')
    handler.close()
    logger.removeHandler(handler)

    endpoint = sandbox / 'api/debug-process-logs/index.php'
    endpoint.parent.mkdir(parents=True)
    shutil.copy(root / 'api/debug-process-logs/index.php', endpoint)
    (sandbox / 'lib').mkdir()
    # Keep the actual identity helper; stub only the session startup/environment.
    (sandbox / 'lib/toast.php').write_text('<?php require ' + json.dumps(str(root / 'lib/toast.php')) + ';')
    (sandbox / 'lib/session.php').write_text('''<?php
require __DIR__ . '/toast.php';
function fridge_start_session() { $_SESSION['user'] = json_decode(getenv('TEST_USER'), true); }
''')
    php_log = sandbox / 'php.log'
    php_log.write_text('PHP_ONLY\n')
    import os
    def request(user):
        env = dict(os.environ, TEST_USER=json.dumps(user), **{'FRIDG3_PHP_PROCESS_LOG': str(php_log)})
        result = subprocess.run(['php', str(endpoint)], env=env, capture_output=True, text=True, check=True)
        assert not result.stderr, result.stderr
        return json.loads(result.stdout)
    for user in ({}, {'username': 'toast'}, {'username': 'visitor', 'isHardcodedToast': True}):
        assert request(user)['error'] == 'forbidden'
    admin = request({'username': 'admin', 'isAdmin': True})
    assert admin['lines'] == ['PHP_ONLY'] and not admin['python']
    toast_user = {'username': 'toast', 'isHardcodedToast': True}
    toast = request(toast_user)
    assert toast['python'] and toast['ok'] and 'PHP_ONLY' not in str(toast)
    path = logs / 'toast-python-log.json'
    with path.open('a') as output:
        output.write('{"partial":')
    assert request(toast_user)['lines'] == toast['lines']
    path.unlink()
    unavailable = request(toast_user)
    assert unavailable['python'] and unavailable['error'] == 'log_unavailable'
    assert 'PHP_ONLY' not in str(unavailable)
print('Python log rotation, permissions, partial records, identity checks, source isolation, and missing-log checks passed.')
