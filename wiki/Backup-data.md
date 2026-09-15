# Backup Data Workflow

This repository includes a GitHub Actions workflow at `.github/workflows/backup-data.yml` that backs up the server-side `data` directory to Google Drive.

## What It Does

Each run:

1. Connects to `deploy@45.76.134.105` over SSH
2. Changes directory to `/var/www/fridge.dev`
3. Compresses the `data` directory into a zip file named `DD-MM-YY_hh-mm-ss.zip`
4. Downloads that zip file to the GitHub Actions runner
5. Uploads the zip file into a specific Google Drive folder
6. Keeps only the 10 newest backup files in that folder, permanently deleting older backups instead of moving them to the Google Drive rubbish bin
7. Deletes the temporary zip file from both the runner and the server

## Required GitHub Secrets

Create these repository secrets in `Settings` -> `Secrets and variables` -> `Actions`.

### `DEPLOY_KEY`

This should be the same private SSH key used by the deploy workflow.

It must allow SSH access to:

```text
deploy@45.76.134.105
```

### `GDRIVE_BACKUP_FOLDER_ID`

This must be the Google Drive folder ID that should hold the backups.

Example Google Drive folder URL:

```text
https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz
```

In that example, the folder ID is:

```text
1AbCdEfGhIjKlMnOpQrStUvWxYz
```

### `RCLONE_CONFIG`

This must be the full contents of an `rclone.conf` file containing a remote named `gdrive`.

Minimal example:

```ini
[gdrive]
type = drive
scope = drive
token = {"access_token":"...","token_type":"Bearer","refresh_token":"...","expiry":"2026-03-28T00:00:00.000000000Z"}
team_drive =
```

The workflow expects the remote name to be exactly:

```text
gdrive
```

## How to Generate `rclone.conf`

On your own machine:

```bash
rclone config
```

Then:

1. Create a new remote named `gdrive`
2. Choose `drive` as the storage type
3. Complete the Google authentication flow
4. Open the generated config file:

```bash
rclone config file
```

5. Copy the full contents into the GitHub secret named `RCLONE_CONFIG`

## Workflow Triggers

The workflow supports:

1. Manual runs via `workflow_dispatch`
2. Scheduled runs once per day at `00:00 UTC`

## Manual Run

To run it manually:

1. Open the repository on GitHub
2. Go to `Actions`
3. Select `backup data`
4. Click `Run workflow`

## Notes

Retention uses `rclone deletefile --drive-use-trash=false` so removed backups do not accumulate in the rubbish bin and consume Drive storage. This applies to future retention deletions; backups already in the rubbish bin must be permanently deleted separately.

The remote server must have the `zip` command installed, because the archive is created on the server before being transferred.

The backup archive is created in:

```text
/home/deploy
```

During archive creation, the workflow checks that the `deploy` user can read every file and read/traverse every directory under `data`, excluding the rebuildable `data/etc/banlists/index/` cache, then prints the target archive path, backup filesystem disk usage, and the zip error log if compression fails. The generated hard-ban index is also excluded from the archive and is rebuilt automatically from the backed-up source lists after restoration.
Before creating a new archive, the workflow removes stale `DD-MM-YY_hh-mm-ss.zip` files from `/home/deploy`; the server copy is temporary, while Google Drive is the retained backup store.

The archive contains the `data` directory from:

```text
/var/www/fridge.dev/data
```

## Restore From Settings

Administrators can use **settings → admin settings → site management → restore backup**, directly below **manage notices**. The flow shows the ZIP-selection notice, a local archive summary, a password prompt, then a final red confirmation button. Paragraph breaks in the dialogs are preserved.

Before confirmation, the browser reads the ZIP central directory and the compressed accounts/reply JSON entries in memory. It neither uploads the ZIP nor unpacks files onto disk. The date comes from the workflow's `DD-MM-YY_hh-mm-ss.zip` filename; counts include accounts, top-level feed posts, individual replies, published journal posts, guestbook entries, private chat thread records, original images, and mdpaste records. Drafts, thumbnails, IP indexes and presence sidecars are excluded. ZIP64 directories are supported; oversized metadata, unsafe paths, encrypted archives and malformed records are rejected.

Sanitized developer-data archives are rejected on the device before authentication or upload. Newly published archives carry `data/.development-copy.json`; the reader also recognizes older developer copies from the sanitizer's empty account list and placeholder draft. The server repeats this validation before it can replace live data.

