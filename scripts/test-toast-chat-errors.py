"""Exercise the website AI handler without Discord, credentials, or network calls."""
import ast
import asyncio
import json
import logging
import re
from pathlib import Path
from types import SimpleNamespace

source = Path(__file__).resolve().parents[1] / 'others/toast-discord-bot/bot/main.py'
names = {'active_model_error', 'verify_active_model', 'website_chat_failure', 'website_chat_reply_handler'}
nodes = [n for n in ast.parse(source.read_text()).body if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef)) and n.name in names]
config = {'api_key': 'test-only', 'temperature': .8, 'top_p': .95, 'max_completion_tokens': 700, 'timeout_seconds': 30}
ns = dict(diagnostics=logging.getLogger('test.diagnostics'), asyncio=asyncio, json=json, re=re, logger=logging.getLogger('test'),
          web=SimpleNamespace(json_response=lambda data, **kwargs: data),
          GROQ_FALLBACK_REPLY='fallback', AI_DM_MIN_SEND_DELAY_SECONDS=5,
          GROQ_CHAT_COMPLETIONS_URL='https://provider.invalid/completions',
          ClientTimeout=lambda **kw: None, get_groq_config=lambda: config,
          website_chat_messages=lambda payload: ([], False), scenario_model=lambda *args: 'test-model',
          split_natural_messages=lambda text: [text], typing_delay_seconds=lambda text: 5,
          groq_daily_quota_exceeded=lambda status, text: status == 429)
exec(compile(ast.Module(body=nodes, type_ignores=[]), str(source), 'exec'), ns)

class Response:
    def __init__(self, status, data): self.status, self.data = status, data
    async def __aenter__(self): return self
    async def __aexit__(self, *args): pass
    async def json(self):
        if isinstance(self.data, Exception): raise self.data
        return self.data
    async def text(self): return json.dumps(self.data)

class Session(Response):
    def __init__(self, catalog, completion): self.catalog, self.completion, self.posts = catalog, completion, 0
    def get(self, *args, **kwargs):
        if isinstance(self.catalog, Exception): raise self.catalog
        return self.catalog
    def post(self, *args, **kwargs):
        self.posts += 1
        if isinstance(self.completion, Exception): raise self.completion
        return self.completion

class Request:
    remote = '127.0.0.1'
    async def json(self): return {'current_message': 'hello'}

async def run():
    active = Response(200, {'data': [{'id': 'test-model', 'active': True}]})
    answer = Response(200, {'choices': [{'message': {'content': 'hello'}}]})
    cases = [
        (active, answer, None),
        (Response(403, {}), answer, 'model_catalog_http'),
        (Response(200, {'data': []}), answer, 'model_inactive'),
        (Response(200, ValueError()), answer, 'model_catalog_invalid_json'),
        (asyncio.TimeoutError(), answer, 'model_catalog_timeout'),
        (active, asyncio.TimeoutError(), 'completion_timeout'),
        (active, Response(400, {'error': {'code': 'model_not_found', 'message': 'SECRET'}}), 'completion_http'),
        (active, Response(429, {}), 'completion_http'),
        (active, Response(200, {'choices': [{'message': {'content': ''}, 'finish_reason': 'length'}]}), 'completion_token_limit'),
        (active, Response(200, {'choices': []}), 'completion_empty'),
    ]
    for catalog, completion, code in cases:
        session = Session(catalog, completion)
        ns['ClientSession'] = lambda **kwargs: session
        result = await ns['website_chat_reply_handler'](Request())
        assert result.get('diagnostic', {}).get('code') == code, result
        assert 'SECRET' not in json.dumps(result)
        if code and code.startswith('model_'): assert session.posts == 0
        if completion.status == 429 if isinstance(completion, Response) else False:
            assert result['daily_quota_exceeded']
    config['api_key'] = ''
    assert (await ns['website_chat_reply_handler'](Request()))['diagnostic']['code'] == 'missing_api_key'
    print('Toast AI success, catalog, timeout, quota, empty-output and diagnostic redaction checks passed.')

asyncio.run(run())
