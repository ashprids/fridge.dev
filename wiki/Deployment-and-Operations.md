# Deployment and Operations

For the complete moderation-layer model behind account restrictions, posting bans, and Nginx hard bans, see [Restrictions and Moderation](Restrictions-and-Moderation).

## Deployment Flow

Deployment is GitHub Actions driven.

Current chain:

1. Push to `main`
2. `code lint` workflow runs
3. If lint passes, `deploy to fridge.dev` runs from the successful workflow event
4. Repo is rsynced to `/var/www/fridge.dev`
5. Nginx validates the deployed configuration and gracefully reloads
6. The hard-ban source index is built or reused as the PHP-FPM `http` user
7. Toast's service is restarted and the patch-notice flow runs as documented on [Toast](Toast#local-service-and-production-operation)

## Deploy Workflow

`/.github/workflows/deploy.yml`

Main details:

- Triggered by successful `code lint` workflow completion
- Only deploys pushes to `main`
- Installs `rsync` and `openssh-client`
- Uses `DEPLOY_KEY`
- Deploy target is `deploy@45.76.134.105:/var/www/fridge.dev`
- The workflow verifies `/var/www/fridge.dev` exists and is writable before rsync, and refuses any unexpected target path
- After rsync, the workflow runs `nginx -t` and reloads Nginx only if validation succeeds, making tracked `.nginx/` changes active without interrupting existing connections
- After rsync, the workflow creates `data/etc/banlists/index` as `http`, verifies that `http` can read the source directory and write the index directory, then asks PHP running as `http` to build or reuse the hard-ban source index so a public request does not pay the one-time rebuild cost
- If index preparation or construction fails, the workflow prints the relevant directory permissions, largest partial index files, and filesystem usage to distinguish ownership from capacity failures
- Toast's restart user, screen environment, writable state, and log preparation are documented on [Toast](Toast#local-service-and-production-operation)

## Toast Integration

