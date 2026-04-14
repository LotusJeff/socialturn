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

---

## Current Stack
- PHP 8.2+
- MySQL 8.0+
- Apache with mod_rewrite (all requests route through index.php)
- Composer for dependency management
- Bootstrap 5, vanilla JS — no frontend build tools

---

## Platform Integrations
| Platform | API | Auth | Status |
|---|---|---|---|
| Twitter/X | API v2 | OAuth 1.0a | Active — posting only |
| Facebook | Graph API v19+ | Page Access Token | Active — pages only |
| Instagram | Graph API v19+ | Business Account via Facebook | Active |

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

### Phase 1 — Foundation
- Composer setup and PSR-4 autoloading
- Fix password hashing (bcrypt)
- Fix invite tokens (random_bytes)
- Add config.php to .gitignore, create config.sample.php
- PHP 8.2 compatibility pass — fix all deprecations
- .htaccess security rules for config.php and images/

### Phase 2 — Database
- Design and create new schema.sql
- Write migration files for upgrade from original schema
- Update db() initialization for new table structure

### Phase 3 — Queue Engine
- Rebuild schedule definition system
- Rebuild queue population engine with recycle threshold per account
- Implement is_recyclable toggle on posts
- Rebuild cron controller with token-based auth (no live authentication)
- Implement post_history logging
- Implement idempotency checks

### Phase 4 — Platform Integrations
- src/Services/TwitterService.php — API v2, posting only
- src/Services/FacebookService.php — Graph API v19+, Page tokens
- src/Services/InstagramService.php — Graph API via Facebook app
- Token storage and auto-refresh for Facebook/Instagram

### Phase 5 — Image Creation
- Image generation pipeline before posting
- Support for dynamic text overlay on images
- Image resizing per platform requirements

### Phase 6 — UI Modernization
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

### Phase 7 — Content Import
- CSV bulk import (post body + optional image filename)
- Manual post entry UI

### Phase 8 — Release Preparation
- Complete INSTALL.md
- Complete README.md with screenshots
- Clean install test on fresh environment
- Tag v1.0.0

### Phase 9 — Future Roadmap (v2.0)
The following features are intentionally deferred until v1.0 is stable
and in production use:
- AI content generation pipeline (OpenAI/Anthropic/etc.)
  Concept: user submits a prompt or topic, system calls AI API, generates
  post variations, user reviews and approves into content library.
  Do not design or build any part of this in v1.0.
  Revisit after v1.0 has been in active use.

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
  to v2.0. See Phase 9.

---

## Technical Debt

### Stale lock cleanup
Scheduled_posts rows where locked_at is older than 10 minutes should be reset
to NULL to allow retry. Add to post() after fetchActiveAccounts.
Deferred from Phase 3d.

