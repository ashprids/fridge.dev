<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/mdpaste/lib.php';
require_once dirname(__DIR__) . '/lib/feed.php';

function check_markdown_html_policy(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$markdown = <<<'MARKDOWN'
# HTML policy

<section data-test="raw">
<style>.raw-html-test { color: red; }</style>
<script>window.rawHtmlTest = true;</script>
<button type="button" onclick="window.rawHtmlClicked = true">test</button>
</section>

Inline <span class="kept" title="1 > 0" onclick="window.inlineHtmlClicked = true">HTML</span>.

<!-- preserved only for trusted content -->
MARKDOWN;

$restricted = mdp_render_markdown($markdown);
check_markdown_html_policy(!str_contains($restricted, '<section data-test="raw">'), 'Restricted renderer emitted a raw section');
$scriptOpen = '<scr' . 'ipt>';
$scriptBlock = $scriptOpen . 'window.rawHtmlTest = true;</script>';
$onclickAttribute = 'on' . 'click=';
check_markdown_html_policy(!str_contains($restricted, $scriptOpen), 'Restricted renderer emitted a script');
check_markdown_html_policy(!str_contains($restricted, '<span class="kept" ' . $onclickAttribute), 'Restricted renderer retained an event attribute');
check_markdown_html_policy(!str_contains($restricted, '<!--'), 'Restricted renderer retained an HTML comment');
check_markdown_html_policy(str_contains($restricted, '<span class="kept" title="1 &gt; 0">HTML</span>'), 'Restricted renderer lost its existing safe HTML support');

$trusted = mdp_render_trusted_markdown($markdown);
check_markdown_html_policy(str_contains($trusted, '<section data-test="raw">'), 'Trusted renderer escaped a raw section');
check_markdown_html_policy(str_contains($trusted, $scriptBlock), 'Trusted renderer changed a script block');
check_markdown_html_policy(str_contains($trusted, $onclickAttribute . '"window.rawHtmlClicked = true"'), 'Trusted renderer removed an event attribute');
check_markdown_html_policy(str_contains($trusted, '<span class="kept" title="1 > 0" ' . $onclickAttribute . '"window.inlineHtmlClicked = true">'), 'Trusted renderer changed an inline HTML attribute');
check_markdown_html_policy(str_contains($trusted, '<!-- preserved only for trusted content -->'), 'Trusted renderer removed an HTML comment');

$voidElement = mdp_render_trusted_markdown("<hr>\n\ncontent after the void element");
check_markdown_html_policy(str_contains($voidElement, "<hr>\n<p>content after the void element</p>"), 'Trusted renderer consumed content after a void element');

$nestedDiv = mdp_render_trusted_markdown(<<<'MARKDOWN'
<div id="grid">
    <div class="grid-item" onclick="window.open('/example')">
        <img class="grid-image" src="example.jpg" alt="example">
        <div class="grid-caption">example</div>
    </div>
</div>

## Content after grid
MARKDOWN);
check_markdown_html_policy(str_contains($nestedDiv, '<div id="grid">'), 'Trusted renderer escaped the outer grid div');
check_markdown_html_policy(str_contains($nestedDiv, '<div class="grid-item" ' . $onclickAttribute . '"window.open(\'/example\')">'), 'Trusted renderer changed a nested grid div');
check_markdown_html_policy(!str_contains($nestedDiv, '<pre'), 'Trusted renderer treated nested grid HTML as indented code');
check_markdown_html_policy(str_contains($nestedDiv, '<h2 id="content-after-grid">Content after grid</h2>'), 'Trusted renderer consumed Markdown after a nested grid');

$autolinks = mdp_render_trusted_markdown("<example.com>, <person@example.com>\n\n## Content after autolinks");
check_markdown_html_policy(str_contains($autolinks, '<a href="https://example.com"'), 'Trusted renderer did not render a leading URL autolink');
check_markdown_html_policy(str_contains($autolinks, '<a href="mailto:person@example.com"'), 'Trusted renderer did not render a leading email autolink');
check_markdown_html_policy(str_contains($autolinks, '<h2 id="content-after-autolinks">Content after autolinks</h2>'), 'Trusted renderer consumed content after leading autolinks');

$abbreviation = mdp_render_trusted_markdown("*[Need]: This is a definition\n\nNeed a website or want an idea to come to life\nin your browser?");
check_markdown_html_policy(str_contains($abbreviation, '<abbr data-tooltip="This is a definition" data-markdown-abbreviation="1">Need</abbr>'), 'Abbreviation definition did not use the site tooltip');
check_markdown_html_policy(!str_contains($abbreviation, '<abbr title='), 'Abbreviation definition retained a native browser tooltip');
check_markdown_html_policy(str_contains($abbreviation, 'life in your browser?</p>'), 'Leading abbreviation split a soft-wrapped Markdown paragraph');

$explicitAbbreviation = mdp_render_trusted_markdown('<abbr title="Application Programming Interface">API</abbr>');
check_markdown_html_policy(str_contains($explicitAbbreviation, '<abbr data-tooltip="Application Programming Interface">API</abbr>'), 'Explicit abbreviation did not use the site tooltip');
check_markdown_html_policy(!str_contains($explicitAbbreviation, '<abbr title='), 'Explicit abbreviation retained a native browser tooltip');
check_markdown_html_policy(!str_contains($explicitAbbreviation, 'data-markdown-abbreviation'), 'Explicit abbreviation was marked as Markdown abbreviation syntax');

$nestedAbbreviation = mdp_render_trusted_markdown("*[JavaScript]: A programming language\n*[JS]: JavaScript: A programming language\n\nJS");
check_markdown_html_policy(str_contains($nestedAbbreviation, '<abbr data-tooltip="JavaScript: A programming language" data-markdown-abbreviation="1">JS</abbr>'), 'Abbreviation tooltip text was recursively expanded');
check_markdown_html_policy(substr_count($nestedAbbreviation, '<abbr') === 1, 'Abbreviation tooltip created a nested abbreviation element');
$legacyNestedAbbreviation = mdp_render_markdown_legacy("*[JavaScript]: A programming language\n*[JS]: JavaScript: A programming language\n\nJS");
check_markdown_html_policy(substr_count($legacyNestedAbbreviation, '<abbr') === 1, 'Legacy abbreviation tooltip created a nested abbreviation element');

$protectedTooltip = mdp_render_trusted_markdown("*[JS]: A programming language\n\n<abbr title=\"Use JS here\">term</abbr>");
check_markdown_html_policy(str_contains($protectedTooltip, '<abbr data-tooltip="Use JS here">term</abbr>'), 'Existing tooltip text was processed as abbreviation Markdown');

$feed = fridge_feed_render_post_body($markdown, 'v2');
check_markdown_html_policy(!str_contains($feed, '<script>'), 'Feed renderer emitted a script');
check_markdown_html_policy(!str_contains($feed, '<button type="button" ' . $onclickAttribute), 'Feed renderer retained an event attribute');

$rawFeedMedia = fridge_feed_render_post_body("https://i.pinimg.com/example/photo.jpg?width=900\n\nhttps://cdn.example.com/audio/song.mp3\n\nhttps://cdn.example.com/video/clip.webm", 'v2');
check_markdown_html_policy(str_contains($rawFeedMedia, '<img src="https://i.pinimg.com/example/photo.jpg?width=900"'), 'Feed renderer did not embed a raw image URL');
check_markdown_html_policy(str_contains($rawFeedMedia, 'src="https://cdn.example.com/audio/song.mp3"'), 'Feed renderer did not embed a raw audio URL');
check_markdown_html_policy(str_contains($rawFeedMedia, 'src="https://cdn.example.com/video/clip.webm"'), 'Feed renderer did not embed a raw video URL');

$moderationFilter = fridge_feed_apply_guest_filter('before fuck after', true, true);
$moderationFilterHtml = fridge_feed_render_post_body($moderationFilter, 'v2');
check_markdown_html_policy(str_contains($moderationFilterHtml, 'data-context-tooltip="original: fuck"'), 'Feed renderer omitted the moderation-only filtered-word context');
$publicFilterHtml = fridge_feed_render_post_body(fridge_feed_apply_guest_filter('before fuck after', true), 'v2');
check_markdown_html_policy(!str_contains($publicFilterHtml, 'original: fuck'), 'Feed renderer exposed a filtered word in public output');

echo "Markdown trusted/restricted HTML policy checks passed.\n";
