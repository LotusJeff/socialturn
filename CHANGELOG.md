# Changelog

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
