# MEMORY.md — Bonsai Post Expiry

Decisions, gotchas and open items that aren't obvious from the code. Keep entries short and dated.

## Key decisions

- **2026-10-08: Expire to Draft, never trash or delete.** It's reversible, and the client can republish with a new date.
- **2026-10-08: Single event per post plus an hourly sweep.** The single event gives near-exact timing. The sweep covers events lost to migrations, DB restores or cron failures. `expire()` re-reads the meta, so stale or duplicate events are harmless.
- **2026-10-08: Saves need the box's nonce.** This stops quick edit, REST-only saves and the plugin's own `wp_update_post()` from wiping or re-scheduling the expiry.
- **2026-10-08: Joined the Bonsai suite in 1.1.0.** Bundled Bonsai Hub, GitHub-release updater (same setup as SEO/GEO Checker) and a catalogue entry in hub 1.0.2. The repo was created at the same time.
- **2026-10-08: The overview has no settings.** Expiries are per post. The default time stays a constant (`BPE_Expiry::DEFAULT_TIME`) and post types stay a filter (`bpe_post_types`). The screen lists upcoming and recently expired posts so a client or PM can see what's about to come down.
- **2026-10-08: Overview capability is `edit_others_posts`.** Editors schedule content, so they need to see it. The rest of the suite uses `manage_options`.
- **2026-10-08: Kept meta keys and cron hook names from 1.0.0.** Live sites have queued `bpe_expire_post` events and `_bpe_expires` meta. Only the PHP class was renamed (`Bonsai_Post_Expiry` → `BPE_Expiry`). Cron stores hook names, not callbacks, so the rename is safe.
- **2026-10-08: `maybe_upgrade()` on `init`.** Updates don't run activation hooks, so each new version re-checks that the sweep is scheduled. It costs one autoloaded option read per request.

## Gotchas

- With `DISABLE_WP_CRON` and no server cron, nothing expires. The overview shows a notice, but check the host's setup on every client site.
- WP-Cron only runs on page loads. Low-traffic sites can expire a few minutes late.
- The block editor doesn't re-render meta boxes after saving. "Expired on …" only appears after the edit screen is reloaded.
- The sweep processes at most 100 posts per hourly run.
- Deactivating clears all queued events. After reactivating, future expiries only fire through the hourly sweep, so they can be up to an hour late. Re-saving the post queues the exact event again.

## Open items / tech debt

- [ ] No PHPUnit tests. Cron jobs mutate data, so Bonsai rules require them: `save()` (timezone, default time, invalid date), `expire()` (stale event, draft/trashed post), `sweep()`.
- [ ] No `uninstall.php`. `_bpe_expires`, `_bpe_expired_at` and `bpe_version` stay after the plugin is deleted.
- [ ] `expire()` on a post that isn't expirable (e.g. already a draft) still records `_bpe_expired_at`, so it appears under "Recently expired" although nothing changed. Consider only recording it when the status actually changed.
- [ ] Possible: a settings tab for the default time and post types, email notification on expiry, "expire to" status other than Draft.
