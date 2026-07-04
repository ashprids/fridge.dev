# Deployment and Operations

## Deployment Flow

deployment is GitHub Actions driven.

current chain:

1. push to `main`
2. `code lint` workflow runs
3. if lint passes, `deploy to fridg3.org` runs from the successful workflow event
4. repo is rsynced to `/var/www/fridg3.org`
5. the Toast Discord bot is gracefully restarted from the deployed copy

## Deploy Workflow

`/.github/workflows/deploy.yml`

main details:

- triggered by successful `code lint` workflow completion
- only deploys pushes to `main`
- installs `rsync` and `openssh-client`
- uses `DEPLOY_KEY`
- deploy target is `deploy@45.76.134.105:/var/www/fridg3.org`
- after rsync, ssh stops any `toast` GNU screen session owned by `deploy`, stops any `toast` session owned by `http`, prepares `others/toast-discord-bot/bot/toast-bot.log` for `http`, then runs `/var/www/fridg3.org/others/toast-discord-bot/bot/start.sh` as `http`
- the restart step needs passwordless sudo for `deploy` to run the Toast bot as `http`; Toast writes DM history and feed notification state under `/data`, which is owned by `http:http`
- because the `http` user home is not a normal login home, the workflow sets `SCREENDIR=/tmp/toast-screen-http` for `http`-owned screen commands and creates that socket directory as `http` with mode `700` before start

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
- `.nginx/fridg3.org` corresponds to `/etc/nginx/sites-enabled/fridg3.org`
- production uses these through symlinks, so edits here are real server config edits, not examples

when adding routes, APIs, uploads, redirects, or private data folders, check `.nginx/fridg3.org` as part of the feature. a correct PHP route can still fail if nginx redirects POSTs, misses a clean-url rewrite, or accidentally exposes/blocklists the wrong `/data` path.

## Nginx Clean URLs

production nginx needs explicit rewrites for PHP routes that accept path-style ids. without these, nginx falls through to the root `/index.php` fallback before the route can parse the URL.

POST-only API directory routes also need POST-safe rewrites when called without `index.php`; otherwise nginx can normalize the directory URL with a redirect and the browser may retry as `GET`. `/api/dev-bootstrap` and `/api/toast-feed-generate` are included in that rewrite list.

the contact route is configured POST-safe at `/contact`, old `/email` paths redirect to `/contact`, and `/data/contact/` is blocked from direct web access.

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
3. zip `/var/www/fridg3.org/data` into a temporary archive under `/home/deploy`
4. download the archive to the runner
5. upload it to Google Drive using `rclone`
6. keep only the 10 newest backups
7. delete temp archives from runner and server

triggers:

- manual `workflow_dispatch`
- scheduled daily cron at `0 0 * * *`

required secrets:

- `DEPLOY_KEY`
- `GDRIVE_BACKUP_FOLDER_ID`
- `RCLONE_CONFIG`

setup notes live in `/.github/workflows/backup-data-setup.md`.

## Developer Data Copy

`/.github/workflows/publish-dev-data.yml`

on the same daily schedule as the private backup workflow, this workflow:

1. copies production `/var/www/fridg3.org/data` into a temporary server workspace
2. runs `/.github/scripts/sanitize-dev-data.php` against the copy
3. zips the sanitized directory as `DD-MM-YY_hh-mm-ss.zip`
4. uploads it to the public Google Drive developer data folder
5. keeps only the 10 newest zip files in that folder
6. removes temporary server and runner files

the sanitizer currently clears accounts, login/page-view/IP/rate-limit logs, guestbook IP ownership, feed guest reply IPs/browser tokens, feed IP ban lists, blanks Toast bot and Groq credentials, blanks Toast private lore, clears Toast DM/notification state, clears webhooks, removes upload room tokens, clears encrypted mdpaste records, clears encrypted chat data and local chat keys, replaces the off-topic Discord archive with an empty placeholder, and replaces private journal drafts with a harmless placeholder draft.

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
