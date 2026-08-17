# Theme Development Instructions

This directory contains selectable themes for fridge.dev. Blackprint is the built-in default and is implemented by `/template.html`, `/template_mobile.html`, and the Blackprint layer near the end of `/style.css`. It is not a JSON theme package. Classic is the retained selectable reference theme in `/themes/classic.json` and `/themes/lib/classic/`.

Treat `/style.css` as the source of truth. Read it before creating or substantially changing a theme. It contains the complete shared component system, accessibility behavior, responsive rules, Blackprint implementation, and final cross-theme invariants. Do not copy deleted themes or assume an older selector inventory is still accurate.

## Theme Package Contract

A selectable theme has:

- `/themes/{theme-id}.json`
- `/themes/lib/{theme-id}/{template}.html`
- `/themes/lib/{theme-id}/{stylesheet}.css`
- `/themes/thumbnails/{theme-id}.svg` (normally a 4:3 preview)

Theme IDs use lowercase `a-z`, `0-9`, `_`, and `-`. The JSON filename without `.json` is the saved theme ID.

Required metadata:

```json
{
  "name": "Theme Name",
  "description": "short picker description",
  "thumbnail": "thumbnails/theme-id.svg",
  "html": "theme-id/theme-id.html",
  "css": "theme-id/theme-id.css"
}
```

An optional `"base": "blackprint"` field makes the renderer add both the package's `{theme-id}-theme` body class and `blackprint-theme`. Use it for a deliberate Blackprint derivative so the package inherits the current Blackprint layout and component coverage instead of copying that layer. `blackprint` is currently the only supported base value.

`thumbnail` is relative to `/themes`; `html` and `css` are relative to `/themes/lib`. Asset paths may contain only letters, numbers, `.`, `_`, `-`, and `/`. Never use absolute paths, `..`, empty segments, query strings, or shell-like filenames.

The renderer discovers valid JSON files dynamically. Blackprint is exposed as `default`; packaged themes receive a `{theme-id}-theme` body class. Missing, malformed, unsupported-base, or unsafe packages are ignored. Desktop may use the package HTML; mobile always uses `/template_mobile.html` and only appends the selected theme CSS.

## Base Stylesheet Map

`/style.css` is intentionally ordered into five cascade sections:

1. Foundations: design variables, selection, loading UI, local fonts, emoji fallback, ASCII/resource typography, global text and element behavior.
2. Site shell: page wrapper, sidebar, title and title animations, navigation, content layout, notifications, mini-player, footer, sliders, and scrollbars.
3. Shared controls: checkboxes, settings panels, theme/title/Fruity Dance pickers, forms, popups, prompts, media corruption effects, tables, Markdown/BBCode surfaces, pagination, toolbars, and common responsive rules.
4. Page features: route-specific feed, journal, account, chat, gallery, upload, admin, notification, music, and tool interfaces.
5. Theme layers: the full Blackprint skin, Blackprint mobile coverage, application-specific cleanup, and final rules that intentionally apply across every theme.

Rules remain in cascade order because later responsive, theme, and coverage rules intentionally override earlier component defaults. A theme stylesheet is appended after the shared stylesheet, but inline mobile rules can still require scoped `!important` overrides.

## Design Variables

Set the core variables at the start of a theme stylesheet:

```css
:root {
    --bg: #000000;
    --fg: #eeeeee;
    --border: #3c7895;
    --subtle: #917daa;
    --links: #415fad;
    --chat-own-fg: #ffffff;
}
```

The shared stylesheet also derives or consumes:

- `--hero-ascii-muted` and `--hero-ascii-1` through `--hero-ascii-7`
- `--hero-ascii-opacity`
- `--resource-ascii`, `--resource-label`, `--time-ascii`, `--time-label`
- `--emoji-font`
- `--selection-bg`, `--selection-fg`
- `--scrollbar-track`, `--scrollbar-thumb`, `--scrollbar-thumb-hover`, `--scrollbar-thumb-border`
- `--chat-own-bg` in the shared chat layer

