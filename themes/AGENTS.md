# Theme Creation Instructions

this directory contains selectable website themes for fridge.dev.

if a user says something like "using this AGENTS.md, create an x based theme", you should be able to make the whole theme from this file plus the existing examples. do the work directly unless the request is dangerously unclear.

## Mental Model

a theme has two parts:

- metadata json in `/themes`
- assets in `/themes/lib`

the metadata file is what makes the theme appear in `/settings`. the filename, minus `.json`, becomes the saved theme id.

example:

```json
{
  "name": "Frutiger Aero",
  "html": "frutiger-aero-template.html",
  "css": "frutiger-aero.css"
}
```

this means:

- settings label: `Frutiger Aero`
- saved id: `example_theme` if the file is `/themes/example_theme.json`
- desktop template: `/themes/lib/frutiger-aero/frutiger-aero-template.html`
- theme stylesheet: `/themes/lib/frutiger-aero/frutiger-aero.css`

## Files To Create

for a new theme, create:

- `/themes/{theme-id}.json`
- `/themes/lib/{theme-id}/{theme-id-or-theme-name}.html`
- `/themes/lib/{theme-id}/{theme-id-or-theme-name}.css`

keep ids lowercase with `a-z`, `0-9`, `_`, and `-`. avoid spaces in filenames.

## Required JSON Rules

theme json must be valid JSON and must include:

- `name`: human-readable label shown in `/settings`
- `description`: short supporting text shown in the on-site theme picker
- `thumbnail`: 4:3 preview image path relative to `/themes`, usually `thumbnails/{theme-id}.svg`
- `html`: relative path inside `/themes/lib`
- `css`: relative path inside `/themes/lib`

do not put paths like `/themes/lib/foo.css` in the json. use paths relative to `/themes/lib`, such as `aero/aero.css`.
do not put paths like `/themes/thumbnails/foo.svg` in the json. use paths relative to `/themes`, such as `thumbnails/aero.svg`.

allowed asset path characters are letters, numbers, `.`, `_`, `-`, and `/`. never use `..`, absolute paths, empty path segments, or weird shell-ish filenames.

## Template Requirements

start from `/template.html` unless the user asks for a radical layout. copy it into `/themes/lib/{theme-id}`, then adapt it.

the template must preserve these placeholders:

- `{title}`
- `{description}`
- `{content}`
- `{user_greeting}`

the template should preserve these scripts/styles unless there is a very good reason:

- Font Awesome CDN link
- Highlight.js CDN CSS and JS
- `/main.js`
- favicon and manifest links

the template should include a stylesheet link to `/style.css`; the renderer appends the selected theme CSS later, so the theme CSS can override base styling.

## Layout Freedom

themes are allowed to change the website layout. this is not just a color-skin system.

you may:

- move the menu
- redesign the sidebar
- make the layout top-nav, bottom-nav, split-panel, dashboard-like, etc.
- add decorative wrappers or background elements
- change spacing, borders, visual density, typography, and component shape

but you must keep:

- a visible content display area containing `{content}`
- a usable menu/navigation of some sort
- account/settings/home navigation available somewhere
- the mini-player markup unless the theme intentionally restyles it
- IDs/classes that `main.js` depends on, unless you also verify and update the JS safely

translation: go wild aesthetically, but do not strand users on a pretty page with no content or nav. that would be deeply goofy.

## Mobile Behavior

mobile view uses `/template_mobile.html`, not the theme HTML file. this is easy to forget and then the theme looks half-baked on `m.fridge.dev`, so treat mobile CSS as required theme work, not a nice extra.

when mobile-friendly view is active:

- only the theme CSS is applied
- the theme HTML is ignored
- write mobile overrides under `body.mobile-template`

this means every theme CSS must include mobile-specific polish if the theme changes core layout, backgrounds, nav buttons, content spacing, cards, forms, the mini-player, or footer controls. almost every real theme changes at least one of these, so most themes should have a substantial `body.mobile-template` section.

important: `/template_mobile.html` contains inline styles with strong defaults. these include black backgrounds for nav buttons, player/footer blocks, and `#content-main`. if you do not override these with `body.mobile-template ... !important`, light themes will get random black panels and dark themes may get default-looking controls.

