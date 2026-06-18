# Changelog

## [0.9.8] — 2026-06-18

### Fixed
- `db/migrations/026_admin_settings.sql` no longer seeds the four deprecated global
  credential rows (`twitter_apikey`, `twitter_apisecret`, `meta_app_id`,
  `meta_app_secret`). These rows were removed from `admin_settings` in v0.9.5 (GAP 1)
  but migration 026 continued to re-insert them on every fresh install via
  `INSERT IGNORE`. Fresh installs now contain only the seven active settings rows.

### Schema
- Migration 036: deletes `twitter_apikey`, `twitter_apisecret`, `meta_app_id`, and
  `meta_app_secret` from `admin_settings` on existing installs where they are still
  present. No-op if the rows are already absent. Existing installs upgrading from any
  0.9.x: run `db/migrations/036_remove_credential_admin_settings.sql`.

### Internal
- `admin_settings` `setting_key` column `COMMENT` in `schema.sql` updated to use
  `owner_email` as the example key instead of the removed `twitter_apikey`.

---

## [0.9.7] — 2026-06-18

### Changed
- **Account → Workspace UI rename** — all UI-rendered text (view labels, page headings, flash
  messages, outbound emails) updated to use "Workspace" in place of "Account." Internal codebase
  variable names, table names, and column names are unchanged.
- **Settings → Connections screen** (`connect/index`) — new sub-screen listing all
  `connected_platforms` rows with platform badge, connection status, workspace count, and
  Reconnect / Disconnect actions. Accessible via a new Settings overview card and the
  Settings → Connections breadcrumb.
- **Reconnect feature** — "Reconnect" action on the Connections screen re-runs the full OAuth
  handshake for an existing `connected_platforms` row and updates it in place. The row id, all
  workspace references, and post history are preserved. Developer app credentials and OAuth tokens
  are overwritten with whatever the new handshake produces.
- **Masked credential display** — on the Connections screen, `app_key` is shown in full
  (it is an identifier); `app_secret` is shown as bullets + last 4 characters only
  (e.g. `••••a8f2`). The full secret is never rendered in any view.
- **Team moved into Settings** — Team removed from the top-level header navigation. A Team card
  added to the Settings overview shows a live member count. Settings → Team breadcrumb added to
  the Team screen.
- **Settings breadcrumbs** — Connections and Team sub-screens display a Settings → [Screen]
  breadcrumb for navigation consistency.

### Schema
- Migration 035: `connected_platform_id INT UNSIGNED NULL` added to `oauth_states`; FK to
  `connected_platforms(id)` ON DELETE SET NULL. Carries the target row id through the OAuth
  reconnect handshake so the callback can UPDATE the existing row rather than INSERT a new one.
  Existing installs upgrading from 0.9.6: run `db/migrations/035_oauth_states_reconnect.sql`.

---

## [0.9.6] — 2026-06-17

### Changed
- Install wizard reduced from 4 steps to 3. Step 4 (Platform Credentials) removed
  entirely — platform credentials are per-connection, entered via Connect Twitter /
  Connect Facebook after install. They have no place in a global install step and no
  longer exist as global settings (see 0.9.5).
- Install wizard now validates everything before writing anything. Revised sequence:
  validate fields → pre-flight writability checks (ini directory and web root) → test
  DB connection → check for existing data → only then run any writes (schema, DB
  transaction, files). Previously, the DB transaction committed before the file writes;
  a file-write failure left the database populated with no config files on disk,
  requiring manual cleanup. This class of failure is now impossible: both target paths
  are confirmed writable before the first write is attempted.

### Fixed
- Pre-flight writability check for the web root directory (where `boot.php` is written)
  was absent. On the previous fresh install run, the DB committed and `socialturn.ini`
  was written, but `boot.php` failed due to directory permissions — leaving a partially
  installed state that required manual intervention. Added `is_writable(ROOT)` check
  alongside the existing ini-dir check, both running before any DB or file write.

---

## [0.9.5] — 2026-06-17

### Changed
- Developer app credentials (Twitter Consumer Key/Secret; Meta App ID/Secret) moved from
  global `admin_settings` to per-row `app_key` and `app_secret` columns on
  `connected_platforms`. Each platform connection now carries its own credentials,
  enabling multiple independent developer apps to coexist (e.g. two Twitter accounts
  under separate apps).
- Twitter and Facebook connect flows now show a credential entry form before initiating
  OAuth. Credentials are stored in `oauth_states` during the handshake and written to
  `connected_platforms` on success — never stored as global settings.
- `TwitterService` now accepts app credentials as constructor parameters. All
  `TWITTER_APIKEY` / `TWITTER_APISECRET` constant references replaced with injected values.
