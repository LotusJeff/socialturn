# dev/

Development-only scripts for local use. **Never committed to the repository,
never deployed to production.** This directory is in `.gitignore` — only this
README is tracked.

Scripts placed here are for local database seeding, one-off data migrations,
diagnostics, and other tasks that are useful during development but have no
place in a production install.

---

## Contents

### `seed_manual_test_data.php`

Seeds three workspaces for manual testing of the live application. Creates:

- Three `accounts` rows (`Science & Policy`, `Tech Trends`, `Daily Commentary`)
  attached to `connected_platforms.id = 1`, each with `is_posting = 1` and
  `is_active = 1`
- One `account_settings` row per workspace (`recycle_threshold = 10`,
  `recycle_lookahead_days = 30`, `scheduling_enabled = 1`)
- One `account_schedules` row per workspace (`every_hour`, 08:00–20:00,
  `America/Chicago`)
- 100 posts per workspace (300 total): ~80% recyclable, ~20% one-time,
  with varied `post_tags` per workspace theme

**Safety guard:** aborts if the `accounts` table already has rows. Run once
on a clean database. Reads connection and user IDs from the live database;
does not touch `connected_platforms`, `users`, or `companies`.

---

## Adding new scripts

Add the script to this directory, then document it here — name, purpose, what
it reads, what it writes, and any safety conditions or run-once constraints.