important mobile selectors that often need theme overrides:

- `body.mobile-template`
- `body.mobile-template #sidebar`
- `body.mobile-template .mobile-collapsed-header`
- `body.mobile-template .mobile-collapsed-brand`
- `body.mobile-template .mobile-collapsed-subtitle`
- `body.mobile-template .mobile-collapsed-title`
- `body.mobile-template #show-sidebar`
- `body.mobile-template .mobile-nav-grid`
- `body.mobile-template .mobile-nav-grid a.mobile-nav-link > #tab.mobile-nav-button`
- `body.mobile-template .mobile-nav-grid a.mobile-nav-link > #tab.mobile-nav-button:hover`
- `body.mobile-template .mobile-nav-grid a.mobile-nav-link > #tab.mobile-nav-button.active`
- `body.mobile-template .mobile-nav-grid a.mobile-nav-link > #tab.mobile-nav-button.active:hover`
- `body.mobile-template #footer-buttons`
- `body.mobile-template #footer-buttons > a.mobile-footer-link > #footer-button.mobile-footer-button`
- `body.mobile-template #footer-buttons > a.mobile-footer-link > #footer-button.mobile-footer-button:hover`
- `body.mobile-template #footer-buttons > a.mobile-footer-link > #footer-button.mobile-footer-button.active`
- `body.mobile-template #footer-buttons > a.mobile-footer-link > #footer-button.mobile-footer-button.active:hover`
- `body.mobile-template #content`
- `body.mobile-template #content-layout`
- `body.mobile-template #content-main`
- `body.mobile-template #mini-player`
- `body.mobile-template #mini-player-tracks`
- `body.mobile-template #mini-player-art-wrapper`
- `body.mobile-template #mini-player-art`
- `body.mobile-template #mini-player-download`
- `body.mobile-template #mini-player-play`
- `body.mobile-template #mini-player-controls span`
- `body.mobile-template #mini-player-seek`
- `body.mobile-template #mini-player-volume`
- `body.mobile-template .mini-track`
- `body.mobile-template .mini-track:hover`
- `body.mobile-template .mini-track.active`
- `body.mobile-template #sidebar-footer`
- `body.mobile-template input`
- `body.mobile-template textarea`
- `body.mobile-template .dropdown`
- `body.mobile-template .text-input`
- `body.mobile-template .bbcode-dropdown`
- `body.mobile-template .bbcode-btn`

mobile CSS may need `!important` because `template_mobile.html` has inline mobile-specific styles. use it where necessary, not everywhere like a maniac.

minimum mobile checklist for every theme:

- theme the page background
- theme the collapsed header and menu button
- theme the expanded menu panel, and force `body.mobile-template #sidebar` back to `width: 100% !important; min-width: 0 !important; max-width: none !important;` so desktop sidebar widths do not make the opened mobile menu skinny
- theme mobile nav buttons, including hover, active, and active-hover states; every state must explicitly set readable text/icon colors
- theme footer buttons, including hover, active, and active-hover states; every state must explicitly set readable text/icon colors
- theme the mini-player, track list, album art, download overlay, play/mute controls, title text, sliders, track rows, hover states, and active track states
- theme `#content-layout` and `#content-main`; especially remove the mobile template's default black `#content-main` background when making a light theme
- theme inputs, dropdowns, textareas, BBCode controls, radios, checkboxes, and color inputs if the theme is light or uses non-default colors
- keep spacing tight enough for small screens without horizontal scrolling

## CSS Strategy

theme CSS is appended after the base stylesheet. normally do not `@import url('/style.css')` in theme CSS, because that can reload base styles after mobile styles and break mobile theming.

set the core color variables early:

```css
:root {
    --bg: #000000;
    --fg: #eeeeee;
    --border: #3c7895;
    --subtle: #917daa;
    --links: #415fad;
}
```

then override components directly.

common components:

- `body`
- `#page-wrapper`
- `#sidebar`
- `#header`
- `#title`
- `#tab`
- `#container`
- `#content`
- `#content-layout`
- `#content-main`
- `#post`
- `#search`
- `#search-box`
- `#search-button`
- `#footer-buttons`
- `#footer-button`
- `#mini-player`
- `#mini-player-tracks`
- `#mini-player-art-wrapper`
- `#mini-player-art`
- `#mini-player-download`
- `#mini-player-play`
- `#mini-player-controls span`
- `#mini-player-seek`
- `#mini-player-volume`
- `.mini-track`, `.mini-track:hover`, `.mini-track.active`
- form inputs, buttons, `.dropdown`, `.radio`, `.checkbox`

