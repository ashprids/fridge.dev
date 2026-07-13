# Deployment and Operations

For the complete moderation-layer model behind account restrictions, posting bans, and nginx hard bans, see [Account Restrictions and IP Bans](Account-Restrictions-and-IP-Bans).

## Deployment Flow

deployment is GitHub Actions driven.

current chain:

1. push to `main`
2. `code lint` workflow runs
3. if lint passes, `deploy to fridge.dev` runs from the successful workflow event
4. repo is rsynced to `/var/www/fridge.dev`
5. the Toast Discord bot is gracefully restarted from the deployed copy
6. the deploy workflow asks Toast to post a patch notice approval preview in Discord channel `1526075637096255548`; an admin `✅` reaction posts it to update channel `1455194403642802309` and pings role `1408064850688475197`

## Deploy Workflow

`/.github/workflows/deploy.yml`

main details:

- triggered by successful `code lint` workflow completion
- only deploys pushes to `main`
- installs `rsync` and `openssh-client`
- uses `DEPLOY_KEY`
- deploy target is `deploy@45.76.134.105:/var/www/fridge.dev`
- the workflow verifies `/var/www/fridge.dev` exists and is writable before rsync, and refuses any unexpected target path
- after rsync, ssh stops any `toast` GNU screen session owned by `deploy`, stops any `toast` session owned by `http`, prepares `others/toast-discord-bot/bot/toast-bot.log` for `http`, then runs `/var/www/fridge.dev/others/toast-discord-bot/bot/start.sh` as `http`
- the restart step needs passwordless sudo for `deploy` to run the Toast bot as `http`; Toast writes DM history, feed notification state, and patch approval state under `/data`, which is owned by `http:http`
- because the `http` user home is not a normal login home, the workflow sets `SCREENDIR=/tmp/toast-screen-http` for `http`-owned screen commands and creates that socket directory as `http` with mode `700` before start

## Toast Patch Notice Commit Format

after a successful `main` deploy, the workflow sends Toast a list of non-merge commits using each commit's subject (`git log %s`) and full body (`git log %b`). Toast turns each commit into Discord patch-note bullets and posts the fully formatted patch notice preview in approval channel `1526075637096255548`. Toast reacts to that preview with `✅`; when an admin approves with `✅`, Toast posts the update to channel `1455194403642802309` and pings role `1408064850688475197`.

Pending approval message IDs and payloads are persisted in `/data/etc/toast-patch-approvals.json`, so older messages remain actionable after a bot restart. The raw reaction handler also fetches uncached approval messages from Discord. For a legacy Toast-authored approval embed created before persistence was added, Toast reconstructs the notice from the embed's commit URL and patch-note fields and sends it to the standard update channel.

```text
• commit subject

• first body note

• second body note
```

format commits like this when the Discord patch notice should read cleanly:

```text
Short user-facing summary

Concrete patch note detail

Second useful detail, if needed
```

rules to keep in mind:

- the Discord embed shows the deployed build ID followed by the patch-note fields, without introductory or pull-request source text
- the first bullet is the commit subject and does not include the commit ID
- body notes are separated by blank lines in the commit body
- every body note starts with its own bullet
- long patch notes are split across multiple Discord embed fields instead of being cut off
- Markdown and Discord mentions are escaped by Toast, so write plain text instead of relying on formatting or pings
- merge commits are excluded from the shipped commit list

example:

```bash
git commit -m "Add a settings system info dashboard" \
  -m "Give admins a live view of server, PHP, storage, and site health." \
  -m "Load shared runtime scripts through rendered pages so settings controls keep working."
```

same message as plain text for VS Code's source control commit message box:

```text
Add a settings system info dashboard

Give admins a live view of server, PHP, storage, and site health.

Load shared runtime scripts through rendered pages so settings controls keep working.
```

that appears in Toast's Discord embed as three patch-note bullets: one for the subject and one for each body note.

