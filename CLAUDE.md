# CLAUDE.md — SocialTurn

## What This Project Is
SocialTurn is an open source, self-hosted PHP social media scheduling and
auto-publishing engine. It is single-tenant — one installation serves one
person or organization. Users supply their own API credentials for every
platform. There is no central server, no billing, no shared infrastructure.

The goal is a tool that runs autonomously — content is loaded once and the
system keeps posting indefinitely via an evergreen recycling queue, without
manual intervention.

**Never break the queue engine.** It is the core of the application. Every
change must preserve the ability for accounts to post on schedule autonomously.

Development workflow: See DEVELOPMENT_PROCESS.md in the repo root for the full build process, templates, and quality gates.

---

## Current Stack
- PHP 8.2+
- MySQL 8.0+ / MariaDB 11.4+
- Apache or nginx (all requests route through index.php via query-string routing — no mod_rewrite required)
- Composer for dependency management
- Bootstrap 5, vanilla JS — no frontend build tools

---

## Deployment

- Live environment: https://polisci101.com/socialturn/
- Server: PHP 8.3, nginx + PHP-FPM, MariaDB 11.4
- Branch: master
- Deployed: April 2026

---

## Platform Integrations
| Platform | API | Auth | Status |
|---|---|---|---|
| Twitter/X | API v2 | OAuth 1.0a | Active — posting only |
| Facebook | Graph API v19+ | Page Access Token | Active — pages only |
| Instagram | Graph API v19+ | Business Account via Facebook | Active |

### Dispatch Architecture
`cron_dispatchToPlatform()` in `controllers/cron.php` is the single dispatch
point for all platform posts. It reads `connected_platforms.platform`, builds
the platform-specific `$context` array, and calls the uniform `post()` interface
on the selected service.

Adding a new platform requires two changes only:
1. A new service class in `src/Services/` extending `AbstractMetaService`
   (for Meta platforms) or implementing `post()` / `verifyToken()` directly
2. A new `case` in `cron_dispatchToPlatform()` building the correct `$context`

### Credential Architecture — Two Layers

**Layer 1 — Developer App Credentials (per-connection, database)**
These identify the developer app to each platform. Entered once per platform
connection at connect time; stored as `app_key` and `app_secret` on
`connected_platforms`. Multiple connections can use separate developer apps.
- Twitter/X: Consumer Key (`app_key`) + Consumer Secret (`app_secret`) per connection
- Meta (Facebook + Instagram): App ID (`app_key`) + App Secret (`app_secret`) per connection

There are no global PHP constants for platform credentials. No platform credential
appears in config, admin_settings, or any constant.

**Layer 2 — Per-Account OAuth Tokens (database)**
These identify each individual social account to the platform.
Stored in connected_platforms — one row per connected account.
Users can connect unlimited accounts of any supported platform type.
- Twitter/X: access_token + token_secret per account
- Facebook: page_access_token per page
- Instagram: access_token per business account

Both layers live in connected_platforms.
Never store either layer in config, code, constants, or admin_settings.
Never hardcode any credentials anywhere in code.

### Token Handling Rules
- Never re-authenticate on every cron run
- Tokens are stored in connected_platforms and refreshed automatically before expiry
- Facebook/Instagram tokens expire in 60 days — auto-refresh is mandatory
- Twitter/X tokens are permanent unless revoked by the user
- Tokens must never appear in logs, error messages, views, or API responses
- Each user of SocialTurn supplies their own API credentials at connect time
  — there are no shared platform credentials

---

## Repository Structure
/
├── CLAUDE.md                  — AI working document
├── README.md                  — Public face of the project
├── INSTALL.md                 — Step by step setup guide
├── CHANGELOG.md               — Version history
├── LICENSE                    — MIT License
├── config.php                 — Live credentials (never committed)
├── config.sample.php          — Heavily commented credential template
├── composer.json              — Dependency management
├── .gitignore                 — Excludes config.php, /images, /vendor
├── .htaccess                  — Routing + security rules
├── index.php                  — Front controller
├── db/
│   ├── schema.sql             — Complete fresh install schema
│   └── migrations/            — Numbered upgrade scripts
├── src/                       — All new clean PHP (PSR-4 autoloaded)
│   └── Services/              — Platform API service classes
├── controllers/               — MVC controllers
├── views/                     — PHP templates
├── libraries/                 — Legacy third-party SDKs (being phased out)
└── assets/                    — Bootstrap 5, CSS, JS
---

## Architecture Overview

### Routing
index.php parses PATH_INFO → $controller / $action → loads
controllers/{controller}.php → calls $action() → renders
views/{controller}/{action}.php wrapped in header/footer partials.

