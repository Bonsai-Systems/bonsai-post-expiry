# Changelog

All notable changes to Bonsai Post Expiry are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-10-08

### Added
- **Settings** tab under Bonsai → Post Expiry (Administrators only, `manage_options`). Tick the post categories that need an expiry date. Stored in the non-autoloaded `bpe_settings` option with the Settings API.
- Required expiry: a post in a ticked category can't be published, scheduled or updated without an expiry date. Drafts can still be saved without one.
  - Classic editor: Publish / Schedule / Update is stopped with a message in the Post Expiry box. If the script doesn't run, the server saves a new publish as Draft and says why. A post that is already live is never unpublished by this check; it gets a warning instead.
  - Block editor (`assets/admin-block.js`): a warning notice shows, and saving is locked while the pre-publish panel is open and on posts that are already live or scheduled. Where the editor supports `editor.preSavePost`, publishing with pre-publish checks turned off is refused too.
  - The Post Expiry box lists the required categories.
- `bpe_required_categories` filter.

### Changed
- Bonsai → Post Expiry now has **Overview** and **Settings** tabs. Editors only see Overview.
- `BPE_Expiry::save()` reads the nonce and date through new `box_submitted()` and `posted_timestamp()` helpers, shared with the required check. Saving behaves the same.

## [1.1.0] - 2026-10-08

### Added
- **Bonsai → Post Expiry** screen in the shared Bonsai admin menu (bundled Bonsai Hub 1.0.2 in `lib/bonsai-hub/`). It lists upcoming expiries (soonest first, overdue ones flagged) and the last 20 expired posts, and warns when the hourly check isn't scheduled or WP-Cron is disabled. Editors and Administrators can see it (`edit_others_posts`).
- **Overview** link on the plugin's row on the Plugins screen.
- Updates from GitHub releases in wp-admin, via YahnisElsts/plugin-update-checker 5.7 (Composer, `vendor/` committed). Sites check every 6 hours and install only the `bonsai-post-expiry.zip` release asset. Optional `BPE_GITHUB_TOKEN` constant for a private repo.
- `.github/workflows/release.yml` builds that zip and attaches it when a release is published. It fails if the tag doesn't match the plugin version.
- Fixed Composer `autoloader-suffix` (`BonsaiPostExpiry`), so the autoloader class can't clash with other Bonsai plugins that bundle the same updater.
- Duplicate-install guard: a second active copy shows an admin notice instead of a fatal error.
- The hourly safety check is re-scheduled once after each update (`bpe_version` option), because updates don't re-run the activation hook.
- Plugin listed in the Bonsai Hub Plugins catalogue (hub 1.0.2).

### Changed
- Code split from one file into `inc/class-bpe-expiry.php`, `inc/class-bpe-admin.php` and `inc/class-bpe-updater.php`. The main class is now `BPE_Expiry` (was `Bonsai_Post_Expiry`). Meta keys, cron hooks, `bpe_post_types` and `bpe_post_expired` are unchanged, so existing expiries carry over.
- The edit-screen box script moved from an inline `<script>` to `assets/admin.js` (jQuery, delegated so it survives the block editor moving meta boxes). Inline styles moved to `assets/admin.css`.
- Plugin header: added Plugin URI, Author URI, Update URI and License.

## [1.0.0]

### Added
- Post Expiry box on every public post type's edit screen: date and time fields, default time `23:59`, Clear button.
- Published, scheduled and private posts switch to Draft when the expiry passes, via a single cron event per post plus an hourly sweep.
- **Expires** column on list screens.
- `bpe_post_types` filter and `bpe_post_expired` action.
