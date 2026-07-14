# Backup Data Workflow

This repository includes a GitHub Actions workflow at `.github/workflows/backup-data.yml` that backs up the server-side `data` directory to Google Drive.

## What It Does

Each run:

1. Connects to `deploy@45.76.134.105` over SSH
2. Changes directory to `/var/www/fridge.dev`
3. Compresses the `data` directory into a zip file named `DD-MM-YY_hh-mm-ss.zip`
4. Downloads that zip file to the GitHub Actions runner
5. Uploads the zip file into a specific Google Drive folder
6. Keeps only the 10 newest backup files in that folder
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

## How To Generate `rclone.conf`

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

The usual repair is to keep `/data` owned by the runtime user/group and make it group-readable to `deploy`:

```sh
sudo usermod -aG http deploy
sudo chown -R http:http /var/www/fridge.dev/data
sudo find /var/www/fridge.dev/data -type d -exec chmod 750 {} +
sudo find /var/www/fridge.dev/data -type f -exec chmod 640 {} +
```

After changing `deploy` group membership, start a new SSH session before rerunning the workflow.
