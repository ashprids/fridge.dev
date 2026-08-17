# Publish Development Data Workflow

This repository includes a GitHub Actions workflow at `.github/workflows/publish-dev-data.yml` that publishes a sanitized developer copy of the production `data` directory.

The workflow runs daily at `00:00 UTC`, matching the private `/data` backup workflow, and it can also be run manually from GitHub Actions.

## What It Does

Each run:

1. Connects to `deploy@45.76.134.105` over SSH
2. Copies `/var/www/fridge.dev/data` into a temporary server workspace while excluding the rebuildable hard-ban binary index
3. Runs `.github/scripts/sanitize-dev-data.php` against that copy
4. Compresses the sanitized `data` directory into a zip file named `DD-MM-YY_hh-mm-ss.zip`
5. Uploads the zip file into the public Google Drive developer data folder
6. Keeps only the 10 newest developer copies in that folder
7. Deletes temporary files from the runner and server

Before creating a workspace, the workflow removes any stale `/home/deploy/dev-data.*` workspaces left by an earlier failed or cancelled run. Runs are serialized, so this cleanup cannot remove files from another active developer-data run. If the initial production-data copy fails, that run also removes its newly-created workspace immediately.

Every production deployment normalizes the site permissions before Nginx is reloaded. Runtime data receives group read/write access, setgid directories, and default ACLs for the `http` group, so files added beneath `data` inherit access for both the `http` runtime user and the `deploy` user. The developer-data workflow retains its pre-copy repair for servers that have not yet received that deployment step.

## Required GitHub Secrets

The workflow reuses `DEPLOY_KEY` and `RCLONE_CONFIG` from the private backup workflow. Their SSH requirements, exact `gdrive` remote name, configuration shape, and setup procedure are documented once on [Backup Data](Backup-data#required-github-secrets).

## Required GitHub Variables

Create these repository variables in `Settings` -> `Secrets and variables` -> `Actions` -> `Variables`.

### `GDRIVE_DEV_DATA_FOLDER_ID`

This must be the Google Drive folder ID that should hold the public developer data zip:

```text
1dltxdqQjfUfGwEEXVxUrOw5fuv9nk_ex
```

This must be a variable, not a secret. If it is stored as a secret, GitHub will mask the folder ID in the workflow summary and the download link will show `***`.

## Public Developer Data Folder

The public developer data folder is:

```text
https://drive.google.com/drive/folders/1dltxdqQjfUfGwEEXVxUrOw5fuv9nk_ex
```

The workflow writes this into the run summary so developers can find the latest archives without digging through repo settings.

## Sanitized Paths

The sanitizer currently changes:

- `data/accounts/accounts.json`: clears all accounts
- `data/accounts/login_attempts.json`: clears contents
- `data/etc/page_views.json`: clears page counts
- `data/etc/toast.json`: clears `bot.token`, `bot.client_id`, and `groq.api_key`
- `data/etc/toast-personality.json`: clears `private_lore`
- `data/etc/toast-dm-history.json`: clears Discord DM history
- `data/etc/toast-feed-notify-state.json`: clears Discord notification state
- `data/etc/toast-patch-approvals.json`: clears pending and completed Discord update approvals
- `data/etc/off-topic-archive.json`: replaces exported Discord archive contents with an empty placeholder
- `data/etc/webhooks.json`: clears all scalar values
- `data/guestbook/ip_index.json`: clears contents
- `data/guestbook/*.txt`: removes `IP:` metadata while retaining public messages
- `data/feed/replies/*.json`: blanks guest IPs and removes guest browser-local inbox identities
- `data/feed/post_ips.json`: blanks feed-post IPs while retaining post IDs and usernames for local rendering
- `data/feed/banned_ips.json`: clears the shared posting IP ban list
- `data/etc/banned-ip-content.json`: clears deleted-content snapshots retained for soft-ban review
- `data/contact/*.json`: removes private contact submissions
- `data/contact/rate_limits.json`: clears IP rate-limit state
- `data/upload/rooms.json`: clears temporary room tokens and public keys
- `data/mdpaste/`: clears encrypted paste records
- `data/chat/`: clears encrypted chat conversations, attachments, presence state, and local chat keys
- `data/journal/drafts`: removes drafts and adds a harmless placeholder draft
- `data/etc/access.json` and `data/etc/access.json.lock`: removes private access-log data and its lock file

The sanitizer finishes with privacy assertions that require access logs to be absent and the contact directory to contain only the empty rate-limit state. The archive command also excludes these operational identity files as defense in depth:

- `data/etc/hard-banned-ips.txt`
- `data/etc/hard-ban-identities.json`
- `data/etc/access.json`
- `data/etc/banlists/*`

To add more privacy rules, edit the marked block in:

```text
.github/scripts/sanitize-dev-data.php
```

## Manual Run

To run it manually:

1. Open the repository on GitHub
2. Go to `Actions`
3. Select `publish development /data/ copy`
4. Click `Run workflow`

## Workflow Triggers

The workflow supports:

1. Manual runs via `workflow_dispatch`
2. Scheduled runs once per day at `00:00 UTC`

## Troubleshooting

If SSH fails, verify `DEPLOY_KEY` still works for `deploy@45.76.134.105`.

If upload fails, verify `RCLONE_CONFIG` contains `gdrive` and `GDRIVE_DEV_DATA_FOLDER_ID` points at a folder the authenticated Google account can write to.

If the archive step fails, verify the server has `php` and `zip`, and that `/home/deploy` has enough space for a temporary copy of `/data`.