## frdgBeats Theming

frdgBeats lives at `/tools/frdgbeats/` and must be treated as part of every theme. the default frdgBeats stylesheet intentionally keeps the original default DAW look. each selected theme stylesheet should override frdgBeats through `.frdgbeats-daw` variables, usually derived from the normal theme variables (`--bg`, `--fg`, `--border`, `--subtle`, `--links`):

- `--fdgb-panel`, `--fdgb-panel-soft`, `--fdgb-panel-strong`
- `--fdgb-popover`, `--fdgb-canvas`, `--fdgb-hover`, `--fdgb-overlay`
- `--fdgb-line`, `--fdgb-border-strong`, `--fdgb-border-medium`, `--fdgb-border-soft`, `--fdgb-border-faint`
- `--fdgb-hot`, `--fdgb-warm`, `--fdgb-muted`, `--fdgb-danger-base`, `--fdgb-danger`
- `--fdgb-hot-soft`, `--fdgb-hot-mid`, `--fdgb-hot-strong`, `--fdgb-warm-soft`, `--fdgb-warm-mid`, `--fdgb-focus`
- `--fdgb-note-fill`, `--fdgb-note-fill-slide`, `--fdgb-note-fg`
- `--fdgb-piano-white`, `--fdgb-piano-black`, `--fdgb-swatch-border`, `--fdgb-code-bg`

when creating or updating a theme, preview `/tools/frdgbeats/` and make sure the toolbar, channel rack, menus, credits modal, piano roll, playlist, mixer, automation grid, sample editor, meters, sliders, toggles, buttons, and popovers are readable and feel like the theme. synth and effect custom editors keep their own unique styling across every theme, so do not hardcode theme overrides into their internal panel/control classes. if the default derived variables are not enough, override the `--fdgb-*` variables inside the theme CSS rather than hardcoding frdgBeats component selectors everywhere.

when styling menus, do not only set the background on hover/active states. set the foreground color too. this includes `#tab:hover`, `#tab.active`, `#footer-button:hover`, `#footer-button.active`, mobile nav buttons, mobile footer buttons, and pseudo-elements like `#tab::before` if the theme uses them. invisible active menu text is a tiny little css jump scare and it is your job to prevent it.

## Content Spacing

be careful with padding on `#content-main`: it reduces usable width for page content. if the user asks for more breathing room without shrinking content, prefer outer margins, pseudo-elements, or background wrappers.

good pattern:

```css
#content-layout {
    position: relative;
    isolation: isolate;
}

#content-layout::before {
    content: "";
    position: absolute;
    inset: -20px;
    z-index: -1;
    pointer-events: none;
}
```

this makes the visual panel larger without stealing content width.

## Accessibility And Usability

keep themes readable:

- preserve strong text/background contrast
- make links visually distinct
- make focus/hover states visible
- keep buttons and controls large enough to click
- avoid hiding overflow in ways that cut off content
- check long words, ASCII art, forms, and post cards

if a theme uses very decorative backgrounds, make content panels opaque enough to read.

## Validation

after creating or changing a theme:

1. validate json:

```bash
php -r 'json_decode(file_get_contents("themes/{theme-id}.json"), true); echo json_last_error_msg(), "\n";'
```

2. check the theme loader sees it:

```bash
php -r 'require "lib/render.php"; echo json_encode(array_values(array_map(fn($t)=>["id"=>$t["id"],"name"=>$t["name"]], fridg3_list_themes(__DIR__))), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";'
```

3. check whitespace:

```bash
git diff --check -- themes/{theme-id}.json themes/lib/{theme-id}/{theme}.html themes/lib/{theme-id}/{theme}.css
```

4. if PHP render helper changed, run:

```bash
php -l lib/render.php
```

do not start a dev server for this repo. assume one is already running if preview is needed.

## Documentation

if you change how themes work, update the wiki. if you only add a normal theme, wiki changes usually are not needed.
