# Account Restrictions and IP Bans

fridge.dev has three separate moderation layers. They solve different problems and must not be treated as interchangeable:

1. An account posting restriction follows a logged-in account
2. A posting IP ban blocks selected submission surfaces without blocking website access
3. A hard ban blocks the entire client at the Nginx request layer

## Account Posting Restrictions

Account restrictions are stored as the `postingRestricted` boolean on an account in `data/accounts/accounts.json`.

Admins can set the flag while creating an account or through the account editor. Restricting an account does not remove its `allowedPages`, admin status, ability to read pages, or authorized deletion and moderation actions. It prevents content creation and editing.

Restricted accounts are rejected by the server handlers for:

- Feed post creation and editing
- Feed replies and reply editing
- Journal posts, drafts, previews, and editing
- Chat conversations and messages
- Guestbook creation and editing
- Contact submissions
- Mdpaste creation
- Serverless upload room creation, joining, and signaling

The shared session helpers in `lib/session.php` refresh the flag from account storage, render `your account has been restricted.`, and disable composer controls. Disabled textareas, upload inputs, BBCode buttons, and submit buttons are only presentation; every write handler must enforce the restriction independently.

## Posting IP Bans

Accounts with `isModerator: true` share the soft-ban and user-management tools exposed under the settings page's moderator section. Their content actions are limited to non-admin authors. Admin-authored feed posts and replies remain outside moderator edit/delete authority, and the moderator flag does not grant access to account administration or site-wide hard-ban configuration.

Posting bans are stored in `data/feed/banned_ips.json` and can be applied from content menus or `/settings/guests`. Each new ban optionally records a reason, the logged-in administrator who applied it, and a new notification generation. `/settings/restricted-ips` lists these records, supports unbanning, and combines live content with deletion snapshots from `data/etc/banned-ip-content.json` for retained post review. IP-associated feed posts, feed replies, and guestbook posts are snapshotted when deleted so the history also covers deletion that happened before a later ban.

Feed-reply and guestbook post action menus resolve the associated IP's current restriction state when rendered: unrestricted IPs show `ban`, while restricted IPs show `unban`. Both actions are revalidated and applied server-side.

Successful moderator mutations—including feed and guestbook edits/deletions, IP bans and unbans, and IP-content purges—are appended to `data/etc/moderator-audit.ndjson`. Admins review this trail at `/settings/audit-log`; edit entries retain both the before and after values. The audit writer deliberately ignores admins so this remains a moderator-account accountability log.

While the restriction remains active, every browser seen on that exact IP is eligible for a one-time in-site restriction popup. Each browser records the ban generation locally after showing it once; a later unban and re-ban creates a new generation. The popup shows only the restriction title and optional reason. The matching `/notifications` entry keeps the contact instructions and `mailto:ashton@fridge.dev` link, with a blank line after the bold reason label when one was supplied.

These bans do not prevent browsing the website. They block the matching client IP on the submission surfaces that use the shared posting-ban list:

- Guest feed replies
- Guestbook submissions
- The public contact form
- Mdpaste creation
- Serverless upload room and signaling APIs

The feed and guestbook share moderation controls. `/settings/guests` groups IP-backed feed replies and guestbook posts, supports individual deletion, and provides separate actions for banning, unbanning, and purging content. Purging deletes matching content but does not itself alter the ban list.

User-facing blocked notices use `your IP address has been restricted.` Account-based feed access may still bypass the guest-reply IP restriction where the route explicitly distinguishes logged-in users from guests; contact and tool handlers apply their IP checks independently.

## Hard Bans

Hard bans are exact IPv4 or IPv6 addresses stored in `data/etc/hard-banned-ips.txt`. Admins edit the list and configure global enforcement and browser-identity propagation at `/settings/banned-ips`; spaces and newlines are accepted, saves validate every token, remove duplicates, and normalize the file to one address per line.

Additional read-only sources may be placed in `.txt` files anywhere beneath `data/etc/banlists/`; subdirectories are scanned recursively. Every valid whitespace-separated IP or CIDR subnet in those files is included in the effective hard-ban set. Both IPv4 CIDRs (`/0` through `/32`) and IPv6 CIDRs (`/0` through `/128`) are supported. Source-list entries are deliberately not copied into `hard-banned-ips.txt` and do not appear in the `/settings/banned-ips` textarea.