### Authentication
authenticate() in libraries/shared.php gates all requests except:
users/login, users/validate, users/invite, users/register, cron/*

User types:
- type=1 — Admin (full access)
- type=100 — Team member (limited to assigned accounts via users_accounts)

### Database
PDO via global $dbh, initialized in libraries/shared.php.
Always use prepared statements. Never use raw string interpolation in queries.
Never use mysql_* functions under any circumstances.

### Templates
$template->set('key', $value) → available as $key in views via extract().
Flash notifications: $_SESSION['notification']['type'] and ['message'].

### URL Helper
u(string $controller, string $action, array $params): string
defined in libraries/shared.php. All internal links, redirects,
and form actions use u() — never raw BASE_URL string concatenation.

### Config and Boot
- socialturn.ini — five keys only (db_host, db_name, db_user,
  db_pass, base_url). Stored above the web root. Never committed.
- boot.php — one line: define('CONFIG_PATH', '/absolute/path/
  socialturn.ini'). Lives in web root. Never committed. Written
  by install wizard.
- index.php loads boot.php first, then parses socialturn.ini via
  CONFIG_PATH. If boot.php is missing, redirects to install wizard.

### Install Wizard
install.php in the web root. Runs on first visit when boot.php is
absent. Writes socialturn.ini and boot.php, runs schema.sql and
migration sql files, creates first admin user and company. Must be
deleted after install. index.php shows a warning banner to admin
users until install.php is removed.

The wizard is 3 steps: (1) Database & Site URL, (2) Admin Account,
(3) Email / Postmark (optional). It does NOT collect platform
credentials — those are entered per-connection after install via
Connect Twitter / Connect Facebook.

**Install sequence — all validation before any write:**
1. Validate fields (format, required)
2. Pre-flight writability: `dirname($iniPath)` and `ROOT` (web root)
   must both be writable — if either fails, show all errors and stop;
   nothing has been written anywhere at this point
3. Test DB connection (read-only)
4. Check for existing data (read-only)
5. Only if all pass: run schema.sql, migration 026, DB transaction
   (company + admin user + admin_settings), write socialturn.ini,
   write boot.php

This sequencing was established after a real incident: the DB
committed but boot.php failed due to directory permissions, leaving
a half-installed state that required manual cleanup. The pre-flight
check at step 2 prevents this class of failure.

### Admin Settings
admin_settings table stores all non-boot configuration: Postmark
credentials, recycle threshold, lookahead days, schedule min posts,
owner email, notification settings. Loaded at bootstrap via
load_admin_settings() in libraries/shared.php which defines each
value as a PHP constant. Editable via controllers/settings.php
(admin only).

Platform API credentials (Twitter/Meta) are NOT in admin_settings —
they are stored per-connection in connected_platforms as app_key/app_secret.

**Any new admin_settings key must also be added to the $keyMap
array in load_admin_settings() in libraries/shared.php.** Without
this, the constant is never defined in any context — web or cron —
regardless of whether the migration has run.

Settings screen (`views/settings/index.php`) has five cards, admin only: Database & Site URL, Email, Application, Team (→ `team/index`), Connections (→ `connect/index`).

### Cron Entry Point
cron.php in the web root. CLI only — exits with 403 if called via
HTTP. Bootstraps the app without session or template, calls post()
in controllers/cron.php directly. Blocked by .htaccess and nginx
deny rules. Crontab: */5 * * * * /usr/bin/php /path/to/cron.php
>> /var/log/socialturn-cron.log 2>&1

### Post Body Assembly
build_final_body(string $body, string $attributedTo,
?string $postTags, ?string $defaultTagsJson, string $platform): string
Defined in libraries/shared.php. Single source of truth for
assembling final_body. Format: [body] - [attribution] #hashtags.
$postTags are per-post hashtags (posts.post_tags); $defaultTagsJson
are account-level default tags (accounts.default_tags). Both are
merged and deduplicated inside build_final_body() before being passed
to TagAppenderService, which appends them in order up to the platform
character limit. Called by QueuePopulationService::populate(), content/store(),
and content/update(). Never called from image template logic —
images receive raw body only.

### Pagination
pagination_calc(int $total): array defined in libraries/shared.php.
Returns [$page, $perPage, $offset, $totalPages]. Reusable partial
at views/partials/pagination.php — three-column layout (count left,
nav center, per-page right). Default 50 per page; options 25/50/100.
Used on: queue/index, queue/view, queue/history, queue/errors,
content/index.

---

## The Queue Engine — Understand Before Touching

This is the most important system in the application. Read this entire
section before making any changes to scheduling, posting, or cron logic.

### Content Library (posts table)
Master record for all postable content. A post is text plus an optional image.
- is_recyclable = TRUE (default) — post re-enters queue after being sent
- is_recyclable = FALSE — post is sent once, then deactivated automatically
- is_active = FALSE — post is excluded from all queue population

### Schedule Definition (account_schedules table)
Each connected platform has a posting interval:
- Supported intervals: every_hour, every_30min, every_2hr, every_4hr, custom
- Custom interval is stored as integer minutes
- Each schedule includes active_hours_start, active_hours_end, and timezone

### Queue Population Engine
When triggered, looks at a connected platform's schedule and fills
scheduled_posts with future time slots up to recycle_lookahead_days out.
- Pulls from posts WHERE is_recyclable = TRUE AND is_active = TRUE
- Randomizes order on every population run
- Never duplicates a post already in pending status in the queue
- Runs in cron only — never triggered synchronously from a web request
- Generated images are written back to post_images (image_source='generated') after
  first generation. Subsequent population cycles use the stored row directly — no
  regeneration occurs unless the image is invalidated by an account settings change.
