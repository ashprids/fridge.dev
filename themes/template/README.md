# Theme starter

This directory is a human-readable starting point for a new fridge.dev theme.
It is intentionally nested under `/themes/template`, so the theme picker does
not discover it as a real theme.

The starter inherits Blackprint's layout and component coverage. Most visual
changes can therefore be made in `package/modules/tokens.css`; the other modules
turn those tokens into shell, component, content, player, mobile, and
accessibility styles.

## Create a theme

Choose a lowercase ID containing only letters, numbers, `_`, or `-`. The example
below uses `my-theme`.

1. Create `/themes/lib/my-theme/` and copy everything inside `package/` into it.
2. Copy `theme.json` to `/themes/my-theme.json`.
3. Copy `thumbnail.svg` to `/themes/thumbnails/my-theme.svg`.
4. Replace every `your-theme` in the copied files with `my-theme`.
5. Edit the name and description in `/themes/my-theme.json`.
6. Start with the colours, type, spacing, corners, and shadows in
   `modules/tokens.css`.

The supplied thumbnail is structurally identical to
`/themes/thumbnails/blackprint.svg`. Keep its view box, shapes, positions, sizes,
stroke widths, and opacities unchanged. Use canonical palette colours, changing
only colour values and optional rectangle border radii. The full-canvas overlay
rectangle should use a solid fill by default; only add a gradient definition when
a gradient is an intentional part of the theme. This keeps previews accurate and
directly comparable in the picker.

The finished package should look like this:

```text
themes/
├── my-theme.json
├── thumbnails/
│   └── my-theme.svg
└── lib/
    └── my-theme/
        ├── theme.html
        ├── theme.css
        └── modules/
            ├── tokens.css
            ├── shell.css
            ├── components.css
            ├── content.css
            ├── player.css
            ├── mobile.css
            └── accessibility.css
```

Keep all imports in `theme.css`, or delete an import when the inherited
Blackprint treatment is already right for that part of the site. Do not import
`/style.css`; the renderer loads it first and appends the theme entry stylesheet.

The desktop template is a copy of the current shared shell with no hard-coded
theme class. The renderer adds `your-theme-theme` and `blackprint-theme` at run
time. Mobile always uses `/template_mobile.html`, so mobile customisation belongs
in `modules/mobile.css`, not a second HTML file.

If the design needs a radically different desktop layout, edit `theme.html` but
preserve the placeholders, IDs, sidebar navigation, persistent audio element,
and shared scripts described in `/themes/AGENTS.md`. When the shared desktop
template changes, compare it with this copy before publishing a new theme.

## Validate it

Run these commands after replacing `my-theme` with the chosen ID:

```bash
php -r '$v=json_decode(file_get_contents("themes/my-theme.json"), true); echo json_last_error_msg(), "\n";'
php -r 'require "lib/render.php"; echo json_encode(array_values(array_map(fn($t) => ["id" => $t["id"], "name" => $t["name"]], fridg3_list_themes(__DIR__))), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";'
git diff --check -- themes/my-theme.json themes/lib/my-theme themes/thumbnails/my-theme.svg
```

Then use `/formatting` to check desktop and mobile, keyboard focus, high
contrast, reduced motion, long content, forms, popups, pagination, and every
mini-player state. The full authoring and runtime contract is in
`/themes/AGENTS.md`.
