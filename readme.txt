=== Updater from Drive ===
Contributors: materron
Tags: updates, google drive, updater, deployment
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Updates your plugins from ZIP packages kept in a Google Drive folder that you own and control.

== Description ==

If you keep your own plugin packages in Google Drive, this plugin lets that folder act as the update source for a site.

You share the folder publicly, paste its address and a free Google API key, and the site does the rest: it lists the packages, works out which ones are newer than what is installed, and installs them.

= How packages are matched =

By file name. A package called `contact-form-7-6.0.1.zip` is read as "version 6.0.1 of the plugin whose folder is `contact-form-7`". That is what lets a check cost nothing: no package is downloaded just to find out what it is.

Because the file name is a claim rather than a fact, the archive is opened and checked immediately before installing, when it has been downloaded anyway. If the contents do not match the name, nothing is installed and the reason is written to the activity log.

= The naming convention =

`plugin-folder-name-version.zip`

* The first part must match the folder the plugin installs into, not its display name. Contact Form 7 lives in `contact-form-7`.
* The version can be `1.2`, `1.2.3`, `1.2.3.4` or `2.0-beta`, with or without a leading `v`.
* Files that do not follow this pattern are ignored, and listed in the activity log so you can spot them.
* Decorative endings are understood: a package named `something-plugin` will be matched to a plugin installed in `something`, and punctuation is ignored, so `gravity-forms-quiz` finds `gravityformsquiz`.

Some pairings cannot be guessed from a name at all: nothing suggests that `woothemes-sensei` is published as `woocommerce-paid-courses`. Those are listed on the settings screen so you can pair them yourself, once.

= What it will and will not do =

* It only ever installs a package whose version is higher than the installed one. It never downgrades and never reinstalls the same version.
* It never installs a package whose contents do not match its name.
* By default it considers every installed plugin. You can restrict it to a list.
* Automatic daily checking is off until you turn it on.

= Your folder, your responsibility =

This plugin installs whatever you put in your own Drive folder. It is a tool for people who publish their own packages, and it assumes you trust the contents of that folder completely.

Please understand before using it:

* **Anyone with the link can download from a publicly shared folder.** Do not put anything private in it.
* **You are responsible for what the packages contain.** The plugin checks that a package contains the plugin its name promises, but it cannot tell you whether the code inside is correct, safe or working.
* **Naming the files correctly is your job.** A misnamed file is skipped or refused, not guessed at.
* **Test on a staging site first.** Automatic updates on a production site, from any source, carry risk.

The plugin is provided without warranty of any kind, as set out in the GPL.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. In Google Drive, share your packages folder so that anyone with the link can view it, and copy its address.
3. In the Google Cloud Console, create a project, enable the Google Drive API, and create an API key. This is free and does not require a billing account.
4. In Settings → Drive Updater, paste the API key and the folder address, and save.
5. Press "Check the folder and update", or switch on automatic daily updates.

To keep the key out of the database, define it in `wp-config.php` instead. It then takes precedence over the settings screen:

`define( 'UFDRIVE_API_KEY', 'AIza....' );`

== Frequently Asked Questions ==

= Where do I find the folder address? =

Open the folder in Google Drive and copy the address from your browser. You can paste the whole thing; the folder ID is extracted for you.

= Does the folder have to be public? =

Yes, in this version. Google will not serve a private folder without a signed-in user, and the plugin does not ask anyone to sign in. "Anyone with the link" is the setting you need.

= Does the API key give access to my Google account? =

No. An API key identifies the caller to Google, it does not grant access to anything. It can only read what is already public. It cannot read your private Drive files, and it cannot change or delete anything.

= Does it cost anything? =

No. The Google Drive API is free to call, and creating a project and an API key does not require a billing account.

= Why was my package ignored? =

Almost always the file name. It must be the plugin's folder name, a hyphen, and a version. Check the activity log on the settings screen: files that could not be read are listed there by name.

= It says a package does not contain the plugin it claims to. What now? =

The archive was downloaded and opened, and the plugin inside it is not the one the file name promised. Nothing was installed. Rename the file in your Drive folder to match its actual contents.

= Can it update plugins from the WordPress.org directory? =

It can update any plugin you have installed, but that is not what it is for. Plugins from the directory should be updated through WordPress itself.

== External Services ==

This plugin connects to Google Drive to list and download the plugin packages you keep there. This connection is what the plugin exists to do, and it only happens after you have supplied your own API key and folder address.

**Google Drive API** — https://developers.google.com/drive

* **What is sent:** your Google API key, and the ID of the Drive folder you configured. The plugin asks for a list of the ZIP files in that folder, and downloads individual files from it.
* **When:** when you press "Check the folder and update", and once a day if automatic updates are switched on.
* **What is received:** the names, IDs, sizes and modification times of the packages in that folder, and the package contents themselves.

No content from your site is ever sent to Google. No analytics, telemetry or usage data is transmitted anywhere.

Google Terms of Service: https://policies.google.com/terms
Google Privacy Policy: https://policies.google.com/privacy

**Updater from Drive distribution directory** — https://potencia.pro/own-plugins/

This plugin is not distributed through the WordPress.org directory, so it checks a plain web directory for new versions of itself. Without this, an installed copy would never learn that an update exists.

* **What is sent:** nothing but the HTTP request itself. No site address, no identifier, no version number, no statistics. The request is indistinguishable from anyone opening that address in a browser.
* **When:** at most once every twelve hours, when WordPress refreshes its list of available updates.
* **What is received:** the directory listing, and the package itself when an update is installed.

To point this at your own mirror instead, define `UFDRIVE_UPDATE_URL` in `wp-config.php`, or filter `ufdrive_update_source_url`. Site owners who would rather the plugin never contacted it can point it at a directory of their own.

Potencia Pro Privacy Policy: https://potencia.pro/politicas-de-privacidad/

== Changelog ==

= 1.0.3 =
* Packages whose name does not match the folder a plugin installs into are now recognised anyway. Astra Pro is published as astra-addon-plugin but installs into astra-addon; that pairing, and others like it, no longer need setting up by hand.
* Plugins that could not be paired with any package are listed on the settings screen, with a way to pair them in one step. Previously a plugin with a mismatched name was silently never updated.
* A package whose contents disagree with its file name is now used if it still moves the version forward, and the discrepancy is logged rather than refusing the update outright.

= 1.0.2 =
* The plugin now keeps itself up to date from its own distribution directory.

= 1.0.0 =
* Initial release.
