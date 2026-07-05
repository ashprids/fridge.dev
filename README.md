[![deploy](https://github.com/ashprids/fridge.dev/actions/workflows/deploy.yml/badge.svg?branch=main)](https://github.com/ashprids/fridge.dev/actions/workflows/deploy.yml)
[![lint](https://github.com/ashprids/fridge.dev/actions/workflows/code-lint.yml/badge.svg?branch=main)](https://github.com/ashprids/fridge.dev/actions/workflows/code-lint.yml)
[![backup data directory](https://github.com/ashprids/fridge.dev/actions/workflows/backup-data.yml/badge.svg?branch=main)](https://github.com/ashprids/fridge.dev/actions/workflows/backup-data.yml)
[![dev data](https://github.com/ashprids/fridge.dev/actions/workflows/publish-dev-data.yml/badge.svg?branch=main)](https://github.com/ashprids/fridge.dev/actions/workflows/publish-dev-data.yml)

# fridge.dev

source for <https://fridge.dev>.

this is a plain PHP website with file-backed runtime data. there is no database. the repo contains source code, templates, assets, tools, and docs; production content/state lives in `/data`, which is intentionally ignored by git.

## Fresh Clone Setup

requirements:

- PHP 8.3 or newer
- Node.js 18 or newer

clone and serve locally:

```bash
git clone https://github.com/ashprids/fridge.dev.git
cd fridge.dev
php -S localhost:8000
```

then open:

```text
http://localhost:8000
```

## Runtime Data

the site expects a local `data/` directory. without it, static-ish pages may load, but anything backed by posts, accounts, music, images, guestbook entries, chat, or Toast state will be missing or weird.

download a sanitized developer copy from the public Google Drive folder: <https://drive.google.com/drive/folders/1dltxdqQjfUfGwEEXVxUrOw5fuv9nk_ex>.

unzip one of the archives into the repo root so it creates:

```text
data/
```

the developer copy is generated daily from production `/data`, scrubbed for private content, and kept as a rolling set of 10 archives. setup details live in [`.github/workflows/publish-dev-data-setup.md`](.github/workflows/publish-dev-data-setup.md).

## Useful Commands

```bash
bash scripts/lint-php.sh
bash scripts/lint-javascript.sh
bash scripts/lint-css.sh
```

## Deployment

pushes to `main` deploy to `/var/www/fridge.dev` through GitHub Actions using rsync over SSH. deployment excludes runtime data, so `/data` is managed separately from git.

production `/data` backups and sanitized developer copies are handled by separate scheduled GitHub Actions workflows.

## Docs

developer docs live in [`wiki/`](wiki/). start with:

- [`wiki/Development-Environment-Setup.md`](wiki/Development-Environment-Setup.md)
- [`wiki/Architecture.md`](wiki/Architecture.md)
- [`wiki/Data-Contracts.md`](wiki/Data-Contracts.md)
- [`wiki/Deployment-and-Operations.md`](wiki/Deployment-and-Operations.md)

the website also exposes the wiki at <https://fridge.dev/wiki>.