The debug overlay provides access-log moderation shortcuts for this system; their interface and exact behavior are documented on [Debug Mode](Debug-Mode#access-tab).

Source files are tokenized in fixed-size chunks and compiled into a binary range index beneath `data/etc/banlists/index/`. Exact IPs and CIDRs share the same fixed-width range representation, split by IP version and first address byte. Each bucket is externally sorted in bounded-memory chunks, and overlapping ranges are merged before publication. Authorization checks use binary search against the relevant bucket rather than scanning every record. The cache key includes every source path's inode, size, modification time, and change time; changing, adding, or removing a source list therefore builds and atomically publishes a new index. Concurrent builders are serialized with a file lock, while steady-state lookups take a lock-free ready-index path. Interrupted build directories are removed by the next locked index access, and superseded signature versions older than one hour are pruned. If the index cannot be created, matching falls back to the bounded-memory source scanner rather than failing the authorization request.

Neither index construction nor fallback scanning loads a complete source file or expands the complete effective list into a PHP array. Tokens longer than the maximum possible supported IP/CIDR representation are treated as invalid and skipped without buffering the rest of that token. A large new index can make the first request after a source change or index deletion slower; subsequent requests reuse it. The `index/` directory must be writable by the PHP-FPM user and remains protected by Nginx's block on the complete `data/etc/banlists/` tree.

Unlike posting bans, hard bans are enforced before normal page or static-file handling. Nginx calls the internal `/_hard-ban-check` authorization endpoint for requests. A denied check becomes a server-side `302` redirect to `/error/blacklisted`, whose final response is `403`.

Hard-banned clients may access only:

- `/error/blacklisted` and its local files
- Font files beneath `/resources` with `woff`, `woff2`, `ttf`, or `otf` extensions

The blacklist page uses stripped desktop and mobile Blackprint templates. Direct visitors who are not actively hard-banned are redirected to `/` by PHP.

## Browser Identity Propagation

When a hard-banned client loads the blacklist page, fridge.dev creates a random first-party browser identifier. It is retained for five years in both the `fridg3_hard_ban_id` cookie and browser local storage.

Associations are stored privately in `data/etc/hard-ban-identities.json`. Each record contains:

- The original manually banned IP, called `primaryIp`
- IP addresses later observed with that identifier
- First-seen and last-seen timestamps
- A SHA-256 hash of the observed user agent

If the identifier later arrives from a different IP while its original `primaryIp` remains hard-banned, strict mode denies that request directly through the identity association and records the observed IP beneath the same primary. Associated IPs are never copied to `hard-banned-ips.txt`, promoted to `primaryIp`, or inserted into the source-list binary index. `main.js` restores the cookie from local storage and reloads once when necessary so the server-side request gate can evaluate it.

Admins can disable **strict hard bans** in the admin-only section of `/settings`. The setting defaults to enabled and is stored globally in `data/etc/hard-ban-settings.json`. During the switch, legacy associated IPs previously copied into the manual hard-ban list are removed while each identity group's original banned IP remains. Once disabled, `hard-ban-identities.json` and all browser tracking are entirely ignored: identity data is not consulted for authorization or admin saves and no identifiers, observed IPs, timestamps, or user-agent hashes are written. Only the client's current IP is checked against the manual and source hard-ban lists. The blacklist page then advises clients to disable VPNs, proxies, or other IP-masking tools.

Admins can separately disable **hard-ban enforcement** above the strict-mode checkbox. This global setting also defaults to enabled. When disabled, the Nginx authorization subrequest returns allowed immediately, before client-IP resolution and without reading the manual list, source lists, or identity data. Hard-ban data remains stored unchanged so enforcement can be restored later.

Authenticated admin sessions always bypass hard-ban enforcement. The internal authorization endpoint loads the session without applying unrelated page redirects and returns allowed before performing the client hard-ban check. Shared rendering uses a read-only evaluation of the current settings for admins; when those rules would otherwise block the admin's IP or identity, it shows the same `hard-banned client` banner used in development mode with an `admin bypass active` status. This preview never propagates an IP or updates identity data.

This mechanism follows the same browser profile while either first-party storage value remains. It intentionally does not use probabilistic canvas, hardware, or font fingerprinting because collisions could hard-ban unrelated visitors.

## Unbanning a Hard-Ban Group

The original manually entered IP is the root of an identity group.

Removing that original IP through `/settings/banned-ips` also removes:

- Every IP automatically associated with it
- Every browser identifier record rooted at that IP

Removing only an automatically associated IP while the original remains banned is temporary: the IP can be added again when the same identifier returns. Removing the original IP directly from the data file is also reconciled the next time the identifier is checked, but the admin editor is the supported path.

## Developer Mode

Production hard bans redirect before the shared website shell renders. Local development commonly runs without the production Nginx configuration, so `lib/render.php` adds a red `hard-banned client` warning beneath the developer-mode sidebar indicator when the current development IP or browser identity matches an active hard ban.

The warning exists only in developer mode and is intended for testing restriction state without losing the normal page shell.

## Private Data and Developer Copies

The following files contain operational IP or browser identity information and must never be served directly:

- `data/etc/hard-banned-ips.txt`
- `data/etc/hard-ban-settings.json`
- `data/etc/hard-ban-identities.json`
- `data/etc/banlists/*.txt`
- `data/feed/banned_ips.json`

Nginx explicitly blocks the hard-ban data files, and the general private-data rules protect the posting-ban JSON. Sanitization and archive-exclusion rules for public development copies are documented only on [Developer Data](Developer-data#sanitized-paths).

## Implementation Checklist

When adding a new content-creation surface:

1. Refresh and enforce `postingRestricted` in the server handler
2. Decide whether the shared posting IP ban applies and enforce it server-side
3. Disable every relevant composer, BBCode, upload, and submit control when blocked
4. Use the standard account or IP restriction message
5. Update the relevant route and data documentation

When changing hard-ban behavior:

1. Update `lib/hard-ban.php`
2. Inspect `.nginx/fridge.dev`, especially authorization exemptions and redirects
3. Keep `/error/blacklisted` functional without access to ordinary shared assets
4. Keep hard-ban data blocked from clients and excluded from developer archives
5. Test initial banning, IP propagation, root-IP removal, associated-IP release, and non-banned blacklist-page redirects

Related documentation:

- [Data Contracts](Data-Contracts)
- [Deployment and Operations](Deployment-and-Operations)
- [Routes and Features](Routes-and-Features)
- [Frontend and Templates](Frontend-and-Templates)
