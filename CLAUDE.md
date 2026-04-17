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
- MySQL 8.0+
- Apache with mod_rewrite (all requests route through index.php)
- Composer for dependency management
- Bootstrap 5, vanilla JS — no frontend build tools

---

## Deployment

- Live environment: https://polisci101.com/socialturn/
- Server: PHP 8.3, nginx + PHP-FPM, MySQL 8.0
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

**Layer 1 — Developer App Credentials (config.php)**
These identify your installation of SocialTurn to each platform.
One set per platform, shared across all connected accounts.
- Twitter/X: TWITTER_API_KEY, TWITTER_API_SECRET
- Meta (Facebook + Instagram): META_APP_ID, META_APP_SECRET

**Layer 2 — Per-Account OAuth Tokens (database)**
These identify each individual social account to the platform.
Stored in connected_platforms — one row per connected account.
Users can connect unlimited accounts of any supported platform type.
- Twitter/X: access_token + token_secret per account
- Facebook: page_access_token per page
- Instagram: access_token per business account

config.php contains ONLY Layer 1 credentials and app config.
Never store individual account tokens in config.php.
Never hardcode any credentials anywhere in code.

### Token Handling Rules
- Never re-authenticate on every cron run
- Tokens are stored in connected_platforms and refreshed automatically before expiry
- Facebook/Instagram tokens expire in 60 days — auto-refresh is mandatory
- Twitter/X tokens are permanent unless revoked by the user
- Tokens must never appear in logs, error messages, views, or API responses
- Each user of SocialTurn supplies their own API credentials via config.php
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
| scheduled_posts | Queue — a scheduled instance of a post at a specific datetime |
| post_history | Immutable log of every successfully sent post |

### connected_platforms columns
- id, account_id
- platform (twitter|facebook|instagram)
- platform_account_id (page ID, Twitter user ID, Instagram account ID)
- platform_account_name (display name — for UI listing)
- access_token, token_secret
- token_expires_at (NULL = never expires)
- is_active
- created_at, updated_at

### scheduled_posts status values
pending → posted | failed | skipped

### Database Migrations
Every schema change ships with a numbered migration file in db/migrations/.
Format: 001_description.sql, 002_description.sql
Never make a breaking schema change without a migration file.
CHANGELOG.md must document which migrations to run for each version upgrade.

---

## Security Rules — Never Violate These

1. Passwords must use password_hash() with PASSWORD_BCRYPT. Never SHA-256.
2. Invite tokens must use bin2hex(random_bytes(32)). Never SHA1.
3. config.php is in .gitignore. It must never be committed.
4. config.sample.php contains only placeholder values — keep it current.
5. All user input uses prepared statements or sanitize(). Never raw.
6. Tokens stored in DB must never appear in logs, responses, or views.
7. config.php must not be web-accessible — .htaccess must block direct access.
8. The images/ directory must not execute PHP — .htaccess must enforce this.
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
  FacebookService and InstagramService must use BASE_URL . 'images/' . $filename
  to construct public URLs for Meta Graph API image posts.
- S3 driver returns a public HTTPS URL — suitable for Meta Graph API directly.
- TwitterService must always use getReadStream() for media uploads, never retrieve().

### New Service Class Pattern
Each platform gets its own service class:
- src/Services/TwitterService.php
- src/Services/FacebookService.php
- src/Services/InstagramService.php

Each service class implements:
- post(array $post, string $token) — send a post
- refreshToken(int $platformId) — refresh stored token if needed
- verifyToken(string $token) — validate a stored token is still active

---

## Installation Experience Standards

Setup must be achievable in under 30 minutes by a developer comfortable
with PHP hosting. These standards are non-negotiable for every release:

- Every config value in config.sample.php must have a comment explaining
  what it is, where to get it, and what format it expects
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
- Composer setup and PSR-4 autoloading
- Fix password hashing (bcrypt)
- Fix invite tokens (random_bytes)
- Add config.php to .gitignore, create config.sample.php
- PHP 8.2 compatibility pass — fix all deprecations
- .htaccess security rules for config.php and images/

### Phase 2 — Database ✓ COMPLETE
- Design and create new schema.sql
- Write migration files for upgrade from original schema
- Update db() initialization for new table structure

### Phase 3 — Queue Engine ✓ COMPLETE
- Rebuild schedule definition system
- Rebuild queue population engine with recycle threshold per account
- Implement is_recyclable toggle on posts
- Rebuild cron controller with token-based auth (no live authentication)
- Implement post_history logging
- Implement idempotency checks