- When dynamic image settings change on an account (base image or enabled toggle),
  all post_images rows with image_source='generated' are invalidated — physical files
  deleted via StorageService, rows deleted from post_images. Regeneration occurs
  naturally on the next population cycle using the new settings. Uploaded images are
  never touched by this process.

Population is gated by scheduling_enabled in account_settings. RecycleService
checks this flag before calling populate(). populate() also checks it
internally as a guard for any call path that bypasses RecycleService.
When scheduling_enabled = 0, population is skipped and the existing queue
is left untouched.

RecycleService::countPendingPosts() scopes the pending count to both connected_platform_id AND
account_id via a JOIN through posts. Never scope to connected_platform_id alone — accounts sharing
a platform would pool their counts and cause queue population to never trigger for lower-volume accounts.

### Recycle Threshold (account_settings table)
Each account has its own recycle_threshold (integer). When scheduled_posts
in pending status drops below this number, the queue population engine runs
automatically and refills from the content library.
- Threshold is configurable per account
- Default threshold is set in config.php as RECYCLE_THRESHOLD_DEFAULT

### Cron Job (controllers/cron.php)
Runs every 5 minutes. For each connected platform:
1. Check for posts due (scheduled_time <= NOW(), status = pending)
2. Retrieve stored token from connected_platforms
3. Post to platform API via the appropriate Service class
4. Mark scheduled_post status as posted, write to post_history
5. If post is_recyclable = FALSE, set posts.is_active = FALSE
6. Check queue depth against recycle_threshold
7. If below threshold, trigger queue population engine

The cron job does not authenticate. It uses stored tokens only.
All cron operations must be idempotent — safe to run twice without side effects.

Account dispatch is gated by cron_fetchActiveAccounts() which only returns
accounts WHERE is_active = 1 AND is_posting = 1 AND cp.is_active = 1.
Accounts with is_posting = 0 are excluded entirely — no posts dispatch
regardless of queue state.

### Post Edit Cascade Rule
When a post body or attributed_to is edited, ALL pending rows in scheduled_posts
for that post_id must be deleted before saving the update. Posted/failed/skipped
history rows are never touched. The post re-enters the queue naturally on the
next population cycle. This handles the case where a recycled post has been
re-queued with stale final_body content.

### Share Now
Creates a scheduled_posts row with scheduled_time = NOW() and status = pending.
The next cron run (within 5 minutes) picks it up and posts it. No special code
path needed. UI must display: "Post will publish within 5 minutes."

---

## Database Schema

### Tables
| Table | Purpose |
|---|---|
| companies | Top-level tenant — all users and accounts belong to one |
| users | Auth; type=1 admin, type=100 team member |
| accounts | A brand or identity (e.g. "Acme Social") |
| connected_platforms | One row per platform per account; holds tokens |
| account_schedules | Posting interval definition per connected platform |
| account_settings | Per-account config: recycle_threshold, lookahead_days |
| posts | Content library — master record for all postable content |
| post_images | One row per image per post; up to 4 images per post ordered by sort_order |
| scheduled_posts | Queue — a scheduled instance of a post at a specific datetime |
| post_history | Immutable log of every successfully sent post |
| admin_settings | Key/value store for all non-boot application configuration |
| oauth_states | Transient OAuth handshake state — one row per in-flight connect flow; deleted on use |

### connected_platforms columns
- id, company_id
- platform (twitter|facebook|instagram)
- platform_account_id (page ID, Twitter user ID, Instagram account ID)
- platform_name, platform_username (display identifiers — for UI listing)
- access_token, token_secret
- token_expires_at (NULL = never expires)
- app_key, app_secret (developer app credentials — entered at connect time; NULL on pre-034 rows until reconnected)
- is_active
- created_at, updated_at

### scheduled_posts status values
pending → posted | failed | skipped

### scheduled_posts source values
- source ENUM('queue','share_now','scheduled') NOT NULL DEFAULT 'queue'
- queue: auto-populated by the queue population engine
- share_now: immediate send created via Share Now or Send Now
- scheduled: user-chosen future datetime via Future Schedule
- flush() and schedule-change cascade deletes filter to source='queue' only;
  share_now and scheduled rows are never auto-deleted by these operations
- Post-edit cascade (content body change) deletes all pending sources —
  stale final_body affects all row types equally

### scheduled_posts image column
- final_image_filenames TEXT NULL — JSON array of processed image filenames ready for
  dispatch; NULL = text-only post
- Never reference final_image_filename (singular) — that column no longer exists (dropped in migration 032). Same applies to post_history: image_filenames (plural, JSON array) replaced image_filename in migration 032.

### accounts overlay columns
- overlay_font_color VARCHAR(7) NULL — hex color for dynamic image overlay text; NULL = ImageService default; defaults to #000000 on save if invalid
- overlay_font_size TINYINT UNSIGNED NULL — font size 30–70; NULL = ImageService default; defaults to 48 on save if out of range

### post_history image column
- image_filenames TEXT NULL — JSON array of image filenames at time of posting;
  NULL = text-only post

