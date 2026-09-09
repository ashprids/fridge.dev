"""Bounded, private operational logs for Toast's website debug panel."""
import json
import logging
from logging.handlers import RotatingFileHandler
import os
import time


class DiagnosticFormatter(logging.Formatter):
    def format(self, record):
        # Only deliberately supplied operational messages enter this logger.
        timestamp = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(record.created))
        return json.dumps({'timestamp': timestamp, 'level': record.levelname,
                           'message': record.getMessage()}, ensure_ascii=True)


class PrivateRotatingFileHandler(RotatingFileHandler):
    def _open(self):
        fd = os.open(self.baseFilename, os.O_CREAT | os.O_APPEND | os.O_WRONLY, 0o640)
        os.fchmod(fd, 0o640)
        return os.fdopen(fd, 'a', encoding=self.encoding)


def configure_diagnostics(directory):
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / 'toast-python-log.json'
    handler = PrivateRotatingFileHandler(path, maxBytes=2 * 1024 * 1024, backupCount=2,
                                  encoding='utf-8')
    # Keep rotated files behind the site's existing private JSON rule too.
    handler.namer = lambda name: name + '.json'
    handler.setFormatter(DiagnosticFormatter())
    logger = logging.getLogger('toast.diagnostics')
    logger.setLevel(logging.DEBUG)
    logger.propagate = False
    logger.addHandler(handler)
    logger.info('Python service starting pid=%s', os.getpid())
    return logger
