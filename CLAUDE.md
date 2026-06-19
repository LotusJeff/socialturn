# CLAUDE.md — SocialTurn

## Project
Self-hosted PHP social media scheduling engine. Single-tenant. Evergreen recycling queue posts indefinitely without manual intervention. MIT license.
Workflow: DEVELOPMENT_PROCESS.md.
Branch: master. Live: https://polisci101.com/socialturn/

**CONSTRAINT: Never break the queue engine.**

---

## Status
Phase 9 (Testing & Bug Fix) — IN PROGRESS. Current version: 0.9.19.
Phase 10: merge to master, tag 1.0.0, public release.
Gate: both PHPUnit automated suite and full manual testing must pass.

---

## Terminology
These two terms are distinct. Never conflate them.

**Connection** = `connected_platforms` row. One real authenticated social media account. Holds both developer app credentials (`app_key`, `app_secret`) and OAuth tokens. UI: Settings → Connections.

**Workspace** = `accounts` row. A scheduling structure under one Connection. Has its own content pool, interval, active hours, tags, queue settings. Multiple Workspaces per Connection allowed.

Internal code/tables/columns use `account`. UI text only uses "Workspace."

---

## Stack
- PHP 8.2+ (live: 8.3), MySQL 8.0+ / MariaDB 11.4+
- nginx + PHP-FPM (live); Apache supported
- Query-string routing (`?c=controller&a=action`) — no mod_rewrite
- Composer, PSR-4 autoload (namespace `SocialTurn\`)
- Bootstrap 5, Alpine.js 3.14.1 (vendored) — no build tools

---

## Repository
```
/
├── index.php          front controller
├── cron.php           cron entry point (web root, CLI only)
├── install.php        install wizard (delete after install)
├── .htaccess          routing + security
├── db/schema.sql      complete fresh-install schema
├── db/migrations/     numbered upgrade scripts (001_description.sql)
├── src/Services/      platform service classes (PSR-4)
├── controllers/       MVC controllers
├── views/             PHP templates
├── libraries/         legacy SDKs (phasing out — not PSR-4)
└── assets/            Bootstrap, CSS, JS, fonts
```

---

## Routing
`index.php` → `?c=controller&a=action` → `controllers/{c}.php` → `$action()` → `views/{c}/{a}.php` (wrapped in header/footer partials).

---

## Authentication
`authenticate()` in `libraries/shared.php` gates all requests.
Exceptions: `users/login`, `users/validate`, `users/invite`, `users/register`, `cron/*`
- `type=1` — Admin, full access
- `type=100` — Team member, scoped to assigned accounts via `users_accounts`

---

## Database
- PDO via global `$dbh`, initialized in `libraries/shared.php`
- Always prepared statements. Never raw interpolation. Never `mysql_*`.

---

## Templates
- `$template->set('key', $value)` → `$key` in views via `extract()`
- Flash: `$_SESSION['notification']['type']` and `['message']`

---

## URL Helper
`u(string $controller, string $action, array $params): string` — `libraries/shared.php`
All internal links, redirects, form actions use `u()`. Never raw BASE_URL concatenation.

---

## Config and Boot
- `socialturn.ini` — 5 keys: `db_host`, `db_name`, `db_user`, `db_pass`, `base_url`. Above web root. Never committed.
- `boot.php` — single line: `define('CONFIG_PATH', '/path/to/socialturn.ini')`. Web root. Never committed. Written by install wizard.
- `index.php` loads `boot.php` first. Missing `boot.php` → redirects to install wizard.

---

## Install Wizard
`install.php` — runs on first visit when `boot.php` absent. 3 steps: (1) DB + Site URL, (2) Admin Account, (3) Email/Postmark (optional). Does NOT collect platform credentials.

**Sequence — all validation before any write:**
1. Validate fields
2. Pre-flight: `dirname($iniPath)` AND `ROOT` both writable — fail here stops everything
3. Test DB connection (read-only)
4. Check existing data (read-only)
5. All pass → run schema.sql + migrations, DB transaction, write `socialturn.ini`, write `boot.php`

Delete `install.php` after install. Admin banner persists until removed.

---

## Admin Settings
`admin_settings` — key/value for all non-boot config (Postmark, recycle threshold, lookahead days, owner email, notifications). Loaded at bootstrap by `load_admin_settings()` → defines PHP constants.

**Rule: any new key must also be added to `$keyMap` in `load_admin_settings()` in `libraries/shared.php`.** Missing from `$keyMap` = constant never defined anywhere.

Platform credentials NOT in `admin_settings` — they live in `connected_platforms.app_key`/`app_secret`.

Settings screen (`views/settings/index.php`): 5 cards (admin only): Database & Site URL, Email, Application, Team → `team/index`, Connections → `connect/index`.

---

## Cron
- `cron.php` (web root) — CLI only; exits 403 on HTTP
- Calls `post()` in `controllers/cron.php`; no session, no template
- Blocked by `.htaccess` and nginx deny rules
- Schedule: `*/5 * * * * /usr/bin/php /path/to/cron.php >> /var/log/socialturn-cron.log 2>&1`

---

## Platform Integrations

| Platform  | API            | Auth          | Status               |
|-----------|----------------|---------------|----------------------|
| Twitter/X | v2             | OAuth 1.0a    | Active — posting only |
| Facebook  | Graph v19+     | Page token    | Active — pages only  |
| Instagram | Graph v19+     | via Facebook  | Active               |

**Dispatch:** `cron_dispatchToPlatform()` in `controllers/cron.php` — single dispatch point. Reads `connected_platforms.platform`, builds `$context`, calls `post()` on service.

**Adding a platform:**
1. New service class in `src/Services/` — extend `AbstractMetaService` (Meta) or implement `post()`/`verifyToken()` directly
2. New `case` in `cron_dispatchToPlatform()` building `$context`

---

## Credential Architecture

**Layer 1 — Developer App Credentials**
Per `connected_platforms` row. Entered at connect time.
- Twitter/X: Consumer Key = `app_key`, Consumer Secret = `app_secret`
- Meta: App ID = `app_key`, App Secret = `app_secret`

No global PHP constants. No platform credential in config, `admin_settings`, or constants.

**Layer 2 — OAuth Tokens**
Per `connected_platforms` row.
- Twitter/X: `access_token` + `token_secret`
- Facebook: `page_access_token`
- Instagram: `access_token`

**Rules:**
- Never store either layer in config, code, constants, or `admin_settings`
- Never hardcode credentials anywhere
- Tokens never appear in logs, error messages, views, or API responses
- Never re-authenticate on cron runs — stored tokens only
- Facebook/Instagram tokens expire 60 days — auto-refresh mandatory before dispatch
- Twitter/X tokens permanent unless revoked
- `app_key` displayed in full in UI; `app_secret` displayed as `••••last4` only

---

## Queue Engine

**CONSTRAINT: Read this entire section before touching scheduling, posting, or cron.**

### `posts` table (content library)
- `is_recyclable = TRUE` (default) — re-enters queue after send
- `is_recyclable = FALSE` — sent once, then `is_active` set FALSE automatically
- `is_active = FALSE` — excluded from all queue population

### `account_schedules` table
- Intervals: `every_hour`, `every_30min`, `every_2hr`, `every_4hr`, `custom` (integer minutes)
- Columns: `active_hours_start`, `active_hours_end`, `timezone`

### Queue Population
Fills `scheduled_posts` with slots up to `recycle_lookahead_days` out.
- Source: `posts` WHERE `is_recyclable = TRUE AND is_active = TRUE`
- Order: randomized on every run
- Never duplicates a pending row
- Cron only — never triggered from a web request
- Gated by `scheduling_enabled` in `account_settings` — checked by `RecycleService` before `populate()` and by `populate()` internally
- `scheduling_enabled = 0` → skip population, leave queue untouched

**`RecycleService::countPendingPosts()` must JOIN through `posts` to scope count to BOTH `connected_platform_id` AND `account_id`. Never scope to `connected_platform_id` alone.**

### Generated Image Caching
- Generated images written to `post_images` (`image_source='generated'`) on first generation; reused on subsequent cycles
- When `dynamic_images_enabled` or `base_image_filename` changes: all `post_images` rows with `image_source='generated'` deleted (files via StorageService, rows from table). Regenerated on next cycle.
- Uploaded images never touched by invalidation

### Recycle Threshold
`account_settings.recycle_threshold` — when pending `scheduled_posts` drops below this, population triggers. Default: `RECYCLE_THRESHOLD_DEFAULT` constant.

### Cron Dispatch Loop (per account)
1. Check due posts: `scheduled_time <= NOW()`, `status = pending`
2. Retrieve token from `connected_platforms`
3. Post via Service class
4. Mark row posted; write `post_history`
5. If `is_recyclable = FALSE` → set `posts.is_active = FALSE`
6. Check queue depth vs `recycle_threshold`
7. Below threshold → trigger population

- Uses stored tokens only
- All operations idempotent
- `cron_fetchActiveAccounts()` filters: `is_active = 1 AND is_posting = 1 AND cp.is_active = 1`

### Post Edit Cascade
On edit of `body` or `attributed_to`: DELETE all pending `scheduled_posts` rows for that `post_id` before saving. Never touch posted/failed/skipped rows. Post re-queues on next population cycle.

### Share Now
INSERT `scheduled_posts` with `scheduled_time = NOW()`, `status = pending`. Next cron run dispatches it. UI text: "Post will publish within 5 minutes."

---

## Post Body Assembly
`build_final_body(string $body, string $attributedTo, ?string $postTags, ?string $defaultTagsJson, string $platform): string`
Location: `libraries/shared.php`
Format: `[body] - [attribution] #hashtags`
Per-post tags (`posts.post_tags`) + account default tags (`accounts.default_tags`) → merged, deduplicated → passed to `TagAppenderService` → appended in order up to platform character limit.
Callers: `QueuePopulationService::populate()`, `content/store()`, `content/update()`
Never called from image template logic — images receive raw body only.

---

## Pagination
`pagination_calc(int $total): array` — `libraries/shared.php`
Returns: `[$page, $perPage, $offset, $totalPages]`
Partial: `views/partials/pagination.php` — count left, nav center, per-page right. Default 50; options 25/50/100.
Used on: `queue/index`, `queue/view`, `queue/history`, `queue/errors`, `content/index`, `content/content_duplicates`

---

## Database Schema

### Tables
| Table               | Purpose                                                        |
|---------------------|----------------------------------------------------------------|
| `companies`         | Top-level tenant                                               |
| `users`             | Auth; type=1 admin, type=100 team member                       |
| `accounts`          | Workspace — scheduling structure under one Connection          |
| `connected_platforms` | One Connection row; holds app credentials + OAuth tokens     |
| `account_schedules` | Posting interval config per connected platform                 |
| `account_settings`  | Per-account config: recycle_threshold, lookahead_days, etc.    |
| `posts`             | Content library                                                |
| `post_images`       | Up to 4 images per post, ordered by `sort_order`              |
| `scheduled_posts`   | Queue instance — post at a specific datetime                   |
| `post_history`      | Immutable send log                                             |
| `admin_settings`    | Non-boot app configuration (key/value)                         |
| `oauth_states`      | Transient OAuth handshake state; consumed-once; cron-purged    |

### `connected_platforms` columns
`id`, `company_id`, `platform` (twitter|facebook|instagram), `platform_account_id`, `platform_name`, `platform_username`, `access_token`, `token_secret`, `token_expires_at` (NULL = never), `app_key`, `app_secret` (NULL on pre-034 rows until reconnected), `is_active`, `created_at`, `updated_at`

### `scheduled_posts`
- `status`: `pending` → `posted` | `failed` | `skipped`
- `source ENUM('queue','share_now','scheduled') NOT NULL DEFAULT 'queue'`
  - `flush()` and schedule-change cascades filter to `source='queue'` only
  - Post-edit cascade deletes all pending sources
- `final_image_filenames TEXT NULL` — JSON array; NULL = text-only. **Never use singular `final_image_filename` — dropped migration 032.**

### `post_history`
- `image_filenames TEXT NULL` — JSON array. **Never use singular `image_filename` — dropped migration 032.**

### `accounts` overlay columns
- `overlay_font_color VARCHAR(7) NULL` — hex; NULL = ImageService default; invalid → saved as `#000000`
- `overlay_font_size TINYINT UNSIGNED NULL` — 30–70; NULL = ImageService default; out of range → saved as 48

### `post_images` columns
`id` (PK), `post_id` (FK → `posts.id`, no cascade), `sort_order TINYINT` (UNIQUE with `post_id`; max 4 enforced at app layer), `image_filename`, `image_source ENUM('uploaded','generated','url_fetched')`, `created_at`

### `oauth_states` columns
Consumed-once: deleted on first use. Expired after 15 min; cron purges.
- `state_key CHAR(64) UNIQUE` — `bin2hex(random_bytes(32))`; OAuth 2.0 `state` param for Facebook; lookup key for Twitter (Twitter callbacks use `request_token` instead)
- `platform ENUM('twitter','facebook','instagram')`
- `user_id` FK → `users.id` CASCADE
- `request_token VARCHAR(512) NULL` — Twitter only
- `request_token_secret VARCHAR(512) NULL` — Twitter only
- `app_key VARCHAR(255) NULL`, `app_secret VARCHAR(255) NULL` — transit credentials through handshake; written to `connected_platforms` on success
- `connected_platform_id INT UNSIGNED NULL` FK → `connected_platforms.id` ON DELETE SET NULL — NULL = fresh connect, non-NULL = reconnect target row (migration 035)
- `created_at DATETIME`

**SESSION vs `oauth_states` boundary:**
- Handshake CSRF state + request token secrets → `oauth_states` only. Never SESSION.
- Facebook post-handshake page selection (`facebook_pages`, `facebook_instagram`, `expires`) → SESSION only
- Flash notifications → SESSION only

**Reconnect:** Re-runs OAuth for existing `connected_platforms` row. Callback checks `connected_platform_id`: non-NULL → UPDATE existing row, NULL → INSERT new row. Overwrites all token and credential fields. Row id and all workspace/history references preserved.

### Migrations
Every schema change requires a numbered file in `db/migrations/` (format: `001_description.sql`). CHANGELOG.md documents which to run per version.

| # | Change |
|---|--------|
| 025 | `scheduling_enabled` → `account_settings` |
| 026 | `admin_settings` table + default rows |
| 027 | `post_tags VARCHAR(255) NULL` → `posts` |
| 028 | Four `notify_*` keys → `admin_settings` |
| 029 | `source ENUM` → `scheduled_posts` |
| 030 | `image_source` → `posts` (dropped in 031) |
| 031 | `post_images` table; drop `image_filename`/`image_source` from `posts`; overlay columns → `accounts` |
| 032 | `final_image_filenames` JSON replaces `final_image_filename` on `scheduled_posts`; `image_filenames` JSON replaces `image_filename` on `post_history` |
| 033 | `oauth_states`: drop `account_id` FK; add `app_key`/`app_secret` |
| 034 | `app_key`/`app_secret` → `connected_platforms`; global credential constants removed |
| 035 | `connected_platform_id` → `oauth_states` for reconnect |
| 036 | Delete ghost credential rows from `admin_settings` on existing installs |

---

## Security — Never Violate

1. Passwords: `password_hash()` / `PASSWORD_BCRYPT` only. Never SHA-256.
2. Invite tokens: `bin2hex(random_bytes(32))` only. Never SHA1.
3. `socialturn.ini`, `boot.php` — never committed, never web-accessible.
4. All user input: prepared statements or `sanitize()`. Never raw.
5. Tokens/secrets: never in logs, responses, or views.
6. `images/` — no PHP execution (`.htaccess` + `nginx.conf.sample`). `vendor/`, `src/`, `libraries/`, `db/` — no direct HTTP access (both configs).
7. Never ship debug mode or verbose errors enabled by default.
8. Token encryption at rest: deferred to v2.0. v1.0 stores plaintext — documented in README.

---

## Coding Conventions
- PHP 8.2+ syntax, explicit type declarations throughout
- New code: `src/Services/` (PSR-4). `libraries/` is legacy only — not autoloaded
- One responsibility per controller function
- Platform API calls: dedicated class in `src/Services/` only
- Failures: logged to `post_history` with error detail. Never silently swallowed.
- Cron: all operations idempotent

---

## Storage Architecture
All image file I/O through `StorageService`. Never call `fopen`, `file_get_contents`, `copy`, `unlink`, or S3 SDK methods directly in controllers or services.

`StorageService::retrieve()`:
- Local driver → absolute filesystem path (NOT a public URL). For Meta API: use `AbstractMetaService::resolveImageUrl($filename)`.
- S3 driver → public HTTPS URL (suitable for Meta Graph API directly).

`TwitterService`: always use `getReadStream()` for media uploads. Never `retrieve()`.

Raw bytes (e.g. curl fetch): `StorageService::storeFromBytes(string $bytes, string $filename): bool` — handles temp file internally.

`ImageService::generateFromTemplate()`: uses `imagettftext()` + Poppins SemiBold 600 (`assets/fonts/Poppins-SemiBold.ttf`). Requires FreeType in PHP GD. `overlay_font_color` and `overlay_font_size` must be in `QueuePopulationService::fetchAccount()` SELECT — omission causes silent rendering errors.

`$imageSettingsChanged` block in `controllers/accounts.php` compares pre-save vs posted: `dynamic_images_enabled`, `base_image_filename`, `overlay_font_color`, `overlay_font_size`. Any new image-affecting account setting must be added to both the pre-save SELECT and this condition.

---

## Service Class Pattern
Files: `src/Services/TwitterService.php`, `FacebookService.php`, `InstagramService.php`

Each implements:
- `post(array $post, string $token)` — send a post
- `refreshToken(int $platformId)` — refresh stored token
- `verifyToken(string $token)` — validate stored token

Service classes that depend on `libraries/` must `require_once` explicitly — `libraries/` is not PSR-4 autoloaded.

---

## Tooltip Pattern
Static helper text → tooltip icon, not visible form-text.

```html
<span data-bs-toggle="tooltip"
      data-bs-title="Help text."
      class="text-muted ms-1" style="cursor:default">&#63;</span>
```

Place immediately after label text. Use for static descriptions only. Never for dynamic content, validation feedback, or required-visible content. Bootstrap 5 tooltip init is global in `views/footer.php`.

---

## Testing
- PHPUnit: dev-only Composer dependency, never in production
- Written locally (Windows), run on remote Linux server via Claude Code
- Test DB seeded with known data, wiped and reseeded before each run
- Platform API calls mocked in all tests
- Both PHPUnit suite and manual testing required before 1.0.0

---

## Release Checklist
- CHANGELOG.md updated
- Clean install test on fresh environment passes
- PHPUnit suite passes
- Manual test of all features passes
- All migrations numbered and documented in CHANGELOG
- schema.sql current
- README.md current (what it does, requirements, quick install, screenshots, API key steps, cron setup, license)
- INSTALL.md current (platform credential steps included)
- GitHub tag created

---

## v2.0 Roadmap (deferred — do not build in v1.0)
- **S3-compatible storage driver** — lightweight client for R2/B2/Spaces/MinIO/Wasabi; StorageService abstraction already in place
- **REST API** — create posts, trigger Share Now, check queue status; API key auth per user; never expose OAuth tokens
- **AI content generation** — via REST API only; no direct DB access; not built into application
- **Reddit posting** — text + image via Reddit API, full queue integration
- **Link shortener** — TinyURL API, configurable per account

---

## Constraints
- `posts` and `scheduled_posts` are separate concerns — never combine
- No platform credentials or tokens hardcoded anywhere
- Queue population: cron only — never from a web request
- Schema changes: migration file required, always
- OAuth tokens: `connected_platforms` only — never config files
- Connectable accounts per platform: unlimited
- Suggestions system: removed — do not rebuild. Content enters via manual entry or CSV import.
- AI content generation: v2.0 only
