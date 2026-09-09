<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/mdpaste/lib.php';
require_once dirname(__DIR__) . '/lib/feed.php';

function check_attributed_quote(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$markdown = <<<'MARKDOWN'
> [!QUOTE Grace Hopper]
> The most **dangerous** phrase.
>
> A second paragraph.
MARKDOWN;

foreach ([
    'restricted' => mdp_render_markdown($markdown),
    'trusted' => mdp_render_trusted_markdown($markdown),
    'feed' => fridg3_feed_render_v2_markdown($markdown),
] as $renderer => $html) {
    check_attributed_quote(str_contains($html, '<figure class="markdown-attributed-quote">'), "$renderer renderer omitted the attributed quote wrapper");
    check_attributed_quote(str_contains($html, '<cite>Grace Hopper</cite>'), "$renderer renderer omitted the attribution");
    check_attributed_quote(str_contains($html, '<strong>dangerous</strong>'), "$renderer renderer lost quote formatting");
}

$unsafeName = '> [!QUOTE <img src=x onerror=alert(1)>]' . "\n> Safe body.";
foreach ([mdp_render_markdown($unsafeName), mdp_render_trusted_markdown($unsafeName), fridg3_feed_render_v2_markdown($unsafeName)] as $html) {
    check_attributed_quote(!str_contains($html, '<img src=x'), 'Renderer emitted HTML from an attribution');
    check_attributed_quote(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;'), 'Renderer did not preserve the escaped attribution');
}

$ordinary = mdp_render_markdown('> An ordinary quote.');
check_attributed_quote(str_contains($ordinary, '<blockquote>') && !str_contains($ordinary, 'markdown-attributed-quote'), 'Ordinary blockquote behavior changed');

echo "Markdown attributed quote checks passed.\n";
