# Development Environment Setup

This page is the practical "get the site running on your machine without doing wizard rituals" guide.

## Recommended Setup

Example stack:

- VS Code
- PHP installed locally
- The PHP GD extension for image compression and gallery thumbnail generation
- Ffmpeg and ffprobe installed locally for voice note validation/compression
- A VS Code extension that can serve PHP projects
- Git

Good enough. No need to summon docker or kubernetes for a personal PHP site. That would be deeply unserious.

## 1. Clone the Repo

```bash
git clone https://github.com/ashprids/fridge.dev.git
cd fridge.dev
```

## 2. Install PHP

Make sure PHP is installed and available in your terminal.

Check it:

```bash
php -v
```

If that prints a version, you’re chilling.

This repo’s GitHub Actions lint job uses PHP `8.3`, so using PHP 8.3 locally is the safest move if you want fewer "works on my machine" plot twists.

Enable the PHP GD extension if you want image uploads and the gallery's cached 500×500 thumbnails to use the primary image-processing path. When GD is unavailable, an installed `ffmpeg` provides the JPEG compression and thumbnail fallback.

Voice notes in chat/feed also need `ffmpeg` and `ffprobe` on the PATH. Without those, uploads will fail closed instead of storing huge browser blobs, which is annoying but correct.

## 3. Open the Repo in VS Code

Open the project folder in VS Code:

```bash
code .
```

Useful extensions:

- A PHP server extension
- A PHP syntax/intellisense extension
- Optional: EditorConfig / GitHub Actions / ESLint-ish helpers if you like extra guard rails

For your example setup, a "serve project in php" style extension is perfect.

Common options people use:

- `PHP Server`
- `PHP Preview`
- Any extension that runs `php -S`

The important part is not the brand name. It just needs to serve the repo through PHP instead of opening the files raw.

## 4. Start A Local PHP Server

You can do this from an extension, or directly in the terminal.

Manual version:

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000
```

If your extension has a "Serve Project" button, that’s basically doing the same thing with less typing.

## 5. Create A Local `/data` Directory

This matters a lot.

The repo ignores `/data`, but the site expects it to exist. If you want more than static wrapper pages, you need local runtime data.

The easiest path is opening `/settings` with developer mode on and using the dev bootstrap button. When the expected data copy is missing, the shared frontend shows a one-per-session popup pointing there. The button deletes the existing `data/`, downloads and extracts the latest sanitized archive, and installs it as `data/`. This requires PHP HTTPS support and either the PHP zip extension or the system `unzip` command.

The public folder, publishing schedule, sanitization rules, manual workflow, and troubleshooting live in [Developer Data](Developer-data). You can also download its latest archive manually and extract it into the repo root.

Minimum useful structure:

```text
data/
  accounts/
    accounts.json
    login_attempts.json
  etc/
    wip
    page_views.json
```

You will probably also want:

```text
data/
  feed/
  journal/
  guestbook/
  images/
  music/
  audio/
  audio/voice/
  contact/
  downloads/
```

## 6. Minimal Starter Files

### `data/accounts/accounts.json`

```json
{
  "accounts": []
}
```

### `data/accounts/login_attempts.json`

```json
{}
```

### `data/etc/page_views.json`

```json
{
  "pages": {},
  "updated_at": null
}
```

### `data/etc/wip`

```text
false
```

## 7. Optional Local Admin Account

If you want to test account-only or admin-only pages, add an account manually.

Example:

```json
{
  "accounts": [
    {
      "username": "dev",
      "name": "dev user",
      "password": "",
      "isAdmin": true,
      "mustResetPassword": false,
      "discordUserId": "",
      "allowedPages": ["feed", "journal", "comments", "chat"],
      "bookmarks": [],
      "theme": "default",
      "glowIntensity": "medium",
      "colors": {
        "bg": "#000000",
        "fg": "#EEEEEE",
        "border": "#3C7895",
        "subtle": "#917DAA",
        "links": "#415FAD"
      }
    }
  ]
}
```

Using an empty password is convenient for local-only setup, but obviously don’t do that on a real public environment unless you enjoy chaos.

## 8. Things That Might Look Broken Locally

Some features depend on data or services that may not exist in local dev:

- Feed/journal content if your local `data` folders are empty
- Feed replies if `data/feed/replies` is missing
- Contact dashboard if `data/contact` is empty
- Music listings if `data/music` and `data/audio` are missing
- Toast features require their configuration and local service; see [Toast](Toast#local-service-and-production-operation) for setup and failure boundaries
- Off-topic archive if `data/etc/off-topic-archive.json` is missing
- Deploy/backup workflows because those are GitHub Actions + server side

That does not mean the site is broken. It just means local dev has no content yet.

## 9. Useful Commands

Lint PHP:

```bash
bash scripts/lint-php.sh
```

Lint JavaScript:

```bash
bash scripts/lint-javascript.sh
```

Lint CSS:

```bash
bash scripts/lint-css.sh
```

Start local server manually:

```bash
php -S localhost:8000
```

## 10. Practical Workflow

Pretty normal loop:

1. Run the local PHP server
2. Open the site in your browser
3. Make edits in VS Code
4. Refresh and test the affected route
5. Run lint scripts before pushing

If you edit shared files like `template.html`, `template_mobile.html`, `main.js`, or `style.css`, test more than one page because shared code loves causing side-quest bugs.

## 11. If Something Is Acting Weird

Quick checks before spiraling:

- Does `php -v` work?
- Are you serving through PHP, not just opening `index.php` as a file?
- If PHP complains about `/var/lib/php/sessions`, the app now falls back to a writable temp session directory automatically for local preview
- If PhpStorm’s built-in preview shows missing fonts/images/CSS, that preview is usually not mapped like a real site root; use a real PHP server such as `php -S localhost:8000` so `/resources`, `/style.css`, and `/js/*` resolve correctly
- Does `/data` exist locally?
- Are the required JSON files valid JSON?
- Is `data/etc/wip` accidentally set to true?
- Are you testing a page that depends on content you haven’t created yet?

## 12. Recommended Reality Check

For this project, the best dev setup is the one that gets you editing pages fast:

- VS Code
- Local PHP
- Simple PHP server extension
- Local `/data` folder

That’s enough to build and test basically everything here without overengineering the life out of it.
