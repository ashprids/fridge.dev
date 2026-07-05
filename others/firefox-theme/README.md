# fridge.dev Firefox Theme

This folder has two separate pieces:

- the Firefox Add-ons theme, installed from Mozilla once it is published and signed
- the optional profile chrome layer, installed locally with `userChrome.css`, for square tabs, square toolbar controls, and the site-style toolbar background

## Recommended User Setup

1. Install the signed theme from Mozilla Add-ons.
2. Download and unzip `fridg3-firefox-userchrome.zip` from the fridge.dev theme page.
3. Run the installer for your OS and choose whether to install or uninstall the userChrome layer.
4. Restart Firefox.

Linux:

```bash
bash install-linux.sh
```

Windows:

```bat
install-windows.bat
```

When installing, the scripts copy this theme to `chrome/fridg3-theme.userChrome.css`, add an `@import` line to the profile `userChrome.css`, and enable `toolkit.legacyUserProfileCustomizations.stylesheets` through `user.js`.

When uninstalling, the scripts remove `chrome/fridg3-theme.userChrome.css` and remove only the fridge.dev `@import` line from profile `userChrome.css`. Other custom Firefox CSS is left alone.

## Building Downloads

From the repository root:

```bash
bash others/firefox-theme/build-downloads.sh
```

From this folder:

```bash
bash build-downloads.sh
```

The script creates:

- `downloads/fridg3-firefox-userchrome.zip`, with the install scripts and `userChrome.css` inside an extracted `fridg3-firefox-userchrome` folder
- `downloads/fridg3-firefox-theme-amo.zip`, with the WebExtension theme files at the archive root

## Add-ons Upload Package

`downloads/fridg3-firefox-theme-amo.zip` is the package intended for Mozilla Add-ons. It contains only:

```text
manifest.json
images/theme-frame.svg
```

Firefox WebExtension themes cannot install profile `userChrome.css`, so the square-tab layer stays in the separate userChrome download.
