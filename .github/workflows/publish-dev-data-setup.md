# Publish Development Data Workflow

This repository includes a GitHub Actions workflow at `.github/workflows/publish-dev-data.yml` that publishes a sanitized developer copy of the production `data` directory.

The workflow runs daily at `00:00 UTC`, matching the private `/data` backup workflow, and it can also be run manually from GitHub Actions.

## What It Does

Each run:

1. Connects to `deploy@45.76.134.105` over SSH
2. Copies `/var/www/fridge.dev/data` into a temporary server workspace
3. Runs `.github/scripts/sanitize-dev-data.php` against that copy
4. Compresses the sanitized `data` directory into a zip file named `DD-MM-YY_hh-mm-ss.zip`
5. Uploads the zip file into the public Google Drive developer data folder
6. Keeps only the 10 newest developer copies in that folder
7. Deletes temporary files from the runner and server

## Required GitHub Secrets

Create these repository secrets in `Settings` -> `Secrets and variables` -> `Actions`.

### `DEPLOY_KEY`

This should be the same private SSH key used by the deploy workflow.

It must allow SSH access to:

```text
deploy@45.76.134.105
```

### `RCLONE_CONFIG`

This is the same rclone config used by the private backup workflow. It must contain a remote named `gdrive`.

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
- `data/etc/off-topic-archive.json`: replaces exported Discord archive contents with an empty placeholder
- `data/etc/webhooks.json`: clears all scalar values
- `data/guestbook/ip_index.json`: clears contents
- `data/guestbook/*.txt`: removes `IP:` metadata while retaining public messages
- `data/feed/replies/*.json`: blanks guest IPs and removes guest browser notification tokens
- `data/feed/banned_ips.json`: clears the shared posting IP ban list
- `data/contact/rate_limits.json`: clears IP rate-limit state
- `data/upload/rooms.json`: clears temporary room tokens and public keys
- `data/mdpaste/`: clears encrypted paste records
- `data/chat/`: clears encrypted chat conversations, attachments, presence state, and local chat keys
- `data/journal/drafts`: removes drafts and adds a harmless placeholder draft

The archive command excludes these operational identity files entirely, after sanitization as defense in depth:

- `data/etc/hard-banned-ips.txt`
- `data/etc/hard-ban-identities.json`

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
