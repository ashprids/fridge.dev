<?php

require dirname(__DIR__) . '/lib/toast.php';
require dirname(__DIR__) . '/lib/feed.php';

function toastAutoReplyCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

toastAutoReplyCheck(fridge_toast_should_auto_reply_to_feed('toast', [
    'type' => 'reply', 'parentId' => '', 'username' => 'alice', 'body' => 'hello',
]), 'top-level reply to Toast post was rejected');
toastAutoReplyCheck(!fridge_toast_should_auto_reply_to_feed('toast', [
    'type' => 'reply', 'parentId' => 'parent', 'username' => 'bob', 'body' => 'replying to Alice',
]), 'unmentioned nested reply to Toast post was accepted');
toastAutoReplyCheck(fridge_toast_should_auto_reply_to_feed('toast', [
    'type' => 'reply', 'parentId' => 'parent', 'username' => 'bob', 'body' => 'what do you think, @toast?',
]), 'nested @toast mention was rejected');
toastAutoReplyCheck(fridge_toast_should_auto_reply_to_feed('alice', [
    'type' => 'post', 'username' => 'alice', 'body' => 'hello @toast',
]), 'post @toast mention was rejected');
toastAutoReplyCheck(!fridge_toast_should_auto_reply_to_feed('toast', [
    'type' => 'reply', 'parentId' => '', 'username' => 'toast', 'body' => '@toast loop',
]), 'Toast was allowed to reply to himself');
toastAutoReplyCheck(fridge_toast_feed_reply_parent_id([
    'type' => 'reply', 'id' => 'trigger_reply-1',
]) === 'trigger_reply-1', 'automatic comment reply did not retain its direct parent');
toastAutoReplyCheck(fridge_toast_feed_reply_parent_id([
    'type' => 'post', 'id' => 'post-id',
]) === '', 'automatic post reply was incorrectly nested');

$mention = fridge_feed_markdown_inline('hello @toast');
toastAutoReplyCheck(str_contains($mention, '<code class="feed-account-mention"'), '@toast was not rendered as inline code');
toastAutoReplyCheck(str_contains($mention, '>@toast</code>'), '@toast inline-code text was malformed');

echo "Toast reply eligibility and mention rendering checks passed.\n";