### Phase 4 — Platform Integrations ✓ COMPLETE
- src/Services/TwitterService.php — API v2, posting only
- src/Services/FacebookService.php — Graph API v19+, Page tokens
- src/Services/InstagramService.php — Graph API via Facebook app
- Token storage and auto-refresh for Facebook/Instagram
- AbstractMetaService base class for shared Meta infrastructure
- cron_dispatchToPlatform() wired in controllers/cron.php

### Phase 5 — Image Creation ✓ COMPLETE
- Image generation pipeline before posting
- Support for dynamic text overlay on images
- Image resizing per platform requirements

### Phase 6 — UI Modernization ✓ COMPLETE
- Account connection and management UI
  - OAuth connect flow per platform (Twitter, Facebook, Instagram)
  - Automatic token exchange (short-lived → long-lived) for Meta
  - Page/account selection UI after Facebook OAuth
  - Account listing with connection status (valid/expired/needs reconnect)
  - Disconnect flow (revoke + delete token)
  - Unlimited accounts per platform type
- Bootstrap 5 upgrade
- Queue management views
- Recycle toggle per post
- Schedule configuration per account
- Content calendar view
- Reconnect flow for expired tokens

### Phase 7 — Content Import ✓ COMPLETE
- CSV bulk import (post body + optional image filename)
  - importForm(), importSample(), importProcess(), importErrors() in controllers/content.php
  - views/content/import.php — upload form with per-account selection, result summary panel
  - views/content/import_sample.csv — downloadable sample with comment rows
  - BOM detection, header-column mapping, 5,000-row cap, character limit enforcement
  - Missing image filenames produce a warning; row is imported without image
  - Error report downloadable as text file after import
- Manual post entry UI — create() / store() / edit() / update() in controllers/content.php

### Phase 7b — Duplicate Detection ✓ COMPLETE
- normalize_body() added to libraries/shared.php
  - Algorithm: lowercase → strip URLs → strip punctuation → collapse whitespace → trim → truncate 280
  - Called in store(), update(), and importProcess() on every write
- Migration 023: body_normalized VARCHAR(280) NOT NULL DEFAULT '' on posts table
  - Non-unique index on (account_id, body_normalized)
  - No backfill — existing rows normalize lazily on first edit or re-import
- Import duplicate detection in importProcess()
  - Pre-transaction lookup: one query per selected account loads existing body_normalized values
  - $seenThisImport tracks within-file duplicates per account
  - Duplicate rows increment $skipped and add to $warnings[]; has_errors not set
- content_duplicates() action — scoped to accessible accounts
  - Correlated subquery finds body_normalized values with COUNT > 1 per account
  - Results grouped in PHP: $groups[$accountId]['posts'][$normalized][]
  - Route: content/content_duplicates (underscore passes through router unchanged; no PHP built-in collision)
- views/content/duplicates.php — grouped cards per account, per-post Delete action
- Find Duplicates button added to content library header action bar

### Phase 8a — Unit Testing ✓ COMPLETE
PHPUnit installed as a dev-only Composer dependency. Test cases written
locally covering all key components. Pure logic tests (normalize_body,
TagAppenderService, CSV parsing, input validation) run locally on Windows.
Full test suite executed on remote Linux server via SSH. Platform API calls
mocked — no real posts during testing. Tests run against a seeded test
database.

### Phase 8b — Codebase Cleanup ✓ COMPLETE
Remove all legacy files, functions, and components no longer in use.
Dead code audit across all controllers, views, and libraries. Collapse
all migrations into a single unified schema.sql for fresh installs.
Verify config.sample.php matches everything used in the codebase.

### Phase 8c — Release Packaging ✓ COMPLETE
INSTALL.md complete with step-by-step setup and API credential instructions.
README.md with screenshots. CHANGELOG.md current. config.sample.php
verified. Tag 0.9.0 on dev branch.

### Phase 9 — Testing & Bug Fix — IN PROGRESS
Deploy to remote Linux server following INSTALL.md as a clean install test.
Run PHPUnit automated test suite. Manual testing of all features including
queue engine, OAuth flows, platform posting, auth, CSV import, and duplicate
detection. Bug tracking and fixes. Each fix gets a point release (0.9.1,
0.9.2, etc.). Both automated and manual testing must pass before Phase 10.

