from pathlib import Path
import tempfile,shutil,subprocess,os,json
repo=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='bot-mode-check-') as tmp:
 root=Path(tmp)
 shutil.copytree(repo/'lib',root/'lib')
 (root/'themes').symlink_to(repo/'themes',target_is_directory=True)
 (root/'tools').symlink_to(repo/'tools',target_is_directory=True)
 for rel in ['settings/index.php','settings/content.html','others/index.php','others/content.html','others/toast-discord-bot/index.php','others/toast-discord-bot/content.html','others/toast-discord-bot/messages/index.php','others/toast-discord-bot/messages/content.html','others/toast-discord-bot/chat/history/index.php','api/toast-models/index.php','api/toast-credentials/index.php','others/toast-discord-bot/chat/index.php','others/toast-discord-bot/chat/content.html','api/discord-bot-control/index.php','api/discord-bot-control/status/index.php','api/toast-feed-reply/index.php','feed/posts/index.php','feed/posts/content.html','feed/create/index.php','feed/create/content.html','feed/markdown-editor.html','template.html','template_mobile.html']:
  dest=root/rel;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy(repo/rel,dest)
 (root/'data/etc').mkdir(parents=True);(root/'data/accounts').mkdir()
 (root/'data/accounts/accounts.json').write_text('{"accounts":[]}')
 (root/'data/etc/toast.json').write_text('{"groq":{},"preserve":"yes"}')
 control=root/'lib/toast-radio-control.php';control.write_text(control.read_text().replace("file_get_contents('php://input')","getenv('TEST_BODY')"))
 (root/'data/etc/toast-dm-history.json').write_text('{}')
 credentials=root/'api/toast-credentials/index.php';credentials.write_text(credentials.read_text().replace("file_get_contents('php://input')","getenv('TEST_BODY')"))
 (root/'run.php').write_text('''<?php
 define('FRIDG3_SKIP_ACCESS_LOG',true); session_save_path(sys_get_temp_dir());session_start();
 $_SESSION=['user'=>json_decode(getenv('TEST_USER'),true)]; if(!$_SESSION['user'])$_SESSION=[]; $_SESSION['toast_radio_csrf']='fixture-token'; $_SESSION['toast_credentials_csrf']='fixture-token'; $_SESSION['toast_chat_csrf']='fixture-token'; $_SESSION['csrf_token']='fixture-token';
 $_SERVER['REQUEST_URI']=getenv('TEST_PATH');$_SERVER['REQUEST_METHOD']=getenv('TEST_METHOD')?:'GET';$_SERVER['HTTP_HOST']='fridge.dev';
 $_SERVER['REMOTE_ADDR']='203.0.113.42';$_POST=json_decode(getenv('TEST_BODY')?:'{}',true);$_GET=[];
 register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});
 require __DIR__.'/'.getenv('TEST_FILE');
 ''')
 bot={'username':'toast','name':'Toast','isHardcodedToast':True,'isAdmin':False,'allowedPages':['feed','comments']}
 def run(file,path,user=bot,method='GET',body={}):
  env=dict(os.environ,TEST_FILE=file,TEST_PATH=path,TEST_USER=json.dumps(user),TEST_METHOD=method,TEST_BODY=json.dumps(body),FRIDG3_TOAST_CHAT_DATA_DIR=str(root/'chats'))
  r=subprocess.run(['php',str(root/'run.php')],env=env,capture_output=True,text=True)
  assert r.returncode==0,(file,r.stderr)
  assert 'Fatal' not in r.stderr and 'Warning' not in r.stderr,(file,r.stderr)
  return r.stdout,r.stderr
 (root/'data/feed').mkdir()
 (root/'data/feed/test-post.txt').write_text('v2\nauthor\n2026-09-09 12:00:00\nA post about radios.')
 html,status=run('feed/posts/index.php','/feed/posts/test-post');assert status=='STATUS:200' and 'data-toast-generate-reply' in html and ' readonly' in html
 html,status=run('feed/posts/index.php','/feed/posts/test-post',{'username':'visitor','name':'visitor'});assert 'data-toast-generate-reply' not in html and 'data-editor-format="markdown"' in html
 html,status=run('feed/posts/index.php','/feed/posts/test-post',method='POST',body={'csrf_token':'fixture-token','reply_content':'ungenerated reply'});assert 'generate a reply for this post or comment before submitting.' in html
 for user in [{},{'username':'admin','isAdmin':True}]:
  html,status=run('api/toast-feed-reply/index.php','/api/toast-feed-reply/',user,method='POST');assert status=='STATUS:403'
 html,status=run('api/toast-feed-reply/index.php','/api/toast-feed-reply/',method='POST',body={'post_id':'test-post'});assert status=='STATUS:403'
 html,status=run('api/toast-feed-reply/index.php','/api/toast-feed-reply/',method='POST',body={'csrf_token':'fixture-token','post_id':'test-post','parent_reply_id':'missing'});assert status=='STATUS:404'
 html,status=run('api/toast-feed-reply/index.php','/api/toast-feed-reply/',method='POST',body={'csrf_token':'fixture-token','post_id':'../escape'});assert status=='STATUS:400'
 html,status=run('api/toast-feed-reply/index.php','/api/toast-feed-reply/',method='POST',body={'csrf_token':'fixture-token','post_id':'test-post'});assert status=='STATUS:503' and 'Groq API key' in html
 print('Toast reply composer, ordinary-user composer, CSRF, target validation, and generation prerequisite checks passed.')
 html,status=run('settings/index.php','/settings')
 assert status=='STATUS:200' and 'id="toast-model-form"' in html and 'id="toast-chat-history-button"' in html and 'id="toast-inbox-button"' in html
 assert '<span id="notification-settings" hidden>' in html
 assert 'type="password"' in html and 'id="toast-credentials-form"' in html
 html,status=run('api/toast-models/index.php','/api/toast-models');assert status=='STATUS:200' and 'discord_text' in html
 html,status=run('others/toast-discord-bot/index.php','/others/toast-discord-bot')
 assert 'id="toast-model-form"' not in html and 'id="toast-inbox-button"' not in html
 for file,path in [('others/toast-discord-bot/messages/index.php','/others/toast-discord-bot/messages'),('others/toast-discord-bot/chat/history/index.php','/others/toast-discord-bot/chat/history')]:
  html,status=run(file,path);assert status=='STATUS:200',(file,status)
  _,status=run(file,path,method='POST');assert status=='STATUS:403',(file,status)
 _,status=run('api/toast-models/index.php','/api/toast-models',method='POST');assert status=='STATUS:403'
 html,status=run('others/index.php','/contact');assert status=='STATUS:303' and not html
 html,status=run('others/index.php','/others/toast-discord-bot/chat');assert status=='STATUS:303'
 html,status=run('others/index.php','/others');assert 'data-bot-disabled="1"' in html
 for user in [{},{'username':'visitor','name':'visitor','isAdmin':False},{'username':'toast','name':'fake','isAdmin':False}]:
  html,status=run('api/toast-models/index.php','/api/toast-models',user);assert status=='STATUS:403'
  html,status=run('settings/index.php','/settings',user);assert 'id="toast-model-form"' not in html
 html,status=run('others/toast-discord-bot/chat/index.php','/others/toast-discord-bot/chat',{});assert status=='STATUS:302' and not html
 html,status=run('others/toast-discord-bot/chat/index.php','/others/toast-discord-bot/chat',{},method='POST',body={'action':'send'});assert status=='STATUS:503' and json.loads(html)['offline']
 assert not (root/'chats').exists()
 html,status=run('feed/create/index.php','/feed/create');assert 'id="toast-feed-generator"' in html and 'data-toast-post-button="1"' in html
 html,status=run('api/discord-bot-control/index.php','/api/discord-bot-control/');assert status=='STATUS:200' and 'fixture-token' in html
 for user in [{},{'username':'visitor','name':'visitor','isAdmin':False}]:
  html,status=run('api/discord-bot-control/index.php','/api/discord-bot-control/',user);assert status=='STATUS:403'
 html,status=run('api/discord-bot-control/index.php','/api/discord-bot-control/',method='POST');assert status=='STATUS:403'
 body={'csrf':'fixture-token','url':'https://example.com/radio.m3u','name':'Fixture radio','status':'online'}
 html,status=run('api/discord-bot-control/index.php','/api/discord-bot-control/',method='POST',body=body);assert status=='STATUS:200',(status,html)
 saved=json.loads((root/'data/etc/toast.json').read_text());assert saved['preserve']=='yes' and saved['stream']['name']=='Fixture radio' and saved['bot']['status']=='online'
 assert (root/'data/etc/.stream-update-signal').exists()
 body['url']='file:///etc/passwd'
 html,status=run('api/discord-bot-control/index.php','/api/discord-bot-control/',method='POST',body=body);assert status=='STATUS:400'
 html,status=run('others/toast-discord-bot/chat/index.php','/others/toast-discord-bot/chat',{});assert status=='STATUS:200' and 'class="toast-clear-chat danger-button chat-delete-button"' in html and '{csrf}' not in html and '{messages}' not in html
 html,status=run('others/toast-discord-bot/chat/index.php','/others/toast-discord-bot/chat',{},method='POST',body={'action':'clear-chat','csrf':'fixture-token'});assert status=='STATUS:200' and json.loads(html)['count']==0
 assert not (root/'chats').exists()
 for user in [{},{'username':'admin','isAdmin':True},{'username':'toast','isAdmin':False}]:
  html,status=run('api/toast-credentials/index.php','/api/toast-credentials/',user);assert status=='STATUS:403'
 html,status=run('api/toast-credentials/index.php','/api/toast-credentials/',method='POST',body={'apiKey':'test-secret'});assert status=='STATUS:403'
 html,status=run('api/toast-credentials/index.php','/api/toast-credentials/',method='POST',body={'apiKey':'test-secret','csrf':'fixture-token'});assert status=='STATUS:200' and 'test-secret' not in html
 saved=json.loads((root/'data/etc/toast.json').read_text());assert saved['groq']['api_key']=='test-secret' and saved['preserve']=='yes'
 html,status=run('api/toast-credentials/index.php','/api/toast-credentials/');assert status=='STATUS:200' and json.loads(html)['configured'] and 'test-secret' not in html
 print('Offline chat, empty clear, password control, and credential authorization/redaction checks passed.')
 print('Radio access, CSRF, atomic save, reload signal, invalid URL, and feed generator checks passed.')
 print('Bot route integration passed: settings relocation, Toast inbox/history access, CSRF rejection, restricted page redirects, and non-Toast authorization.')
