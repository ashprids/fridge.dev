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

## Environment
When deployed, the website is running on an Nginx webserver with PHP and Python installed. The operating system is Arch Linux, so all implementations should be made with this in mind.

The Nginx configurations on the web server can be found at `/etc/nginx/nginx.conf` and `/etc/nginx/sites-enabled/fridge.dev`.

## AI Workflow

### Updating the Wiki
Whenever you make a change, you should always check if the change is worth mentioning in the wiki by looking through the pages and checking whether or not the topic of your change is mentioned. 

If it is, then edit that section to reflect your changes and if not, then if it's important enough, add a new section (or a new page entirely, if necessary).