### Database Migrations
Every schema change ships with a numbered migration file in db/migrations/.
Format: 001_description.sql, 002_description.sql
Never make a breaking schema change without a migration file.
CHANGELOG.md must document which migrations to run for each version upgrade.
- Migration 025: scheduling_enabled added to account_settings
- Migration 026: admin_settings table created with default rows; INSERT IGNORE seed
  list corrected in v0.9.8 to omit the four deprecated credential rows (see migration 036)
- Migration 027: post_tags VARCHAR(255) NULL added to posts
- Migration 028: four notify_* keys added to admin_settings (notify_post_failure,
  notify_recap_frequency, notify_recipient_email, notify_recap_last_sent)
- Migration 029: source ENUM('queue','share_now','scheduled') added to scheduled_posts;
  flush() and schedule-change cascade delete filter to source='queue' only
- Migration 030: image_source ENUM('uploaded','generated','url_fetched') NULL added to posts (column subsequently dropped in migration 031)
- Migration 031: post_images table created; image_filename and image_source dropped from posts;
  overlay_font_color and overlay_font_size added to accounts
- Migration 032: final_image_filename dropped from scheduled_posts, replaced by
  final_image_filenames TEXT NULL (JSON array); image_filename dropped from post_history,
  replaced by image_filenames TEXT NULL (JSON array)
- Migration 033: oauth_states refactored for active use — account_id column, FK, and
  index dropped (no accounts row exists during connect flow); app_key VARCHAR(255) NULL
  and app_secret VARCHAR(255) NULL added (activated by GAP 1 in migration 034)
- Migration 034: app_key VARCHAR(255) NULL and app_secret VARCHAR(255) NULL added to
  connected_platforms; admin_settings seed rows for twitter_apikey, twitter_apisecret,
  meta_app_id, meta_app_secret removed; credentials now entered per-connection at
  connect time and stored directly on the connected_platforms row
- Migration 035: connected_platform_id INT UNSIGNED NULL added to oauth_states; FK to
  connected_platforms(id) ON DELETE SET NULL; enables in-place reconnect — callbacks
  branch on its presence to UPDATE the existing row vs INSERT a new row.
  schema.sql corrected in v0.9.10 to include this column (was missing since v0.9.5).
- Migration 036: twitter_apikey, twitter_apisecret, meta_app_id, meta_app_secret rows
  deleted from admin_settings for existing installs; these rows were seeded by migration
  026 but unused since v0.9.5 (GAP 1); migration 026 INSERT IGNORE list corrected
  simultaneously to prevent re-insertion on fresh installs

### post_images columns
- id — auto-increment primary key
- post_id — FK to posts.id (no cascade)
- sort_order TINYINT — display/posting sequence; UNIQUE with post_id; max 4 rows per post enforced at application layer
- image_filename — bare filename (uploaded) or storage-relative path (generated)
- image_source ENUM('uploaded','generated','url_fetched')
- created_at — auto-set timestamp

### oauth_states table
Holds transient OAuth handshake state for in-flight platform connect flows.
One row per flow; deleted immediately on first use (consumed-once pattern, prevents replay).
Rows older than 15 minutes are treated as expired; cron purges them automatically.

**Columns:**
- state_key CHAR(64) UNIQUE — `bin2hex(random_bytes(32))`; passed as OAuth 2.0 `state`
  parameter for Facebook; used as synthetic unique key for Twitter flows (Twitter does
  not support a `state` parameter — callbacks are looked up by `request_token` instead)
- platform ENUM('twitter','facebook','instagram')
- user_id FK → users.id — identifies who initiated the flow; CASCADE on user delete
- request_token VARCHAR(512) NULL — Twitter OAuth 1.0a only; echoed back by Twitter as
  `oauth_token` in the callback URL; used as the lookup key in twitterCallback()
- request_token_secret VARCHAR(512) NULL — Twitter OAuth 1.0a only
- app_key VARCHAR(255) NULL — developer app key transiting during the OAuth handshake;
  written on INSERT (from POST body), read back in the callback, then written to
  connected_platforms on success; deleted with the row (consumed-once)
- app_secret VARCHAR(255) NULL — same lifecycle as app_key
- connected_platform_id INT UNSIGNED NULL — FK → connected_platforms.id; ON DELETE SET NULL;
  NULL = fresh connect flow, set = reconnect of that existing row. Consumed-once pattern
  unchanged — deleted with the row on callback. Added in migration 035.
- created_at DATETIME — set on INSERT; used for expiry check and cron purge

**SESSION vs oauth_states boundary (important):**
- OAuth handshake CSRF state and request token secrets → oauth_states only (not SESSION)
- Facebook post-handshake page-selection data (`facebook_pages`, `facebook_instagram`,
  `expires`) → SESSION only (this is not a handshake concern; the oauth_states row has
  already been deleted by the time page selection begins)
- Flash notifications → SESSION only (`$_SESSION['notification']`)
- Never put handshake secrets back into SESSION — that re-introduces the collision bug

