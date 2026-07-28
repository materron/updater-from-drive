# Updater from Drive

A WordPress plugin that keeps your installed plugins up to date from ZIP packages
kept in a Google Drive folder you own and control.

Point a site at a publicly shared Drive folder, and it will work out which
packages are newer than what is installed and install them.

## How it works

Matching is done from package **file names**, so a check costs a single listing
request and never downloads anything speculatively:

```
plugin-folder-name-version.zip     e.g. contact-form-7-6.0.1.zip
```

A file name is a claim, not a fact. So immediately before installing — when the
archive has been downloaded anyway, and the check is therefore free — the ZIP is
opened and its plugin header is read. If the contents do not match the name,
nothing is installed and the reason is logged:

> Nothing was updated, and it is worth checking your Drive folder. The file
> "x.zip" is named as if it were an update for A, but it actually contains a
> different plugin (B).

## Safety rules

- Never downgrades: a package is installed only when strictly newer.
- Never installs a package whose contents disagree with its name.
- Installation goes through the WordPress core upgrader, not a hand-rolled unzip.
- Automatic daily checking is off until switched on.
- Only read-only access to Drive is ever used.

## Setup

1. Share your packages folder in Google Drive as "Anyone with the link" (Viewer).
2. In the Google Cloud Console, create a project, enable the Google Drive API and
   create an API key. This is free and needs no billing account.
3. In Settings → Drive Updater, paste the key and the folder address.

The API key can be kept out of the database with a constant in `wp-config.php`:

```php
define( 'UFDRIVE_API_KEY', 'AIza....' );
```

## Updating the plugin itself

This plugin is not in the WordPress.org directory, so it checks its own
distribution directory for new versions and reports them to WordPress in the
usual way — the update then appears on the Plugins screen like any other.

The version lives only in the package file name, so there is no manifest to keep
in step with the packages. The archive is still opened and checked before it
replaces a working install.

Point it somewhere else with a constant:

```php
define( 'UFDRIVE_UPDATE_URL', 'https://example.com/my-plugins/' );
```

or with the `ufdrive_update_source_url` filter.

## Requirements

- WordPress 6.3 or newer
- PHP 7.4 or newer, with the `zip` extension

## Notes

Shared drives are supported. Packages stored in a shared drive are invisible to
the Drive API unless every request opts in, which this plugin does.

## Licence

GPL-2.0-or-later
