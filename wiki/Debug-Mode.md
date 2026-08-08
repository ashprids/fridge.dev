# Debug Mode

Debug mode is the desktop-only diagnostic overlay enabled from the **Accessibility** section of `/settings`. It brings browser, PHP, and site-access diagnostics into the normal fridge.dev interface so developers can inspect a problem without opening several separate tools.

It is an observability feature, not a permission override. Enabling it does not grant access to admin data: ordinary users can use client logging, but only authenticated admins receive server logs and the access-log tab.

## Enabling and Persistence

Turn on **debug mode** in `/settings` and save the accessibility preferences. The setting applies immediately. It is disabled on mobile view because the overlay and its resize controls are designed for the desktop shell.

For guests, the preference is stored locally in the browser. For logged-in users it is also synchronized through `/api/settings` as the account's `debugMode` setting, using the same accessibility-preference flow as Reduce Motion. The overlay width is local to the browser, while the selected tab, searches, filters, and retained client/server entries use session storage and therefore survive page navigation and refreshes within that browser session.

When debug mode is off, its runtime is dormant: the panel is not constructed, diagnostic listeners are not attached, `fetch` and console handling are not wrapped, process logs are not probed, and entries are not accumulated. This keeps normal browsing behavior and overhead separate from debugging.

## Overlay Layout and Controls

The overlay is fixed to the right side of the desktop viewport and does not reflow the page. Its full left edge is a resize gutter. Drag it with a pointer, or focus it and use the Left and Right Arrow keys; the chosen width persists locally.

The overlay has up to three tabs:

- **client** contains browser-side events and is available to everyone
- **server** contains request-local PHP diagnostics and process-log output; non-admins see it disabled with a security explanation
- **access** contains sensitive site navigation records and is omitted entirely for non-admins

Every entry receives a local `[HH:MM:SS]` display timestamp. Timestamps are grey and bracketed source tags are light grey. Successful actions and 2xx responses are green, redirects and warnings are yellow-orange, and errors plus 4xx/5xx responses are red.

Each tab has a case-insensitive search field. An entry is shown only when it contains the complete search term, and every match is highlighted. Search values persist for the session. Filters hide retained entries rather than deleting them, so turning a filter back on restores its matching history.

The icon-only clear button asks for confirmation. Clearing client or server removes that tab's browser-retained history. Clearing access makes an admin-only request that deletes the stored access records, so it affects other admin sessions too.

New output follows the bottom only when the viewer is already near the bottom. If the viewer has scrolled upward, polling, searches, filter changes, and new records preserve that reading position. DOM-rebuilding updates are coalesced while text inside a log is selected, preventing a refresh from destroying the selection; safe append-only updates can continue.

## Client Tab

The client tab records high-signal events generated in the browser, including:

- Shared and page-specific initialization
- Sanitized network methods, paths, and response statuses
- Settings and persistence outcomes
- Page visibility and browser online/offline changes
- Media and user-operation outcomes
- Console warnings and errors
- Uncaught errors and unhandled promise rejections, without stack traces

Completed requests use the compact form `[network] METHOD /path CODE`. Uploads are classified as network activity and log only method, path, status, and deliberately added aggregate metadata such as byte counts; request bodies and file contents are never recorded.

The available filters are `settings`, `network`, `warnings`, and `errors`. Shared sidebar initialization is treated as settings activity. The tab retains its newest 1,000 entries in session storage.

First-party scripts can add concise entries with:

```js
window.fridg3DebugClientLog?.('[feature] useful event description');
```

Use a stable bracketed feature tag so entries are searchable. Log state transitions and outcomes, not keystrokes, animation frames, complete objects, or repetitive polling success.

## Server Tab

`lib/debug.php` starts the shared non-process PHP diagnostic runtime wherever it enters the first-party include graph. It automatically records:

- The sanitized request method and path at initialization
- Every repository PHP file included by the request
- PHP warnings, notices, recoverable errors, and fatal shutdown errors
- The final HTTP status
- Sanitized submission and attachment metadata for `POST`, `PUT`, and `PATCH`

The server filters are `loaded`, `process`, `warnings`, and `errors`. `loaded` covers `[PHP] loaded ...` entries and request lifecycle messages. The newest 1,000 server entries are retained in session storage.

### Adding Route-Specific PHP Entries

After loading `lib/render.php` or `lib/debug.php`, add a route-specific message with:

```php
fridg3_debug_log('[PHP] formatting example page initialized');
```

`/formatting/example_page` is the canonical working example. Values are converted to text and truncated to 2,000 characters, but callers must sanitize them before logging.

For rendered admin pages, `lib/render.php` embeds request-local entries as JSON in the page. SPA navigation imports that payload into the existing overlay. For admin JSON requests made while debug mode is enabled, the fetch wrapper sends `X-Fridg3-Debug: 1`; eligible responses return a base64-encoded `X-Fridg3-Debug-Logs` header. The header is capped to a small selection of recent entries so it remains within practical HTTP header limits. Non-admin responses receive neither transport.

### Submissions and Redirects

The shared runtime reports a submission's route, content length, attachment count, final status, and authenticated username or guest state. It walks nested and multiple `$_FILES` fields and records only the field slot, declared MIME type, byte count, and PHP upload error code.