**Reconnect flow:** A "Reconnect" action on the Connections screen re-runs the OAuth
handshake for an existing `connected_platforms` row, updating tokens and optionally
credentials in place. The row id and all workspace/history references are preserved.
`connected_platform_id` on `oauth_states` carries the target row id through the
handshake; callbacks branch on its presence: set = UPDATE existing row, NULL = INSERT
new row. A successful reconnect overwrites all token and credential fields with whatever
the new handshake produced — no rollback, no prior-identity verification. The user's
deliberate action is trusted outright, including if the reconnect produces a different
platform account than the original.

**Masked credential display:** `app_key` is shown in full (it is an identifier, not a
secret); `app_secret` is shown as bullets + last 4 characters only (e.g. `••••a8f2`).
The full secret is never rendered in any view under any circumstances. This extends the
existing rule that tokens/secrets never appear in logs, views, or API responses.

---

## Security Rules — Never Violate These

1. Passwords must use password_hash() with PASSWORD_BCRYPT. Never SHA-256.
2. Invite tokens must use bin2hex(random_bytes(32)). Never SHA1.
3. config.php is in .gitignore. It must never be committed.
4. config.sample.php contains only placeholder values — keep it current.
5. All user input uses prepared statements or sanitize(). Never raw.
6. Tokens stored in DB must never appear in logs, responses, or views.
7. config.php must not be web-accessible — .htaccess must block direct access.
8. The images/ directory must not execute PHP — both .htaccess and nginx.conf.sample
   enforce this. vendor/, src/, libraries/, and db/ must block direct HTTP access —
   both config files enforce this.
9. Never ship with debug mode or verbose error output enabled by default.
10. Default configuration must be secure — never require users to harden it.
11. Token encryption at rest is deferred to v2.0. Tokens in connected_platforms
    are stored as plaintext in v1.0. Documented in README as a known limitation —
    production installs must use HTTPS and restrict database access.

---

## Coding Conventions

- PHP 8.2+ syntax and type declarations throughout
- Composer autoloading for all new code (PSR-4, namespace SocialTurn\)
- New library integrations go in src/Services/ — not libraries/ (legacy only)
- One responsibility per controller function
- All platform API calls go through a dedicated class in src/Services/
- Failures are logged to post_history with error detail — never silently swallowed
- All cron operations must be idempotent

### Storage Architecture
All image file operations go through `StorageService` — never call `fopen`,
`file_get_contents`, `copy`, `unlink`, or any S3 SDK method directly in
controllers or other services.

The local driver is the default and requires no additional packages.
The S3 driver requires `aws/aws-sdk-php` installed separately —
it is intentionally excluded from `composer.json` so local installs
carry no AWS dependency. A RuntimeException is thrown at StorageService
instantiation if S3 is selected but the SDK is absent.

Driver-specific behaviour of `retrieve()`:
- Local driver returns an absolute filesystem path — NOT a public URL.
  Meta API image posts require a public URL — use resolveImageUrl($filename)
  inherited from AbstractMetaService, which handles local vs S3 automatically.
- S3 driver returns a public HTTPS URL — suitable for Meta Graph API directly.
- TwitterService must always use getReadStream() for media uploads, never retrieve().
- To store raw bytes (e.g. from a curl fetch) without creating a temp file in the
  caller, use storeFromBytes(string $bytes, string $filename): bool — temp file
  creation and cleanup are handled internally.

ImageService::generateFromTemplate() renders text using imagettftext() with Poppins SemiBold 600
(assets/fonts/Poppins-SemiBold.ttf). Requires FreeType support in PHP GD (standard on Ubuntu/Debian
with php-gd). overlay_font_color (hex) and overlay_font_size (int) flow from the accounts table
through QueuePopulationService::fetchAccount() into generateFromTemplate(). Both must be included
in fetchAccount()'s SELECT — omitting either causes silently incorrect rendering (defaults applied).

The $imageSettingsChanged invalidation block in controllers/accounts.php compares pre-save vs posted
values for: dynamic_images_enabled, base_image_filename, overlay_font_color, overlay_font_size.
Any new image-affecting setting added to accounts must be added to both the pre-save SELECT and the
$imageSettingsChanged condition or invalidation will silently fail.

### Tooltip Pattern
Form field helper text that is descriptive and static (not dynamic
or functional) is presented as a tooltip icon rather than visible
form-text below the field. This keeps forms compact for repeat
users while preserving discoverability.

Standard markup — place immediately after the label text, before
any (optional) span:

<span data-bs-toggle="tooltip"
      data-bs-title="Your help text here."
      class="text-muted ms-1" style="cursor:default">&#63;</span>

Rules:
- Use for: static descriptive helper text explaining a field's
  purpose, format, or behavior
- Never use for: dynamic content (character counters, live data
  displays), error/validation feedback, or any content that must
  be visible without interaction
- Bootstrap 5 tooltip init is global in views/footer.php — no
  per-page initialization needed
- Tooltip text should be the same string previously shown in the
  form-text div — do not abbreviate or rewrite it

### New Service Class Pattern
Each platform gets its own service class:
- src/Services/TwitterService.php
- src/Services/FacebookService.php
- src/Services/InstagramService.php

