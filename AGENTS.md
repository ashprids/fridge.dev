# fridge.dev
This repository is for a website hosted at https://fridge.dev and https://m.fridge.dev (a URL specifically for the mobile view).

All information regarding how the website works and how to develop for it is available within all the `*.md` files in `/wiki/`.

## Dependencies
To develop for this website, you should have the following dependencies installed:
- php (8.3 or newer)
- nodejs (18 or newer)

These dependencies are not necessary for development but they aid in testing and validating code.

### /others/toast-discord-bot
This project uses Python 3 to run the Python script. Below are the listed requirements to make this work, installable with `pip`:
- `discord.py`
- `aiohttp`
- `pynacl`

Make sure, when developing this page, the user sets up a virtual environment, installs these packages and uses the most up-to-date versions to ensure compatibility with the latest Discord.

Below are the requirements that should be installed through your package manager:
- `ffmpeg`


### Data directory
A /data/ directory is recommended for previewing the website with high accuracy. 

If a /data/ directory is absent, the user can download a developer copy of the current production /data/ directory from Google Drive.

### /tools/frdgbeats
frdgBeats has its own wiki in /tools/frdgbeats/wiki. Make sure the
wiki is kept up-to-date with any changes made; update it whenever you
make a change wherever necessary.

frdgBeats includes an `automate` tab next to the mixer. Automation is stored per channel in `.frdgbeats` project JSON as `automation` lanes. Each lane targets channel `volume`/`pan`, a numeric synth param, or a numeric effect param, and stores pattern-specific 16/32 step-aligned values in `valuesByPattern` plus `enabled` and `mode` (`step` or `smooth`).

When changing synths, effects, demos, presets, import/export, or project hydration, preserve the automation contract. Numeric params declared in synth/effect `params` become automation targets, so keep param ids stable and include sensible `min`, `max`, `step`, and `default` values. Automation must apply only to the currently selected/playing pattern, never globally across every pattern on a channel.

Bundled frdgBeats synth presets must use tagged names: `[BA]` bass, `[FX]` effects, `[LD]` leads, `[PD]` pads, `[PL]` plucks, `[SQ]` sequences when the synth supports sequencing, and `[SY]` general synth patches. Production synths should provide 10 unique presets for each applicable type.

## Environment
When deployed, the website is running on an Nginx webserver with PHP and Python installed. The operating system is Arch Linux, so all implementations should be made with this in mind.

The Nginx configurations on the web server can be found at `/etc/nginx/nginx.conf` and `/etc/nginx/sites-enabled/fridge.dev`.

## AI Workflow

### Updating the Wiki
Whenever you make a change, you should always check if the change is worth mentioning in the wiki by looking through the pages and checking whether or not the topic of your change is mentioned. 

If it is, then edit that section to reflect your changes and if not, then if it's important enough, add a new section (or a new page entirely, if necessary).