- `AbstractMetaService` (parent of `FacebookService` and `InstagramService`) now accepts
  `$appId` and `$appSecret` as constructor parameters. All `META_APP_ID` / `META_APP_SECRET`
  constant references replaced with injected values.
- `cron_dispatchToPlatform()` instantiates service classes with per-row credentials read
  from `$account['app_key']` / `$account['app_secret']` (selected by `cron_fetchActiveAccounts()`).
- `AbstractMetaService::refreshToken()` reads `app_key` and `app_secret` from the
  `connected_platforms` row being refreshed, not from constructor-injected values — a
  single maintenance call works correctly regardless of which service instance was created.

### Removed
- **Settings → Platform Credentials screen** (`settings/platforms`) removed. App credentials
  are entered per-connection at connect time, not stored or edited globally.
- `twitter_apikey`, `twitter_apisecret`, `meta_app_id`, `meta_app_secret` rows removed from
  `admin_settings` seed data and from `load_admin_settings()` `$keyMap`.
- PHP constants `TWITTER_APIKEY`, `TWITTER_APISECRET`, `META_APP_ID`, `META_APP_SECRET` no
  longer defined anywhere in the application.

### Schema
- Migration 034: `app_key VARCHAR(255) NULL` and `app_secret VARCHAR(255) NULL` added to
  `connected_platforms`. `oauth_states.app_key` and `oauth_states.app_secret` (added in
  migration 033, previously unused) are now actively used to transit credentials through
  the OAuth handshake.
- Existing installs upgrading from 0.9.4: run `db/migrations/034_per_connection_app_credentials.sql`,
  then reconnect each platform connection through the new credential form — existing OAuth
  tokens remain valid and only the app credential columns need to be populated.

---

## [0.9.4] — 2026-06-17

### Fixed
- OAuth handshake state for both Twitter and Facebook connect flows moved from
  `$_SESSION['oauth']` to the `oauth_states` database table. Previously, starting
  a second OAuth flow in a new browser tab (same session) would overwrite the first
  flow's request token secret or CSRF state key, causing the first flow to fail on
  callback. Each flow now writes its own independent row keyed by a unique `state_key`
  (64-char hex), so concurrent flows in the same browser session complete independently
  without collision.
- Twitter callback now looks up handshake state by `request_token` (which Twitter
  echoes back as `oauth_token` in the callback URL) rather than a SESSION key.
- Facebook callback now validates CSRF state by `state_key` DB lookup rather than
  reading `$_SESSION['oauth']['facebook_state']`.
- `oauth_states` rows are deleted immediately on first use (prevents replay). Rows
  older than 15 minutes are treated as expired — the flow errors with a clear message
  and the stale row is deleted. The cron maintenance pass now purges abandoned rows
  older than 15 minutes automatically.
- SESSION is still used (correctly, by design) for the Facebook post-handshake
  page-selection step (`facebook_pages`, `facebook_instagram`, `expires` keys) — that
  is not a handshake state concern and is unaffected.

### Schema
- Migration 033: `oauth_states` table refactored for active use. Drops `account_id`
  column, `idx_oauth_states_account_id` key, and `fk_oauth_states_account` FK (no
  `accounts` row exists during the connect flow — the FK was never satisfiable). Adds
  `app_key VARCHAR(255) NULL` and `app_secret VARCHAR(255) NULL` (nullable, unused
  until GAP 1 per-connection app credentials lands).

## [0.9.3] — 2026-04-24

### Fixed
- StorageService::store() used @copy() which suppressed write permission failures; @ removed and
  error_log() added; accounts/update now returns an actionable error when upload fails rather than
  a misleading "base image required" message
- accounts/update fatal error when dynamic images enabled — $imageSettingsChanged block referenced
  image_filename and image_source on posts table; both columns moved to post_images in migration 031;
  SELECT and DELETE updated to query post_images JOIN posts
- queue/view, queue/history, queue/errors fatal errors — final_image_filename (singular) renamed to
  final_image_filenames (plural, JSON array) in migration 032; all stale references updated across
  controllers and views
- RecycleService::countPendingPosts() scoped pending count to connected_platform_id only; accounts
  sharing a platform pooled their counts causing queue population to never trigger for lower-volume
  accounts; fixed by joining through posts.account_id
- "Has image" badge in queue/view clipped by text-truncate div; moved to its own flex-shrink-0
  element; badge is now clickable and opens a Bootstrap 5 modal preview of the first image
- overlay_font_color and overlay_font_size changes did not trigger generated image invalidation;
  pre-save SELECT and $imageSettingsChanged condition extended to include both columns
- TwitterService::uploadMedia() swallowed exceptions silently; catch block now captures and
  surfaces the actual error message in post_history