The final confirmation locks maintenance and creates one persistent restore job. The browser first uploads in resumable 2 MiB chunks. A PHP CLI worker then validates archive paths and every file's checksum, removes the current `/data` contents, and restores the archive there. The outer directory and its inherited permissions are retained because the production PHP user cannot write to the application root. Uploading and validation happen before deleting live content. The existing data is not retained as a rollback copy.

Job state, the uploaded archive and the maintenance lock are stored outside the web root and outside `/data`, in a private `fridg3-restore-*` directory under PHP's temporary directory. Maintenance radios are disabled and other POST actions are blocked during the operation. Admin page navigation opens settings with the shared progress dialog; already-open admin pages also display it. Non-admins remain behind the server-side maintenance gate, including while `/data/etc/wip` is missing or being replaced. There is no cancel action after confirmation.

The selected file begins uploading directly after final confirmation and is also saved in browser IndexedDB for resumption. The IndexedDB copy runs in the background so a large archive cannot delay creation of the restore job, its progress popup, or its upload. Closing the browser necessarily pauses unfinished upload; returning to an admin page on the same origin resumes from the last acknowledged chunk. Another browser can resume by selecting the same archive using **resume upload**. Once uploaded, the background worker continues with no open browser required. The progress dialog uses the same themed track, animated inner bar, percentage, status line, and sizing as the developer-data copy dialog. If validation, disk access or the worker fails, maintenance stays on and the progress dialog provides **retry**. A successful restore removes the temporary ZIP, writes `false` to the restored maintenance flag and removes the external maintenance lock. The progress popup then shows **ok**, linking to the homepage.

Runtime requirements: PHP CLI and the PHP Zip extension, a writable PHP temporary directory, writable `/data` contents, sufficient upload/extraction space, and a browser with IndexedDB and `DecompressionStream('deflate-raw')`. The ZIP reader uses no CDN libraries. Backup password hashes are read only in browser memory during counting and are not displayed or logged.

### Restore checks

Run `php scripts/test-backup-restore.php` and `node scripts/test-backup-archive.mjs` for isolated replacement and local summary fixtures. API denial cases are checked with `php scripts/test-backup-access.php guest`, `moderator`, `csrf`, `no-password`, and `maintenance-conflict`.

## Troubleshooting

If the workflow fails during SSH setup:

1. Verify `DEPLOY_KEY` is valid
2. Confirm the server still accepts that key for the `deploy` user
3. Confirm the server IP in the workflow is correct

If the workflow fails during Google Drive upload:

1. Verify `RCLONE_CONFIG` contains a remote named `gdrive`
2. Verify the OAuth token in the config is still valid
3. Verify `GDRIVE_BACKUP_FOLDER_ID` points to a folder the authenticated Google account can write to

If the workflow fails on archive creation:

1. Verify `/var/www/fridge.dev/data` exists on the server
2. Verify the `deploy` user can read every file and read/traverse every directory under `/var/www/fridge.dev/data`
3. Verify `zip` is installed on the server
4. Verify `/home/deploy` is writable and has enough free space for the archive

`zip` exit code `18` means at least one file was unreadable and skipped. To diagnose it on the server, run:

```sh
sudo -u deploy find /var/www/fridge.dev/data \
  -path /var/www/fridge.dev/data/etc/banlists/index -prune -o \
  \( -type f ! -readable -o -type d \( ! -readable -o ! -executable \) \) -print
```

The usual one-time ownership repair is to keep `/data` owned by the runtime user/group. Install the `acl` package, make `deploy` a member of `http`, and rerun a deployment; the deployment workflow then applies the current modes and inherited ACLs automatically:

```sh
sudo usermod -aG http deploy
sudo chown -R http:http /var/www/fridge.dev/data
sudo pacman -S acl
```

After changing `deploy` group membership, start a new SSH session before rerunning the workflow.

Every deployment normalizes existing `data` files to shared group read/write access and installs setgid/default ACL inheritance for newly-created paths. The backup and developer-data workflows also ask the `http` runtime user to restore missing group read/traverse bits before copying, which remains a compatibility repair for files created before the deployment normalizer was installed. The rebuildable hard-ban index remains excluded from archive copies.
