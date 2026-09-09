<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/lib/session.php';
require_once dirname(__DIR__,2).'/lib/toast-feed-reply.php';
fridg3_start_session();
fridg3_feed_refresh_session_user();
header('Content-Type: application/json'); header('Cache-Control: no-store');
function toast_reply_response(array $data, int $status=200): never { http_response_code($status); echo json_encode($data); exit; }
if (!fridg3_toast_is_current_user() || fridg3_current_user_posting_restricted()) toast_reply_response(['ok'=>false,'error'=>'Only an unrestricted Toast account can generate replies.'],403);
if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') toast_reply_response(['ok'=>false,'error'=>'Method not allowed.'],405);
if (!is_string($_POST['csrf_token']??null) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) toast_reply_response(['ok'=>false,'error'=>'Invalid request token. Reload the page.'],403);
$postId = (string)($_POST['post_id']??''); $parent=(string)($_POST['parent_reply_id']??'');
if (!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$postId)) toast_reply_response(['ok'=>false,'error'=>'Invalid feed post.'],400);
$raw=@file_get_contents(fridg3_feed_posts_dir().'/'.$postId.'.txt');
if ($raw===false) toast_reply_response(['ok'=>false,'error'=>'The feed post is no longer available.'],404);
$post=fridg3_feed_parse_post($raw);$post['id']=$postId;
try { $context=toast_reply_context($post,fridg3_feed_load_replies($postId),$parent); }
catch (InvalidArgumentException $e) { toast_reply_response(['ok'=>false,'error'=>$e->getMessage()],404); }
$groq=fridg3_toast_load_groq_config();
if (!$groq['api_key']) toast_reply_response(['ok'=>false,'error'=>'Set a Groq API key in Toast settings first.'],503);
if (!function_exists('curl_init')) toast_reply_response(['ok'=>false,'error'=>'PHP cURL is required to generate replies.'],503);
if (!in_array($groq['model'],toast_active_models($groq['api_key'])??[],true)) toast_reply_response(['ok'=>false,'error'=>'The feed reply model is unavailable or could not be verified. Check Toast settings.'],503);
$payload=toast_reply_payload($context,$groq);
$ch=curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$groq['timeout_seconds'],CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$groq['api_key'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
if ($response===false) toast_reply_response(['ok'=>false,'error'=>'Could not reach Groq. Try again.'],502);
if ($status>=400) toast_reply_response(['ok'=>false,'error'=>'Groq rejected the reply request (HTTP '.$status.').'],502);
$data=json_decode($response,true);$text=$data['choices'][0]['message']['content']??null;
if (!is_string($text) || trim($text)==='') toast_reply_response(['ok'=>false,'error'=>'Groq returned no reply text. Try again or check the model and token budget.'],502);
$text=trim($text);
if (strlen($text)>4000) toast_reply_response(['ok'=>false,'error'=>'The generated reply was too long. Generate a shorter reply again.'],502);
$token=bin2hex(random_bytes(24));
$_SESSION['toast_reply_drafts']=array_filter($_SESSION['toast_reply_drafts']??[],static fn($draft)=>is_array($draft)&&($draft['expires']??0)>=time());
$_SESSION['toast_reply_drafts']=array_slice($_SESSION['toast_reply_drafts'],-19,null,true);
$_SESSION['toast_reply_drafts'][$token]=['post'=>$postId,'parent'=>$parent,'expires'=>time()+3600];
toast_reply_response(['ok'=>true,'reply'=>$text,'token'=>$token]);