admins can manually post the same style of update directly from Discord with Toast's `/shareupdate` command:

- `/shareupdate latest` posts the currently deployed `HEAD` commit from the bot's local repo
- `/shareupdate <commit ID>` posts a specific 7-40 character commit SHA

the command uses the same embed formatter and update channel as approved deploy notices, so it also pings role `1408064850688475197`.

## What Does Not Deploy

deployment uses `.rsyncignore`, so these are excluded:

- `/data/**`
- `sitemap.xml`
- repo docs and local config files
- `.github/**`
- `/scripts/**`
- local editor/codex folders
- `/others/toast-discord-bot/bot/venv/**`

that means production runtime data is expected to already exist on the server.

## Server Permissions

from `README.md`:

- project files should belong to `deploy:http`
- directories should be `755`
- files should be `644`
- `/data` and `sitemap.xml` need `http:http` ownership for webserver writes
- Toast runs as `http` in production so it can update `/data/etc/toast-dm-history.json` and `/data/etc/toast-feed-notify-state.json`; only `toast-bot.log` in the bot code directory is made writable for that runtime user

the deploy user needs passwordless sudo for the Toast restart step:

```sudoers
deploy ALL=(http) NOPASSWD: ALL
```

install that with `visudo`, preferably as a small file under `/etc/sudoers.d/`, because typoing sudoers directly is how servers become decorative bricks.
the workflow prepares `toast-bot.log` as `deploy` with group `http` and mode `664`, so no root sudo is needed for log setup.

## Nginx Config Source

the repo-tracked files in `.nginx/` are the source for the production nginx config.

- `.nginx/nginx.conf` corresponds to `/etc/nginx/nginx.conf`
- `.nginx/fridge.dev` corresponds to `/etc/nginx/sites-enabled/fridge.dev`
- production uses these through symlinks, so edits here are real server config edits, not examples

when adding routes, APIs, uploads, redirects, or private data folders, check `.nginx/fridge.dev` as part of the feature. a correct PHP route can still fail if nginx redirects POSTs, misses a clean-url rewrite, or accidentally exposes/blocklists the wrong `/data` path.

legacy `fridg3.org`, `www.fridg3.org`, and `m.fridg3.org` redirects are handled in Cloudflare, not nginx. the redirect must append `legacy_domain=fridg3.org`; the frontend consumes that marker for the one-time rebrand popup and then removes it from the URL.

## Nginx Clean URLs

production nginx needs explicit rewrites for PHP routes that accept path-style ids. without these, nginx falls through to the root `/index.php` fallback before the route can parse the URL.

the generic `location /` fallback should route missing paths to `/error/404`, not `/index.php`, so nonexistent URLs do not quietly render the homepage.

POST-only API directory routes also need POST-safe rewrites when called without `index.php`; otherwise nginx can normalize the directory URL with a redirect and the browser may retry as `GET`. `/api/dev-bootstrap` and `/api/toast-feed-generate` are included in that rewrite list.

the contact route is configured POST-safe at `/contact`, old `/email` paths redirect to `/contact`, and `/data/contact/` is blocked from direct web access. `/data/guestbook` and `/data/guestbook/` are also blocked because entry files contain moderation-only IP metadata; the public guestbook remains available through its PHP routes. account form routes such as `/account/login`, `/account/change-password`, and `/account/admin/edit` are also rewritten directly to their PHP handlers so POST bodies are not lost to trailing-slash redirects.

site-wide hard bans are stored in `data/etc/hard-banned-ips.txt`, augmented by read-only `data/etc/banlists/*.txt` source files, and enforced by nginx `auth_request` through the internal `/_hard-ban-check` location. a denied subrequest returns `401`, which nginx converts into a `302` redirect to `/error/blacklisted`; that route, files beneath its directory, and font files beneath `/resources` explicitly disable the authorization check so the redirect cannot loop and the stripped Blackprint page can render. browser/IP associations live in `data/etc/hard-ban-identities.json`. the physical checker route, hard-ban data files, and source-list directory must remain blocked from direct requests. this requires nginx's standard `ngx_http_auth_request_module`.

