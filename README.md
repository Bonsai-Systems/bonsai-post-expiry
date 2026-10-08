# Bonsai Post Expiry

Set an expiry date and time on any post, page or custom post type. When it passes, the post switches back to **Draft**. Nothing is deleted.

Built by [The Bonsai Digital Collective](https://bonsaidigitalcollective.co.uk/). Source: [Bonsai-Systems/bonsai-post-expiry](https://github.com/Bonsai-Systems/bonsai-post-expiry).

## Using it

1. Open any post or page. The **Post Expiry** box is in the sidebar.
2. Pick an **Expiry date**. If you leave the time blank, it fills in `23:59` (the end of that day).
3. Save or update the post.

When the time passes, a published, scheduled or private post switches to Draft. Times use the site timezone (**Settings → General**), which the box shows.

- **Clear expiry** empties both fields. Save the post to remove the expiry.
- A date in the past takes effect on the next cron run, usually the next time someone visits the site.
- After a post expires, the box shows **Expired on …** until you set a new date.
- List screens (**Posts**, **Pages** etc.) have an **Expires** column showing the date, or **Expired** and the date it happened.

## Bonsai → Post Expiry

The plugin's screen in the shared **Bonsai** admin menu lists:

- **Upcoming expiries**, soonest first, with each post's type and status and how long it has left. Anything overdue is marked **Due on next cron run**.
- **Recently expired**: the last 20 posts to expire, with their current status.

Editors and Administrators can see it (`edit_others_posts`). There are no settings, because expiries are set on each post.

The screen also warns you if:

- the hourly safety check isn't scheduled (deactivate and reactivate the plugin to fix it), or
- WP-Cron is disabled (`DISABLE_WP_CRON`). Posts then only expire when a server cron job calls `wp-cron.php`, so check one is set up with the host.

## How it works

- Each expiry is a single WP-Cron event, scheduled when the post is saved.
- An hourly check catches anything overdue whose event was missed, for example after a site migration or backup restore.
- WP-Cron runs when someone visits the site. On a site with very little traffic, an expiry can be a few minutes late. Use a real server cron for exact timing.
- Deactivating the plugin clears its cron events but keeps the expiry dates. After you reactivate it, the hourly check expires each post within an hour of its time.
- Quick Edit and REST-only saves don't change the expiry.

## Install

1. Download `bonsai-post-expiry.zip` from the [latest release](https://github.com/Bonsai-Systems/bonsai-post-expiry/releases/latest), upload it under **Plugins → Add New → Upload Plugin**, then activate it. Don't use GitHub's "Source code" zip: it unpacks to a versioned folder name and includes dev files.
2. On a site that already has another Bonsai plugin, you can install it from **Bonsai → Plugins** instead (Bonsai Hub 1.0.2 or later).

Sites on 1.0.0 have no updater, so they need this manual install once. Existing expiry dates and scheduled events carry over.

## Bonsai menu

This plugin's screen lives in the shared **Bonsai** admin menu, provided by [Bonsai Hub](https://github.com/Bonsai-Systems/bonsai-hub). A copy of the hub is bundled in `lib/bonsai-hub/`, so this plugin sets up the menu on its own. Other Bonsai plugins appear alongside it, and **Bonsai → Plugins** installs, activates and deactivates the rest of the suite.

- Don't edit `lib/bonsai-hub/` by hand. Change the bonsai-hub repo and run its `bin/sync.sh`.
- Release zips must include `lib/`.

## Updates

The plugin updates itself from GitHub releases. It uses [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker), installed with Composer (`vendor/` is committed). New versions appear under **Plugins** in wp-admin like any other plugin update. Sites check every 6 hours, or straight away when you click **Check for updates** on the plugin's row.

To ship a new version:

1. Bump `Version:` in `bonsai-post-expiry.php` **and** `BPE_VERSION`, and add a `CHANGELOG.md` entry.
2. Commit and push to `main`.
3. Publish a GitHub release tagged with the version, e.g. `v1.1.0` or `1.1.0`.
4. The **Release zip** GitHub Action builds `bonsai-post-expiry.zip` and attaches it to the release. It stops with an error if the tag doesn't match both version numbers.

Sites only take the attached `bonsai-post-expiry.zip` and never GitHub's source archive, so a release with no zip isn't offered.

If the repo is ever made private, add a read-only GitHub token to `wp-config.php` on each site:

```php
define( 'BPE_GITHUB_TOKEN', 'github_pat_...' );
```

The Composer autoloader has its own fixed suffix (`ComposerAutoloaderInitBonsaiPostExpiry`), so it can't clash with other Bonsai plugins that bundle the same updater. If two copies of this plugin are active at once, the second one shows an admin notice instead of causing a fatal error.

## For developers

Limit which post types get the box, column and overview:

```php
add_filter( 'bpe_post_types', function ( $types ) {
	return array( 'post', 'event' );
} );
```

Do something when a post expires, e.g. send a notification, call a Make.com webhook or purge a cache:

```php
add_action( 'bpe_post_expired', function ( $post_id, $post ) {
	// $post is the post as it was before it switched to Draft.
}, 10, 2 );
```

The default time is the `BPE_Expiry::DEFAULT_TIME` constant (`23:59`).

Stored data: `_bpe_expires` (pending expiry, UTC timestamp) and `_bpe_expired_at` (when it expired) in post meta, plus the `bpe_version` option. Deleting the plugin leaves these in place.