Each service class implements:
- post(array $post, string $token) — send a post
- refreshToken(int $platformId) — refresh stored token if needed
- verifyToken(string $token) — validate a stored token is still active

If a service class in src/Services/ depends on a legacy library
in libraries/, it must require_once the library file explicitly —
libraries/ is not PSR-4 autoloaded and classes there will not be
found at runtime without a direct require_once.

---

## Installation Experience Standards

Setup must be achievable in under 30 minutes by a developer comfortable
with PHP hosting. These standards are non-negotiable for every release:

- INSTALL.md must include step-by-step instructions for obtaining API
  credentials from each platform — never assume knowledge of their portals
- The images/ and vendor/ directories must be created automatically or
  clearly documented — a missing directory must never cause a silent failure
- schema.sql must produce a fully working install in one command
- Cron job setup must be documented with exact crontab syntax

---

## Open Source Release Standards

- License: MIT
- Versioning: semantic (MAJOR.MINOR.PATCH)
- Every release is tagged in GitHub
- CHANGELOG.md is updated with every release before tagging
- README.md must include: what it does, requirements, quick install,
  screenshot or demo, where to get API keys, cron setup, license
- A clean install test on a fresh environment must pass before any
  release is tagged — test as if you have never seen the project before

---

## Build Order — Follow This Sequence

Do not skip ahead. Each phase depends on the previous being stable.

### Phase 1 — Foundation ✓ COMPLETE
Composer setup, PSR-4 autoloading, bcrypt passwords, secure invite tokens,
config.php in .gitignore, PHP 8.2 compatibility, .htaccess security rules.

### Phase 2 — Database ✓ COMPLETE
schema.sql, migration files for legacy upgrade path, PDO initialization.

### Phase 3 — Queue Engine ✓ COMPLETE
Schedule definitions, queue population engine, is_recyclable toggle, cron controller
with token-based auth, post_history logging, idempotency checks.

### Phase 4 — Platform Integrations ✓ COMPLETE
TwitterService, FacebookService, InstagramService, AbstractMetaService, token storage
and auto-refresh for Facebook/Instagram, cron_dispatchToPlatform().

### Phase 5 — Image Creation ✓ COMPLETE
Image generation pipeline, dynamic text overlay on base template images,
per-platform resizing.

### Phase 6 — UI Modernization ✓ COMPLETE
OAuth connect flow per platform, page/account selection, account listing with status,
disconnect flow, Bootstrap 5, queue management views, recycle toggle, schedule
configuration, content calendar.

### Phase 7 — Content Import ✓ COMPLETE
CSV bulk import (5,000-row cap, BOM detection, header mapping, error report),
manual post entry UI.

### Phase 7b — Duplicate Detection ✓ COMPLETE
normalize_body() in libraries/shared.php; write-time and import-time duplicate
detection; duplicate manager view.

### Phase 8a — Unit Testing ✓ COMPLETE
PHPUnit integration tests against a real test database; platform API calls mocked.

### Phase 8b — Codebase Cleanup ✓ COMPLETE
Dead code removed, schema.sql consolidated.

### Phase 8c — Release Packaging ✓ COMPLETE
INSTALL.md, README.md, CHANGELOG.md complete. Tag 0.9.0 on dev branch.

### Phase 9 — Testing & Bug Fix — IN PROGRESS
Deploy to remote Linux server following INSTALL.md as a clean install test.
Run PHPUnit automated test suite. Manual testing of all features including
queue engine, OAuth flows, platform posting, auth, CSV import, and duplicate
detection. Bug tracking and fixes. Each fix gets a point release (0.9.1,
0.9.2, etc.). Both automated and manual testing must pass before Phase 10.
Architectural changes completed during Phase 9:
- Phase A: Query-string routing replaces PATH_INFO/rewrite routing
- Phase B: config.php replaced by socialturn.ini + boot.php +
  install wizard + admin settings screen
- Multi-image post library: post_images table (migration 031), up to 4 images per
  post, sort order management, per-image delete in content edit view, URL image
  fetch via curl
- Multi-image cron dispatch (migration 032): all three platform services updated;
  Twitter multi-upload; Facebook two-phase unpublished + /feed; Instagram carousel
  flow for 2–4 images
- Overlay font color and size configurable per account for dynamic image generation
- GAP 1: Per-connection developer app credentials — platform credentials moved from
  global admin_settings to per-row app_key/app_secret on connected_platforms (migration 034);
  connect flows now include credential entry forms; service classes accept credentials
  via constructor; Settings → Platform Credentials screen removed
- Account→Workspace UI rename — all UI-rendered text; codebase variable/table names
  unchanged (commits 78db0de, 6ab10a5)
- Settings → Connections screen with Reconnect action per connection
- In-place OAuth reconnect via migration 035 (connected_platform_id on oauth_states)
- Team moved into Settings; Connections and Team sub-screens given Settings breadcrumbs
- Migration 026 ghost credential rows cleaned up (migration 036): twitter_apikey,
  twitter_apisecret, meta_app_id, meta_app_secret removed from migration 026 INSERT
  IGNORE seed list; migration 036 deletes them from existing installs