Prefer overriding variables over duplicating component rules. Add direct component overrides where the theme changes shape, spacing, typography, imagery, or layout. Classic alone currently exposes account-synced custom values for `bg`, `fg`, `border`, `subtle`, and `links`; adding color controls for another theme requires coordinated changes in `/js/settings.js` and `/api/settings/index.php`.

Available shared fonts are `MainRegular`, `MainBold`, `MainItalic`, `MainBoldItalic`, `IBM_VGA`, `Title`, and the `var(--emoji-font)` fallback stack. Preserve the emoji fallback in custom font families.

## Templates And Runtime Contracts

Start desktop work from `/template.html` unless the requested design needs a different layout. Preserve:

- `{title}`, `{description}`, `{content}`, and `{user_greeting}`
- the content mount IDs `#container`, `#content`, `#content-layout`, and `#content-main`
- sidebar/navigation access, including home, account, and settings
- title markup with `#title` and per-character `.title-letter` spans
- mini-player IDs and the persistent `#mini-player-audio` element
- sidebar/footer/notification hooks used by `/js/sidebar-player.js`
- Font Awesome, Highlight.js, favicon, manifest, `/style.css`, and shared script includes

The site uses SPA content replacement while leaving the shell and audio element mounted. Do not duplicate IDs, replace the shared audio element during navigation, or remove runtime hooks without updating and verifying the JavaScript.

Themes may radically rearrange the desktop shell, but content and navigation must remain usable. Avoid adding padding directly to `#content-main` when a decorative wrapper or `#content-layout::before` can add breathing room without reducing the page's usable width.

## Components To Cover

At minimum, inspect the current declarations in `/style.css` for every component the theme visibly changes:

- shell: `body`, `.paper-grain`, `#page-wrapper`, `#sidebar`, `#header`, `#title`, `.title-letter`, `#tab`, `#container`, `#content`, `#content-layout`, `#content-main`
- navigation/status: active tabs, sidebar notifications, active chat, maintenance/developer indicators, tooltips, notification toasts
- footer: `#sidebar-footer`, `#footer-text`, `#footer-buttons`, `#footer-button`
- player: `#mini-player`, `#mini-player-main`, art wrapper/image/download, metadata/title/artist, play/mute/close controls, seek/volume sliders, track list, `.mini-track` hover/active states, live-stream states
- forms: text inputs, textareas, selects/dropdowns, buttons, radios, checkboxes, color inputs, file controls, field help, disabled/error/success states
- pickers/popups: theme picker, title-animation picker, Fruity Dance controls, site popup/prompt/dialog surfaces
- content: headings, links, lists, blockquotes, code/pre, Markdown/BBCode, spoilers, tables, embedded media, pager controls, posts/cards
- route features: feed/journal editors and cards, accounts/settings/admin, chat and reply UI, gallery/upload, notifications, music albums, tools, logs, wiki/formatting pages
- ASCII/resource displays: homepage hero, server time/resources, labels, scaling containers

Use the stylesheet's actual selectors rather than inventing parallel markup. Search for a route or component name before adding overrides.

## Title And Motion

The shared title uses clipped per-letter backgrounds and runtime-selectable wobble, bounce, rubberhose, bubble, slot-machine, moonwalk, and heartbeat animations. Mobile Safari requires gradients on the individual `.title-letter` elements. Keep transform-safe overflow and padding intact unless the replacement is verified with every animation.

Do not override the shared title's `font-family` in packaged theme styles. Themes may change the title's colour, gradient, decoration, and surrounding panel, but `#title`, `.title-letter`, `.mobile-collapsed-title`, and its letters must retain the shared title typeface. Classic is the existing legacy exception because its original title treatment is part of that preserved theme.