Feature handlers may add validated outcomes. Feed and journal processors report saved and rejected media/voice counts and type/size metadata; music reports cover, track, rollback, and release-metadata outcomes; feed, journal, and guestbook report save success or failure. If an admin submission redirects, up to 100 sanitized submission events are carried through the session and imported into the next rendered server log.

### Process Logs

The `process` filter is separate from request-local PHP entries. Enabling it initially reads the newest 64 KB of the selected server-wide PHP/FastCGI log, then polls for appended content. These entries are prefixed `[PROCESS]`, and the overlay reports which source was selected. Turning the filter off pauses polling.

Source discovery uses this order:

1. `FRIDG3_PHP_PROCESS_LOG`
2. PHP's configured file-backed `error_log`
3. PHP-FPM log locations
4. `/var/log/nginx/error.log`
5. The Apache development error log

The process-log API and source discovery are admin-only. A missing or unreadable source is reported in the tab rather than exposing filesystem details to non-admins.

## Access Tab

The access tab is an admin-only view of `data/etc/access.json`, a permission-restricted JSON array capped at the latest 10,000 page visits. The development-data archive excludes this sensitive file.

The shutdown runtime records only direct top-level document navigation executed through `index.php`. Normal browser navigation is identified with Fetch Metadata; fridge.dev SPA navigation explicitly sends `X-Fridg3-Page-Navigation: 1`. Older browsers fall back to a non-XHR request that accepts HTML.

The logger excludes API calls, `/chat`, every `/error` path, images, media, scripts, stylesheets, attachments, polling, hard-ban authorization checks, and other subresources. Missing pages retain the originally requested path and are recorded once with status `404`.

Each stored record contains:

- A UTC timestamp
- The Nginx-resolved visitor IP
- The canonical path without a query string
- The HTTP status
- The session username when present
- The request-time role: guest, user, or admin

Request methods are not stored. Production Nginx accepts `CF-Connecting-IP` only from Cloudflare's published trusted networks before PHP sees the visitor address.

Paths omit a trailing slash except for `/`. Records are compacted per IP/username visitor: refreshes and repeated visits to that visitor's current canonical page are omitted until another page is visited. If the status changes on the same page, the newer record replaces the earlier one. The same compaction is applied while reading older data.

Selecting access fetches immediately from `/api/debug-access-logs` and starts one-second polling. Leaving the tab stops polling, and a request that finishes after the tab closes is not rendered. The API does not record its own polling requests. It derives roles for legacy entries from current account data and checks each unique IP against the effective hard-ban rules, including whitelist overrides.

The display format is `[time] [IP] [@username] [HTTP code] page`, with the username group omitted for guests. Filters for `guests`, `users`, and `admins` select roles; `hard-banned` is an additive filter for effective bans. Only the IP text turns red for a hard-banned address, only the username text is yellow, and status colours follow the shared response palette.

The IP text links to that address on whatismyipaddress.com in a new tab and deliberately bypasses the general external-link confirmation. Right-clicking a normal IP offers to add it to the manual hard-ban list. Right-clicking an effectively hard-banned IP instead offers to whitelist that exact address across manual, source-list, CIDR, and identity-based enforcement. Both actions require confirmation above the overlay; applying a hard ban removes an existing whitelist override for that address. See [Restrictions and Moderation](Restrictions-and-Moderation) for the enforcement model itself.

## Bootstrap Diagnostics

The developer-data bootstrap stream includes a sanitized `debug` value on every event, prefixed `[BOOTSTRAP]`. Debug mode imports these values into the admin server tab. The browser also records confirmation events for stream parsing, progress-popup text/detail/percentage changes, completion or failure, and button restoration. Download URLs and query credentials are replaced with `[url omitted]`.

These messages are useful when the archive download succeeds but extraction, progress display, or UI recovery does not. They intentionally describe stages rather than exposing the archive URL or credentials.

## Privacy and Logging Rules

Debug output is still application output and must be treated as potentially visible to an administrator looking over a live site. Never log:

- Passwords, credentials, tokens, cookies, or query strings
- Raw request or response bodies
- Private posts, messages, contact text, or draft content
- Filenames supplied by users, temporary paths, or destination names
- Upload or attachment contents
- Raw IP addresses outside the protected access-log system
- Stack traces or unbounded object dumps

Keep entries concise, sanitized, and useful as individual lines. Prefer a route without its query string, counts instead of collections, MIME/type and byte size instead of file identity, and a clear success/failure outcome instead of user content.

## Troubleshooting

- If no panel appears, confirm desktop view is active and the saved `/settings` preference is on.
- If server is locked or access is absent, confirm the current session is authenticated as an admin; enabling debug mode cannot elevate permissions.
- If JSON request logs are missing, confirm the request went through the debug-mode fetch wrapper and the response declared `application/json`.
- If process output is empty, check the selected-source status and the PHP-FPM user's read permission for the intended log, or set `FRIDG3_PHP_PROCESS_LOG` explicitly.
- If access entries are missing, confirm the request was a top-level page navigation through `index.php`; APIs, subresources, `/chat`, and `/error` are excluded by design.
- If an entry appears to vanish after filtering, clear the search and re-enable the relevant category before clearing stored history.