Toast restart behavior, patch-notice formatting, approval flow, and manual update commands are documented on [Toast](Toast#patch-notices).

## What Does Not Deploy

Deployment uses `.rsyncignore`, so these are excluded:

- `/data/**`
- `sitemap.xml`
- Repo docs and local config files
- `.github/**`
- `/scripts/**`
- Local editor/codex folders
- `/others/toast-discord-bot/bot/venv/**`

That means production runtime data is expected to already exist on the server.

## Server Permissions

From `README.md`:

- Project files should belong to `deploy:http`
- Directories should be `755`
- Files should be `644`
- `/data` and `sitemap.xml` need `http:http` ownership for webserver writes
- Toast's production ownership requirements are documented on [Toast](Toast#local-service-and-production-operation)

The deploy user needs the passwordless sudo allowances described on [Toast](Toast#local-service-and-production-operation), alongside the Nginx allowances below:

```sudoers
deploy ALL=(http) NOPASSWD: ALL
deploy ALL=(root) NOPASSWD: /usr/bin/nginx -t, /usr/bin/systemctl reload nginx
```

Install that with `visudo`, preferably as a small file under `/etc/sudoers.d/`, because typoing sudoers directly is how servers become decorative bricks.
The root allowance is deliberately restricted to validating and gracefully reloading Nginx. Toast-specific log setup is documented on [Toast](Toast#local-service-and-production-operation).

## Nginx Config Source

The repo-tracked files in `.nginx/` are the source for the production Nginx config.

- `.nginx/nginx.conf` corresponds to `/etc/nginx/nginx.conf`
- `.nginx/fridge.dev` corresponds to `/etc/nginx/sites-enabled/fridge.dev`
- Production uses these through symlinks, so edits here are real server config edits, not examples

The HTTP config trusts `CF-Connecting-IP` only when the direct peer belongs to one of Cloudflare's published IPv4 or IPv6 proxy networks. Nginx's Real IP module then replaces `$remote_addr` before access checks, PHP-FPM handling, and access logging, so application logs contain the visitor address instead of a Cloudflare edge address. Keep the `set_real_ip_from` list synchronized with Cloudflare's authoritative IP-ranges page; never replace it with an unrestricted range.

When adding routes, APIs, uploads, redirects, or private data folders, check `.nginx/fridge.dev` as part of the feature. A correct PHP route can still fail if Nginx redirects POSTs, misses a clean-url rewrite, or accidentally exposes/blocklists the wrong `/data` path.

Nginx uses `client_max_body_size 0` and the repo-root `.user.ini` disables PHP's aggregate request/upload limits. Individual application handlers remain responsible for validating MIME types and enforcing per-file limits; feed and journal media attachments are capped at 8 MB per file.

Production must provision `data/audio/uploads/` and `data/video/` as writable by the PHP-FPM `http` user. Failed mixed-media submissions call the shared media cleanup helper so successfully moved files are removed before the request redirects with an upload error.

Legacy `fridg3.org`, `www.fridg3.org`, and `m.fridg3.org` redirects are handled in Cloudflare, not Nginx. The redirect must append `legacy_domain=fridg3.org`; the frontend consumes that marker for the one-time rebrand popup and then removes it from the URL.

## Nginx Clean URLs

Production Nginx needs explicit rewrites for PHP routes that accept path-style ids. Without these, Nginx falls through to the root `/index.php` fallback before the route can parse the URL.

The generic `location /` fallback routes missing paths internally to `/error/404/index.php`, not `/index.php`, so nonexistent URLs render the error page with a `404` response while retaining the originally requested URL.

POST-only API directory routes also need POST-safe rewrites when called without `index.php`; otherwise Nginx can normalize the directory URL with a redirect and the browser may retry as `GET`. `/api/dev-bootstrap` is included in that rewrite list; Toast's API route is documented on [Toast](Toast#ai-feed-posts).

The contact route is configured POST-safe at `/contact`, old `/email` paths redirect to `/contact`, and `/data/contact/` is blocked from direct web access. `/data/guestbook` and `/data/guestbook/` are also blocked because entry files contain moderation-only IP metadata; the public guestbook remains available through its PHP routes. Account form routes such as `/account/login`, `/account/change-password`, and `/account/admin/edit` are also rewritten directly to their PHP handlers so POST bodies are not lost to trailing-slash redirects.

Site-wide hard bans are stored in `data/etc/hard-banned-ips.txt`, augmented by read-only `.txt` source files recursively discovered beneath `data/etc/banlists/` and containing exact IPs or IPv4/IPv6 CIDR subnets, and enforced by Nginx `auth_request` through the internal `/_hard-ban-check` location. Exact-IP exceptions in the `whitelistedIps` array in `data/etc/hard-ban-settings.json` take precedence over manual, source-list, and identity bans. That FastCGI location must disable request-body forwarding, clear `CONTENT_LENGTH`, and use `GET`: authorization subrequests do not contain the parent request body, so retaining a POST body's declared length makes PHP-FPM wait for bytes that never arrive. PHP-FPM compiles source lists into fixed-width binary range buckets beneath `data/etc/banlists/index/`; its source-stat signature automatically invalidates the index when a list changes, and a lock prevents concurrent rebuilds. Build-time external sorting merges overlapping ranges with bounded memory, then steady-state checks take the lock-free ready path and binary-search one bucket. Index construction and its streaming fallback use fixed-size token chunks, keeping memory bounded even for large files or lines. The index directory must be writable by `http`; deleting it or updating a source makes the next deployment prewarm or request rebuild it. A denied subrequest returns `401`, which Nginx converts into a `302` redirect to `/error/blacklisted`; that route, files beneath its directory, and font files beneath `/resources` explicitly disable the authorization check so the redirect cannot loop and the stripped Blackprint page can render. Browser/IP associations live in `data/etc/hard-ban-identities.json`; the global `strictIdentityEnforcement`, `enforcementEnabled`, and `whitelistedIps` policies live in `data/etc/hard-ban-settings.json`. Disabling strict enforcement releases previously propagated IPs, then causes the identity JSON to be entirely ignored until strict enforcement is enabled again. Disabling overall enforcement makes the authorization endpoint allow requests before client-IP resolution or any ban-data lookup. Authenticated admins also receive an immediate allow response; shared rendering performs a read-only rule preview and displays the hard-ban banner when an admin would otherwise be blocked. The physical checker route, hard-ban data files, settings, source-list directory, and binary index must remain inaccessible to clients. This requires Nginx's standard `ngx_http_auth_request_module`.

The upload API posts to `/tools/upload/?api=*`; keep the exact `/tools/upload` Nginx rewrite so stale no-slash requests hit PHP directly instead of losing their POST body to a trailing-slash redirect. Cursed but real.

Mdpaste share links use `/tools/mdpaste/s/{id}` and need this block before the generic `location /` fallback. Keep the regexes quoted, because Nginx treats unquoted `{16}` like cursed config syntax.

```nginx
# mdpaste clean URLs
location ~ "^/tools/mdpaste/s/[a-fA-F0-9]{16}/?$" {
    rewrite "^/tools/mdpaste/s/([a-fA-F0-9]{16})/?$" /tools/mdpaste/s/index.php?id=$1 last;
}
location /tools/mdpaste/s/ { try_files $uri $uri/ /tools/mdpaste/s/index.php?$args; }
location /tools/mdpaste/   { try_files $uri $uri/ /tools/mdpaste/index.php?$args; }
```

## Data Protection Workflows

Private production backups are documented on [Backup Data](Backup-data). Sanitized public development copies, including their privacy transformations and archive exclusions, are documented on [Developer Data](Developer-data). Both workflows validate that their deployment target is exactly `/var/www/fridge.dev` before operating.

## Sitemap Generation

`sitemap.xml` is not deployed from git. It is generated by `/api/sitemap`, which means:

- The file must be writable by the server
- The server copy is the one that matters
- Each generated file identifies itself with an automatic-generation comment and the local generation timestamp in `DD/MM/YY HH:MM:SS` format

## Operational Truths

- This repo is source code, not a full backup
- `/data` is operational state
- If prod data disappears, git will not magically save you
- If file permissions are wrong, deploys and runtime writes will get weird fast