Respect both `@media (prefers-reduced-motion: reduce)` and `html.access-reduced-motion`. Theme animation is decorative and must be suppressible. The global reduced-motion rule forces animation and transition durations down to 1ms; do not defeat it with later theme rules.

## Mobile Requirements

Mobile uses `/template_mobile.html`, ignores theme HTML, and appends theme CSS. Scope mobile work under `body.mobile-template`. The template contains strong inline layout defaults, so targeted `!important` is sometimes necessary.

Verify at least:

- page background, collapsed header, brand/title, menu button, unread badge, backdrop, and expanded menu panel
- `body.mobile-template #sidebar` preserves the shared safe gutter (`width: calc(100% - 16px)`), uses `min-width: 0`, `max-width: none`, border-box sizing, and never overflows the viewport
- nav and footer buttons in normal, hover, active, and active-hover states with explicit readable foregrounds
- content/layout/main backgrounds and spacing; light themes must replace default dark panels
- complete mini-player and track-list styling
- inputs, textareas, dropdowns, BBCode controls, radios, checkboxes, and color/file controls
- narrow content, long words, code blocks, tables, ASCII art, and media without horizontal page overflow

Do not reintroduce a separate collapsed branded header: the current mobile template uses the fixed menu control and expanded sidebar title. Preserve the shared open/close transforms, backdrop behavior, document scroll locking, and reduced-motion overrides.

## Accessibility And Shared Invariants

Themes must maintain readable contrast, distinct links, visible focus/hover/active states, usable target sizes, and unclipped content. Decorative backgrounds need opaque-enough content surfaces.

The shared accessibility layer supplies high contrast through the core variables. If a future light theme needs a light high-contrast mode, add an explicit theme-scoped override rather than a generic name list.

The final rules in `/style.css` are intentional cross-theme contracts:

- sidebar mini-player, track list, and footer remain shadow-free
- mini-player close controls retain their compact pinned geometry
- mobile title letters retain independent gradient painting and transform-safe spacing
- mobile Always Playing title setting stays hidden
- the mobile menu toggle remains square
- chat controls and quoted replies remain flat and readable
- the sidebar suppresses text selection and native image/link dragging while controls remain interactive

Do not casually override these rules in a theme.

## CSS Practices

- Do not `@import /style.css`; the renderer already loads it.
- Scope theme-specific rules to the theme body class added by the renderer.
- Set both foreground and background for hover/active states, including pseudo-elements.
- Reuse shared variables and component markup.
- Use `!important` only to beat known inline/mobile or final shared declarations.
- Keep asset URLs rooted under the theme package when practical.
- Avoid broad selectors that leak into editor previews, popups, or mobile unintentionally.
- Preserve reduced motion, high contrast, keyboard focus, and touch behavior.

## Validation

After creating or changing a theme:

1. Validate metadata:

```bash
php -r '$v=json_decode(file_get_contents("themes/{theme-id}.json"), true); echo json_last_error_msg(), "\n";'
```

2. Verify discovery:

```bash
php -r 'require "lib/render.php"; echo json_encode(array_values(array_map(fn($t) => ["id" => $t["id"], "name" => $t["name"]], fridg3_list_themes(__DIR__))), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";'
```

3. Lint and check whitespace:

```bash
php -l lib/render.php
git diff --check -- themes/{theme-id}.json themes/lib/{theme-id} themes/thumbnails/{theme-id}.svg
```

4. Test desktop and mobile, normal and reduced motion, keyboard focus, long content, forms, mini-player states, and the routes whose components the theme overrides.

Do not start a development server; assume one is already running if preview is needed.

## Documentation

Update `/wiki/Frontend-and-Templates.md`, `/wiki/Routing-and-Rendering.md`, `/wiki/Data-Contracts.md`, and `/wiki/API-Reference.md` when the package contract, renderer, settings behavior, color support, or shared theme architecture changes. A normal new theme usually needs only a concise catalog mention if its behavior is noteworthy.
