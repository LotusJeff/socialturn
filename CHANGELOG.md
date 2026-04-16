# Changelog

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