### Phase 10 — 1.0 Release
Merge to master. Tag 1.0.0. Public release.

---

## Versioning Strategy

- 0.9.0 — first public tag, feature complete, pre-release
- 0.9.x — bug fix point releases during Phase 9
- 1.0.0 — after clean automated and manual test pass on remote server

---

## Testing Approach

- PHPUnit as dev-only Composer dependency, never ships to production
- Test cases written locally on Windows
- Full suite executed on remote Linux server via SSH using Claude Code
- Test database seeded with known data, wiped and reseeded before each run
- Platform API calls (Twitter, Facebook, Instagram) mocked in all tests
- Both automated (PHPUnit) and manual testing required before 1.0.0

---

## Phase 9 Bug Fix Log

### Fixed
- Post body assembly centralized — build_final_body() in libraries/shared.php is the
  single source of truth for final body construction (body + attribution + per-post tags
  + account default tags, deduplicated and platform-limited). Called by
  QueuePopulationService::populate(), content/store(), and content/update().
- Queue pending counts scoped incorrectly when accounts share connected platforms —
  join corrected to route through posts.account_id; counts now correctly reflect
  per-account pending state.
- Multi-image post library (migration 031): post_images table replaces
  posts.image_filename and posts.image_source; up to 4 images per post with sort_order;
  store(), update(), shareNow(), and QueuePopulationService updated to read/write
  post_images; URL image fetch via curl writes a post_images row with image_source='uploaded'.
- Multi-image cron dispatch (migration 032): scheduled_posts.final_image_filenames and
  post_history.image_filenames replace single VARCHAR columns with JSON TEXT arrays;
  QueuePopulationService processes all post_images rows per post; TwitterService passes
  multiple media IDs; FacebookService uses two-phase unpublished upload + /feed
  attached_media[]; InstagramService uses carousel container flow for 2–4 images.
- Integration test fixture defects: scheduling_enabled=1 added to seedBaseFixture()
  (DEFAULT 0 caused all QueuePopulationService tests to silently fail);
  PDO native integer return fixed in ContentStoreTest TC5 (PHP 8.1+);
  phantom tags_truncated assertion removed from QueuePopulationServiceTest TC14 and
  RecycleServiceTest TC5.
- Queue population never triggered for account 2 — RecycleService::countPendingPosts() scoped
  the pending count to connected_platform_id only; accounts sharing a connected platform pooled
  their counts, causing account 2's depth to always read as above threshold; fixed by joining
  through posts.account_id so the count is scoped to the specific account; same class of bug
  as the queue view display count fix applied in an earlier session
- accounts/update image settings invalidation incomplete — overlay_font_color and overlay_font_size
  changes did not trigger generated image invalidation; pre-save SELECT extended to fetch both columns;
  $imageSettingsChanged condition extended with two additional || clauses comparing pre-save vs posted
  values for both font settings
- TwitterService::uploadMedia() swallowed all exceptions with bare catch (Throwable) — no error
  detail reached post_history; catch block updated to capture $e->getMessage(); non-media_id_string
  API responses now include raw response body; post() updated to surface actual error in the
  stored failure message
- TwitterService::uploadMedia() passed new CURLFile($tmpFile) to $client->upload() — the
  abraham/twitteroauth library constructs CURLFile internally and calls is_readable() on each
  param value; passing a pre-built CURLFile object caused PHP 8 TypeError; fixed by passing
  $tmpFile string directly; unused CURLFile import removed
- Inline generated image flush logic extracted from accounts.php::update() into
  src/Services/GeneratedImageService — deleteForAccount(int $accountId): int.
  Single source of truth for deleting generated images and their post_images rows
  for an account. accounts.php::update() now calls the service.
- OAuth handshake state collision — concurrent OAuth flows in the same browser session
  overwrote each other's SESSION key (`twitter_request_secret` or `facebook_state`),
  causing the earlier flow to fail on callback. Fixed by moving handshake state to the
  oauth_states table (migration 033): twitter() and facebook() INSERT one row per flow;
  twitterCallback() looks up by request_token; facebookCallback() looks up by state_key;
  both delete the row immediately on use. SESSION no longer holds any OAuth handshake
  state. Facebook post-handshake page-selection SESSION usage is unchanged.
- Per-connection developer app credentials (GAP 1, migration 034): Twitter Consumer
  Key/Secret and Meta App ID/Secret moved from global admin_settings constants to per-row
  app_key/app_secret on connected_platforms. TwitterService and AbstractMetaService now
  accept credentials via constructor. cron_dispatchToPlatform() instantiates service
  classes with credentials from the connected_platforms row. Connect flows show a
  credential entry form before initiating OAuth; credentials transit through oauth_states
  during the handshake and are written to connected_platforms on success.
  AbstractMetaService::refreshToken() reads app_key/app_secret directly from the DB row.
  Settings → Platform Credentials screen removed entirely.
- Account→Workspace UI rename across all views, controllers, flash messages, and outbound
  emails (commits 78db0de, 6ab10a5)
- New Settings → Connections screen: connect/index() action and view listing all
  connected_platforms rows with status, workspace count, and Reconnect/Disconnect actions;
  Settings overview card; Settings → Connections breadcrumb