- TwitterService::uploadMedia() double-wrapped CURLFile caused PHP 8 TypeError; filepath string
  now passed directly to twitteroauth upload() which handles CURLFile construction internally
- Inline generated image flush logic extracted from accounts.php::update() into
  src/Services/GeneratedImageService — deleteForAccount(int $accountId): int;
  accounts.php::update() now calls the service instead of duplicating the logic
- Dead file views/connect/facebook.php removed — contained credential-in-URL pattern
  from prior implementation, not reachable in live routing
- .htaccess PHP execution block added for images/ — brings Apache config to parity
  with nginx.conf.sample
- .htaccess and nginx.conf.sample deny rules added for vendor/, src/, libraries/, and db/
- Dead code removal — deleted views/users/invite.php, views/users/inform.php, db/1.txt, db/2.txt, images/index.htm
- Removed dead functions from controllers/users.php: validate(), invite(), inform()
- Removed dead functions from libraries/shared.php: hashPassword(), verifyPassword(), sendemail(), upload()
- Stripped vestigial <form action="users/validate"> wrappers from views/oops/notfound.php,
  permissions.php, noaccounts.php
- Removed dead users/inform link from views/oops/noaccounts.php

### Added
- TrueType font overlay system using Poppins SemiBold 600 (assets/fonts/Poppins-SemiBold.ttf);
  replaces GD bitmap rendering; text centered horizontally and vertically, wraps at 80% canvas width,
  color and size driven by per-account overlay_font_color and overlay_font_size settings
- "Has image" badge in queue/view is now a clickable modal image preview

## [0.9.2] — 2026-04-24

### Multi-Image Post Support

- **post_images table** (migration 031) — replaces `posts.image_filename` and
  `posts.image_source`. Each post supports up to 4 images, ordered by `sort_order`.
  Unique constraint on `(post_id, sort_order)`; FK to `posts.id`.
- **Sort order management and per-image delete** in the content edit view.
- **URL image fetch** — paste a remote image URL on create or edit; fetched via curl
  with SSL verification, validated as JPG or PNG by MIME type, stored via StorageService
  with `image_source = 'uploaded'` — treated identically to a directly uploaded image
  after storage. `'url_fetched'` is reserved in the ENUM but never written.
- **Overlay font controls** — font color (hex picker) and font size (30–70 pt)
  configurable per account for dynamic image generation. Stored as `overlay_font_color`
  and `overlay_font_size` on the `accounts` table (migration 031).

### Multi-Image Cron Dispatch

- **scheduled_posts.final_image_filenames** (migration 032) — replaces
  `final_image_filename VARCHAR`; JSON array of processed image filenames ready for
  dispatch. NULL = text-only post.
- **post_history.image_filenames** (migration 032) — replaces `image_filename VARCHAR`;
  JSON array of filenames at time of posting.
- **TwitterService** — uploads all images as separate media objects, passes all media
  IDs to the v2 tweet endpoint in one request.
- **FacebookService** — two-phase dispatch: each image uploaded unpublished to
  `/{page_id}/photos`, then a single `/feed` post references all photo IDs via
  `attached_media[]`.
- **InstagramService** — single image uses the existing container → publish flow;
  2–4 images use the carousel flow (per-image carousel item containers → CAROUSEL
  container with child IDs → media_publish).
- **QueuePopulationService** — reads all `post_images` rows per post ordered by
  `sort_order`, calls `prepareForPlatform()` per image, stores result as JSON in
  `final_image_filenames`.

### Migrations

- Run `db/migrations/031_post_images.sql` and `db/migrations/032_multi_image_filenames.sql`
  when upgrading from 0.9.1. Fresh installs use `db/schema.sql`.

### PHPUnit Fixes

- `scheduling_enabled = 1` added to `seedBaseFixture()` — `DEFAULT 0` caused all
  `QueuePopulationService` integration tests to silently fail.
- PDO integer type cast removed from `ContentStoreTest` TC5 — PHP 8.1+ returns native
  integers from `fetchColumn()`, not strings.
- Phantom `tags_truncated` assertion removed from `QueuePopulationServiceTest` TC14 and
  `RecycleServiceTest` TC5 — key never existed in `populate()`'s return value.

---

## [0.9.1] — 2026-04-18

### Install Wizard & Settings UI

- **Install wizard** (`install.php`) — self-contained multi-step setup replacing
  manual `config.php` editing. Collects database credentials, admin account,
  Postmark email settings, and platform API keys. Runs `db/schema.sql` and
  migration 026 automatically. Writes `config.ini` and sets permissions 0600.
- **config.ini** replaces `config.php` as the runtime configuration file.
  Contains only five values: `db_host`, `db_name`, `db_user`, `db_pass`,
  `base_url`. All other settings move to `admin_settings` in the database.
