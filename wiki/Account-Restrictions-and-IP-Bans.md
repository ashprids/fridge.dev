# Account Restrictions and IP Bans

fridge.dev has three separate moderation layers. They solve different problems and must not be treated as interchangeable:

1. an account posting restriction follows a logged-in account
2. a posting IP ban blocks selected submission surfaces without blocking website access
3. a hard ban blocks the entire client at the nginx request layer

## Account Posting Restrictions

Account restrictions are stored as the `postingRestricted` boolean on an account in `data/accounts/accounts.json`.

Admins can set the flag while creating an account or through the account editor. Restricting an account does not remove its `allowedPages`, admin status, ability to read pages, or authorized deletion and moderation actions. It prevents content creation and editing.

Restricted accounts are rejected by the server handlers for:

- feed post creation and editing
- feed replies and reply editing
- journal posts, drafts, previews, and editing
- chat conversations and messages
- guestbook creation and editing
- contact submissions
- mdpaste creation
- serverless upload room creation, joining, and signaling

The shared session helpers in `lib/session.php` refresh the flag from account storage, render `your account has been restricted.`, and disable composer controls. Disabled textareas, upload inputs, BBCode buttons, and submit buttons are only presentation; every write handler must enforce the restriction independently.

## Posting IP Bans

Posting bans are stored in `data/feed/banned_ips.json` and managed from `/settings/guests`.

These bans do not prevent browsing the website. They block the matching client IP on the submission surfaces that use the shared posting-ban list:

- guest feed replies
- guestbook submissions
- the public contact form
- mdpaste creation
- serverless upload room and signaling APIs

The feed and guestbook share moderation controls. `/settings/guests` groups IP-backed feed replies and guestbook posts, supports individual deletion, and provides separate actions for banning, unbanning, and purging content. Purging deletes matching content but does not itself alter the ban list.

User-facing blocked notices use `your IP address has been restricted.` Account-based feed access may still bypass the guest-reply IP restriction where the route explicitly distinguishes logged-in users from guests; contact and tool handlers apply their IP checks independently.

## Hard Bans

Hard bans are exact IPv4 or IPv6 addresses stored in `data/etc/hard-banned-ips.txt`. Admins edit the list at `/settings/banned-ips`; spaces and newlines are accepted, saves validate every token, remove duplicates, and normalize the file to one address per line.

Unlike posting bans, hard bans are enforced before normal page or static-file handling. Nginx calls the internal `/_hard-ban-check` authorization endpoint for requests. A denied check becomes a server-side `302` redirect to `/error/blacklisted`, whose final response is `403`.

Hard-banned clients may access only:

- `/error/blacklisted` and its local files
- font files beneath `/resources` with `woff`, `woff2`, `ttf`, or `otf` extensions

The blacklist page uses stripped desktop and mobile Blackprint templates. Direct visitors who are not actively hard-banned are redirected to `/` by PHP.

## Browser Identity Propagation

When a hard-banned client loads the blacklist page, fridge.dev creates a random first-party browser identifier. It is retained for five years in both the `fridg3_hard_ban_id` cookie and browser local storage.

Associations are stored privately in `data/etc/hard-ban-identities.json`. Each record contains:

- the original manually banned IP, called `primaryIp`
- IP addresses later observed with that identifier
- first-seen and last-seen timestamps
- a SHA-256 hash of the observed user agent

If the identifier later arrives from a different IP while its original `primaryIp` remains hard-banned, the new IP is appended to `hard-banned-ips.txt` and immediately receives the same hard ban. `main.js` restores the cookie from local storage and reloads once when necessary so the server-side request gate can evaluate it.

This mechanism follows the same browser profile while either first-party storage value remains. It intentionally does not use probabilistic canvas, hardware, or font fingerprinting because collisions could hard-ban unrelated visitors.

## Unbanning a Hard-Ban Group

The original manually entered IP is the root of an identity group.

Removing that original IP through `/settings/banned-ips` also removes:

- every IP automatically associated with it
- every browser identifier record rooted at that IP

Removing only an automatically associated IP while the original remains banned is temporary: the IP can be added again when the same identifier returns. Removing the original IP directly from the data file is also reconciled the next time the identifier is checked, but the admin editor is the supported path.

## Developer Mode

Production hard bans redirect before the shared website shell renders. Local development commonly runs without the production nginx configuration, so `lib/render.php` adds a red `hard-banned client` warning beneath the developer-mode sidebar indicator when the current development IP or browser identity matches an active hard ban.

The warning exists only in developer mode and is intended for testing restriction state without losing the normal page shell.

## Private Data and Developer Copies

The following files contain operational IP or browser identity information and must never be served directly:

- `data/etc/hard-banned-ips.txt`
- `data/etc/hard-ban-identities.json`
- `data/feed/banned_ips.json`

Nginx explicitly blocks the two hard-ban files, and the general private-data rules protect the posting-ban JSON. The developer-data sanitizer empties the hard-ban list and identity records as defense in depth. The development archive command then excludes both hard-ban files entirely:

- `data/etc/hard-banned-ips.txt`
- `data/etc/hard-ban-identities.json`

## Implementation Checklist

When adding a new content-creation surface:

1. refresh and enforce `postingRestricted` in the server handler
2. decide whether the shared posting IP ban applies and enforce it server-side
3. disable every relevant composer, BBCode, upload, and submit control when blocked
4. use the standard account or IP restriction message
5. update the relevant route and data documentation

When changing hard-ban behavior:

1. update `lib/hard-ban.php`
2. inspect `.nginx/fridge.dev`, especially authorization exemptions and redirects
3. keep `/error/blacklisted` functional without access to ordinary shared assets
4. keep hard-ban data blocked from clients and excluded from developer archives
5. test initial banning, IP propagation, root-IP removal, associated-IP release, and non-banned blacklist-page redirects

Related documentation:

- [Data Contracts](Data-Contracts)
- [Deployment and Operations](Deployment-and-Operations)
- [Routes and Features](Routes-and-Features)
- [Frontend and Templates](Frontend-and-Templates)