- Reconnect feature: "Reconnect" action on Connections screen re-runs the OAuth handshake
  and UPDATEs the existing connected_platforms row in place; row id, workspace references,
  and history preserved; migration 035 adds connected_platform_id to oauth_states; masked
  credential display (app_key in full, app_secret as ••••last4)
- Team moved into Settings: Team <li> removed from header nav; Team card added to Settings
  overview with live member count badge (bg-success / bg-secondary); Settings → Team
  breadcrumb added to views/team/index.php
- Connections and Team sub-screens given Settings → [Screen] breadcrumbs for nav consistency

### Open
(none)

---

## v0.9.5 — Connections, Workspace Rename, and Reconnect — COMPLETE

### Settled Terminology

**Connection** (UI term for the `connected_platforms` row)
A real, authenticated social media account. Carries both developer app credentials
(`app_key`, `app_secret`) and OAuth tokens (`access_token`, `token_secret`) in one
row. Set up once; rarely touched after it works. Managed via Settings → Connections.

**Workspace** (UI term for the `accounts` row)
A named scheduling structure attached to exactly one Connection — its own content
pool, posting interval, active hours, tags, and queue settings. Multiple Workspaces
can run under a single Connection. Day-to-day operational layer.

Codebase variable names, table names, and column names continue to use "account"
internally. Only UI-rendered text (view labels, flash messages, page headings) uses
"Workspace." No schema changes were needed for this rename.

### What Was Built

- **Account→Workspace UI rename** — all views, controllers, flash messages, and
  outbound emails updated; codebase internals unchanged (commits 78db0de, 6ab10a5)
- **Settings → Connections screen** (`connect/index()` action + view) — lists all
  `connected_platforms` rows with platform badge, status, workspace count, and
  Reconnect / Disconnect actions; accessible via Settings overview card and
  Settings → Connections breadcrumb
- **Reconnect feature** — "Reconnect" action re-runs the OAuth handshake for an
  existing `connected_platforms` row and UPDATEs it in place; row id and all
  workspace/history references preserved; migration 035
- **Team moved into Settings** — Team removed from top-level header nav; Team card
  added to Settings overview with live member count; Settings → Team breadcrumb
- **Settings breadcrumbs** — Connections and Team sub-screens show Settings → [Screen]
  for nav consistency

### Status

v0.9.5 scope complete. The only remaining gate before v1.0.0 is Phase 9 testing
completion — both automated (PHPUnit) and manual testing must pass. See Phase 9
in the Build Order above.

---

## Future Roadmap (v2.0)

Features explicitly deferred until v1.0 is stable and in production use.
Do not design or build any part of these in v1.0.

### S3-Compatible Storage Driver
Replace the current AWS SDK dependency with a lightweight S3-compatible
HTTP client that works with any S3-compatible object storage provider:
Cloudflare R2, Backblaze B2, DigitalOcean Spaces, MinIO, Wasabi, etc.
The StorageService abstraction is already in place — only the driver
implementation changes. This widens hosting options beyond AWS and removes
the heavy aws/aws-sdk-php dependency from installs that need cloud storage.

### REST API Layer
Authenticated REST API allowing remote services to interact with SocialTurn
programmatically. Minimum scope:
- Create posts in the content library
- Trigger Share Now for an existing post
- Check queue status (pending count, next scheduled time) per account
This is the foundation for AI integration and third-party tool connectivity.
Authentication: API key per user stored in the database, passed as a header.
Never expose OAuth tokens through the API.

### AI Content Generation
AI-generated post content pushed into the content library via the REST API.
The preferred integration path is: an external AI service (or a user's own
script) calls the SocialTurn REST API to create posts — no direct database
access required. This keeps AI tooling decoupled from the core application
and allows any model or provider to be used without changes to SocialTurn.
Do not build AI generation into the application itself.

### Reddit Posting
Support posting to Reddit via the Reddit API with full queue engine integration —
text posts and image posts, per-account scheduling, OAuth authentication.

### Link Shortener
Integrate TinyURL API to automatically shorten URLs in post bodies before sending.
Configurable per account; API key stored in admin_settings.

---

## What Not To Do

- Do not add new features before Phase 3 (queue engine) is stable
- Do not combine posts (content) with scheduled_posts (queue instances) —
  this separation is intentional and must be preserved
- Do not hardcode any platform credentials or tokens anywhere in code
- Do not trigger queue population from a web request — cron only
- Do not make schema changes without a corresponding migration file
- Do not release without updating CHANGELOG.md and running a clean install test
- Do not store per-account OAuth tokens in config.php — they belong
  in connected_platforms in the database
- Do not limit the number of connectable accounts per platform —
  the architecture supports unlimited accounts by design
- Do not rebuild the suggestions system — it has been intentionally
  removed. Content enters the system via manual entry or CSV import in v1.0.
- Do not add AI content generation to v1.0 — this is explicitly deferred
  to v2.0. The planned integration path is via the REST API layer (also v2.0):
  AI services push generated content through the API rather than accessing
  the database directly. See Future Roadmap (v2.0).


