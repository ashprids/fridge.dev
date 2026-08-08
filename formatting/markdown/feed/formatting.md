# Feed Markdown formatting

This page showcases the formatting syntax supported in Feed posts and replies. To view the raw syntax, press the !fa solid code button.

## Bold and emphasis

**Bold text**

*Italic text*

**Bold with *nested italic* text**

## Additional inline styles

<u>Underlined text</u>

~~Strikethrough text~~

==Highlighted text==

||Spoiler text — hover or select to reveal it.||

## Links and media

[External link](https://example.com)

[Internal link](/feed)

![Image description](/resources/favicon.svg)

Uploaded audio and video use the same media button and feed players as normal posts.

## Font Awesome icons

Use `!fa`, an icon style, and an icon name: !fa solid code.

[Browse all available free icons](https://fontawesome.com/search?ic=free-collection).

Use `!frdg` to display the fridge.dev site icon inline: !frdg

## Blockquotes

> This is a blockquote.
> It can contain **bold**, *italic*, `inline code`, and ||spoilers||.

## Unordered and ordered lists

- Unordered item
- Another unordered item
    - Nested unordered item
    1. Nested ordered item

1. First ordered item
2. Second ordered item
    1. Nested ordered item
    2. Another nested ordered item

## Inline code

Use `const message = "hello";` within a sentence. Formatting such as `**bold**` remains literal inside code.

## Fenced code blocks

```
const greeting = "hello";
console.log(greeting);
```

## Tables

| Element | Example |
| --- | --- |
| Bold | **Important** |
| Italic | *Emphasis* |
| Code | `example()` |
| Spoiler | ||Hidden|| |
| Link | [Open](/feed) |