- **admin_settings table** (migration 026) — stores all application
  configuration beyond DB credentials and BASE_URL, loaded at bootstrap as PHP
  constants by `load_admin_settings()`.
- **Settings UI** — new Settings section in the admin nav with four sub-pages:
  Database & Site URL, Email, Platform Credentials, Application Settings.
  All settings previously in `config.php` are now editable in the UI.
- **Security warning banner** — index.php checks for the presence of
  `install.php` after every request and displays a persistent alert to admin
  users until the file is deleted.
- **config.sample.php removed** — superseded by the install wizard.
- **setup() removed** from `controllers/users.php` — first-run setup is now
  handled entirely by the install wizard.

### Query-String Routing (0.9.1 also includes the Phase A changes)

- All internal URLs use query-string routing (`?c=controller&a=action`) via
  the `u()` helper. PATH_INFO routing removed.
- `.htaccess` mod_rewrite block removed — no URL rewriting required.
- `nginx.conf.sample` updated with working subdirectory install configuration.
- `INSTALL.md` updated to reflect no mod_rewrite requirement.

### Migrations

- Run `db/migrations/026_admin_settings.sql` when upgrading from 0.9.0.
  Fresh installs use `db/schema.sql` which includes all tables.

---

## [0.9.0] — 2026-04-16

> **Pre-release.** Gates 3 and 4 of the test suite (integration and platform
> tests) are deferred to Phase 9 testing on a live server. Do not use in
> production until 1.0.0 is tagged.

### Evergreen Queue Engine

- Posts recycle automatically after sending — the queue refills from the
  content library without manual intervention
- Per-account recycle threshold: the queue engine triggers automatically
  when pending post depth falls below a configurable number
- Per-account lookahead window: the engine schedules posts up to a
  configurable number of days in advance
- Queue population is randomized on every run — posts do not repeat in
  the same order each cycle
- All queue operations are idempotent — safe to run the cron job twice
  without double-posting
- One-time posts: mark a post as non-recyclable and it is automatically
  deactivated after sending
- Share Now: publish any post within 5 minutes without disrupting the
  queue
- Full post history log: every send attempt (success or failure) is
  recorded with platform response detail

### Platform Support

- **Twitter / X** — text and image posts via API v2, OAuth 1.0a
- **Facebook Pages** — posts to connected pages via Graph API v19+
- **Instagram Business** — posts to business accounts via Graph API v19+,
  connected through a Facebook app
- Tokens for Facebook and Instagram are automatically refreshed before
  the 60-day expiry — no manual reconnection required under normal operation
- Unlimited platform accounts per installation; unlimited accounts per
  platform type

### Content Library

- Central content library per account — posts are written once and reused
- Each post supports a body, optional image, optional attribution, and an
  internal note (never sent)
- Posts can be individually activated, deactivated, or marked non-recyclable
- Edits to post body cascade cleanly — stale queue entries are removed and
  the updated post re-enters the queue on the next population cycle

### CSV Import

- Bulk import posts from a CSV file — up to 5,000 rows per upload
- Supports body text and optional image filename per row
- Import into one or more accounts in a single operation
- BOM detection, flexible header mapping, character limit enforcement
- Missing image filenames produce a warning; the row is imported as
  text-only rather than rejected
- Full error report downloadable as a text file after import
- Sample CSV available for download from the import screen

### Duplicate Detection

- Duplicate posts are detected at import time and on manual entry —
  no duplicate content reaches the queue
- Normalized comparison strips URLs, punctuation, and extra whitespace
  before comparing — catches near-duplicates as well as exact matches
- Within-file duplicates are caught during a single import run
- Duplicate manager view: browse all duplicate groups per account and
  delete unwanted copies in one action

### Account and Schedule Management

- Two schedule modes per account: interval-based (every 15 min to every
  8 hours, with active-hours window) or time-specific slots
- Schedule timezone is configurable per account
- Posting can be paused and resumed per account without losing queue
  or content
- Default hashtags per account: tags are appended at send time in order,
  up to the platform character limit

### Team Management

- Admin users have full access to all accounts
- Team members are scoped to assigned accounts only
- New team members are invited by email — no open registration
- Secure invite tokens; passwords set via emailed one-time link

### Image Support

- Optional image per post, stored in the `images/` directory (local) or
  S3 (optional)
- Dynamic image generation: overlay post text on a base template image
  at send time, per account
- Images are resized to platform requirements before posting
- Storage driver is configurable: local filesystem (default) or AWS S3

### Queue Management

- Queue dashboard shows pending posts per account with scheduled times
- Manually reorder or remove individual queue entries
- Activity log shows recent cron runs, post outcomes, and queue
  population events (retained 48 hours)