the upload API posts to `/tools/upload/?api=*`; keep the exact `/tools/upload` nginx rewrite so stale no-slash requests hit PHP directly instead of losing their POST body to a trailing-slash redirect. cursed but real.

mdpaste share links use `/tools/mdpaste/s/{id}` and need this block before the generic `location /` fallback. keep the regexes quoted, because nginx treats unquoted `{16}` like cursed config syntax.

```nginx
# mdpaste clean URLs
location ~ "^/tools/mdpaste/s/[a-fA-F0-9]{16}/?$" {
    rewrite "^/tools/mdpaste/s/([a-fA-F0-9]{16})/?$" /tools/mdpaste/s/index.php?id=$1 last;
}
location /tools/mdpaste/s/ { try_files $uri $uri/ /tools/mdpaste/s/index.php?$args; }
location /tools/mdpaste/   { try_files $uri $uri/ /tools/mdpaste/index.php?$args; }
```

## Backup Workflow

`/.github/workflows/backup-data.yml`

what it does:

1. ssh to the server
2. remove stale temporary backup zips from `/home/deploy`
3. verify `deploy` can read/traverse `/var/www/fridge.dev/data`
4. zip `/var/www/fridge.dev/data` into a temporary archive under `/home/deploy`
5. download the archive to the runner
6. upload it to Google Drive using `rclone`
7. keep only the 10 newest backups
8. delete temp archives from runner and server

triggers:

- manual `workflow_dispatch`
- scheduled daily cron at `0 0 * * *`

required secrets:

- `DEPLOY_KEY`
- `GDRIVE_BACKUP_FOLDER_ID`
- `RCLONE_CONFIG`

setup notes live in `/.github/workflows/backup-data-setup.md`.

if archive creation fails with `zip` exit code `18`, at least one path under `/data` was unreadable to `deploy`. run the unreadable-path check from `/.github/workflows/backup-data-setup.md`, then fix ownership/permissions before rerunning the workflow.

the backup and developer-data workflows also refuse to run if `TARGET` is anything other than `/var/www/fridge.dev`, so a stale workflow variable cannot accidentally back up or publish the wrong site tree.

## Developer Data Copy

`/.github/workflows/publish-dev-data.yml`

on the same daily schedule as the private backup workflow, this workflow:

1. copies production `/var/www/fridge.dev/data` into a temporary server workspace
2. runs `/.github/scripts/sanitize-dev-data.php` against the copy
3. zips the sanitized directory as `DD-MM-YY_hh-mm-ss.zip`
4. uploads it to the public Google Drive developer data folder
5. keeps only the 10 newest zip files in that folder
6. removes temporary server and runner files

the sanitizer currently clears accounts, login/page-view/IP/rate-limit logs, guestbook IP ownership and entry IP metadata, feed guest reply IPs/browser tokens, shared posting ban lists, the site-wide hard-ban list and browser/IP associations, blanks Toast bot and Groq credentials, blanks Toast private lore, clears Toast DM/notification state and browser notification state, clears webhooks, removes upload room tokens, clears encrypted mdpaste records, clears encrypted chat data and local chat keys, replaces the off-topic Discord archive with an empty placeholder, and replaces private journal drafts with a harmless placeholder draft. the development archive additionally excludes `data/etc/hard-banned-ips.txt` and `data/etc/hard-ban-identities.json` entirely.

setup notes live in `/.github/workflows/publish-dev-data-setup.md`.

## Sitemap Generation

`sitemap.xml` is not deployed from git. it is generated by `/api/sitemap`, which means:

- the file must be writable by the server
- the server copy is the one that matters

## Operational Truths

- this repo is source code, not a full backup
- `/data` is operational state
- if prod data disappears, git will not magically save you
- if file permissions are wrong, deploys and runtime writes will get weird fast