### Phase 10 — 1.0 Release
Merge dev to main. Tag 1.0.0. Public release.

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
- `log()` naming collision in `accounts.php` — renamed to `accounts_log()` (PHP built-in conflict)
- Facebook OAuth initiated without credential check — pre-flight validation added matching Twitter pattern
- Alpine.js x-data double-quote conflict in `views/accounts/edit.php` — outer attribute delimiter changed to single quotes
- Account schedule save — slots deleted unconditionally on every save regardless of schedule_type — fixed to branch by schedule type
- Account schedule save — `active_hours_start` and `active_hours_end` defined NOT NULL prevented nulling for time_specific mode — migration 024 added to allow NULL
- Active hours boundary — extended to support 24 (end of day) so posts can run through 23:xx
- Cross-midnight validation added — server-side check rejects `active_hours_start >= active_hours_end` with clear error message
- `index.php` PATH_INFO fallback — added QUERY_STRING parsing for nginx subdirectory installs using if/rewrite pattern
- Postmark API endpoint — `http://` changed to `https://` in `libraries/postmark.class.php`
- `nginx.conf.sample` — updated with verified working subdirectory install config using if/rewrite pattern and exact match deny rules
- `INSTALL.md` — updated with subdirectory install instructions, file permissions step, Postmark sender verification, DKIM/SPF DNS guidance
- Twitter credential naming — `config.sample.php` and `INSTALL.md` updated to note Consumer Key/Consumer Secret labeling in Twitter developer portal
- Migration 024 — `active_hours_start` and `active_hours_end` changed to NULL DEFAULT NULL in `account_schedules`

### Open
- Password reset link shows "expired or already used" message after successful first-time password set — needs investigation
- Two Twitter accounts with separate developer apps cannot both be connected — architecture limitation, deferred to v0.9.5

---

## Future Roadmap (v0.9.5) — Connect Flow and Multi-Account Redesign

**Required before v1.0.0 tag. Do not begin implementation until base system
bugs are resolved and manually tested (Phase 9 complete).**

### Background

The current architecture stores platform app credentials (TWITTER_APIKEY,
TWITTER_APISECRET, META_APP_ID, META_APP_SECRET) in config.php as shared
constants. This limits each platform to one developer app per installation,
making it impossible to connect two Twitter accounts that use separate
developer apps. This redesign moves credentials to the database and rebuilds
the connect flow as a guided wizard.

### Schema Changes Required

- Add `app_key` and `app_secret` columns to `connected_platforms`
- These store the developer app credentials per connection
- config.php platform credential constants become optional fallback defaults
  or are removed entirely
- Migration required

### Connect Flow Redesign

Replace the current single-click connect buttons with a guided multi-step
wizard per platform.

**Step 1 — Platform selection and documentation**
- User selects which platform to connect
- Screen displays step-by-step instructions for obtaining developer app
  credentials for that platform
- Twitter/X: create project and app at developer.twitter.com, set Read and
  Write permissions, add callback URL, copy Consumer Key and Consumer Secret
- Facebook: create app at developers.facebook.com, add Facebook Login and
  Instagram Graph API products, copy App ID and App Secret
- Instagram: connected through Facebook app — no separate credentials needed

**Step 2 — Credential entry**
- Form fields for app credentials
- Pre-populated from config.php values if present as defaults
- User can enter account-specific credentials
- Credentials validated before proceeding to OAuth

**Step 3 — OAuth authorization**
- Redirect to platform OAuth with the entered credentials
- On callback, store both app credentials and OAuth tokens in
  connected_platforms

### Account and Sub-Account Model Redesign

- A connected platform becomes the parent record
- Under each connected platform, user can create multiple posting schedules
  (sub-accounts) each with their own content pool, schedule, and settings
- Replaces current model where accounts reference a connected_platform_id
- Enables Brand X Twitter to have both a Marketing and Promotional schedule
  under the same Twitter connection

### Service Class Changes

- TwitterService, FacebookService, InstagramService currently read app
  credentials from PHP constants
- Redesign to accept app_key and app_secret as constructor parameters or via
  the context array passed from cron
- cron_dispatchToPlatform() reads app credentials from connected_platforms
  row and passes to service class

### Impact

- Touches: connect flow, schema, service classes, cron dispatch, account
  management UI, config.php
- config.php platform credential constants removed or made optional
- Fully backward compatible migration path for existing installs

### Implementation Order

1. Schema migration — add app_key, app_secret to connected_platforms
2. Service class updates — accept credentials as parameters
3. Cron dispatch update — pass credentials from DB
4. Connect wizard UI — multi-step flow per platform
5. Account/sub-account model — parent/child restructure
6. config.php cleanup — remove or deprecate platform credential constants

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

---

## What Not To Do

- Do not modify legacy libraries/facebook/ or libraries/twitter/ —
  they will be deleted when Phase 4 is complete
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


