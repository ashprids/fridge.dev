<?php
require dirname(__DIR__).'/lib/toast-feed-reply.php';
function replyCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$post=['id'=>'p1','username'=>'author','body'=>'A post about building a radio.'];
$replies=[
 ['id'=>'target','username'=>'first','body'=>'Which stream format?'],
 ['id'=>'child','parentId'=>'target','username'=>'second','body'=>'Try M3U.'],
 ['id'=>'nested','parentId'=>'child','username'=>'third','body'=>'HTTPS works too.'],
 ['id'=>'unrelated','username'=>'fourth','body'=>'Unrelated discussion.'],
];
$root=toast_reply_context($post,$replies,'');
replyCheck($root['reply_to']==='post'&&$root['post']['text']===$post['body']&&$root['target_comment']===null,'post context mismatch');
$thread=toast_reply_context($post,$replies,'target');
replyCheck($thread['post']['text']===$post['body']&&$thread['target_comment']['text']===$replies[0]['body'],'target missing');
replyCheck(array_column($thread['comment_replies'],'id')===['child','nested'],'wrong descendants');
try {toast_reply_context($post,$replies,'missing');throw new RuntimeException('accepted missing target');}catch(InvalidArgumentException $expected){}
$groq=['model'=>'test-model','temperature'=>.8,'top_p'=>.95,'max_completion_tokens'=>700];
$payload=toast_reply_payload($thread,$groq);
replyCheck(json_decode($payload['messages'][2]['content'],true)===$thread,'payload dropped context');
$_SESSION['toast_reply_drafts']['token']=['post'=>'p1','parent'=>'target','expires'=>time()+100];
replyCheck(toast_reply_token_valid('p1','target','token'),'valid draft rejected');
replyCheck(!toast_reply_token_valid('p2','target','token')&&!toast_reply_token_valid('p1','','token'),'draft authorized wrong target');
$_SESSION['toast_reply_drafts']['token']['expires']=time()-1;
replyCheck(!toast_reply_token_valid('p1','target','token'),'expired draft accepted');
echo "Reply post/thread context, missing-target, and draft binding checks passed.\n";
