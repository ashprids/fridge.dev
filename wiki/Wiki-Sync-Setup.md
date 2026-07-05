# GitHub Wiki Sync Workflow

This repository includes a workflow at `.github/workflows/sync-wiki.yml` that mirrors the local `/wiki` directory to the GitHub wiki for this repo.

## What It Does

Each run:

1. Checks out the main repository
2. Clones `https://github.com/ashprids/fridge.dev.wiki.git`
3. Syncs `/wiki` into the wiki repo with deletion enabled
4. Commits only if something changed
5. Pushes the updated wiki back to GitHub

## Trigger

The workflow runs on:

1. Pushes to `main`
2. Manual runs via `workflow_dispatch`

## Required Secret

Create this repository secret in `Settings` -> `Secrets and variables` -> `Actions`.

### `WIKI_PUSH_TOKEN`

This should be a GitHub token that can push to:

```text
ashprids/fridge.dev.wiki
```

Safest options:

1. A classic personal access token with `repo` scope
2. A fine-grained token with write access to repository contents for `ashprids/fridge.dev`

## Notes

- The workflow mirrors `/wiki` exactly, so deleted local wiki files will also be deleted from the GitHub wiki.
- `Home.md` and `_Sidebar.md` are supported as normal wiki pages.
- The workflow pushes to the wiki repo’s default branch, which is typically `master`.

## Troubleshooting

If cloning fails:

1. Verify `WIKI_PUSH_TOKEN` exists
2. Verify the token can access the repository wiki
3. Verify the wiki is enabled for the repository

If pushing fails:

1. Verify the token has write access
2. Check whether branch protections or org policies are blocking the push
3. Confirm the wiki repo still uses `master` as its default branch
