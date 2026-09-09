<?php
require dirname(__DIR__) . '/lib/toast-chat-errors.php';
$failure = ['ok'=>false, 'diagnostic'=>['code'=>'completion_http','http_status'=>400,'model'=>'test-model','provider_code'=>'model_not_found','message'=>'SECRET']];
foreach ([false, true] as $admin) {
    $error = toast_chat_admin_error($failure,$admin);
    if (!$admin && $error !== null) throw new RuntimeException('diagnostic leaked to non-admin');
    if ($admin && (!str_contains($error,'HTTP 400') || !str_contains($error,'model_not_found') || str_contains($error,'SECRET'))) throw new RuntimeException('invalid admin diagnostic');
}
if (toast_chat_admin_error(['ok'=>true],true)!==null) throw new RuntimeException('success showed error');
echo "Admin diagnostic authorization and redaction checks passed.\n";
