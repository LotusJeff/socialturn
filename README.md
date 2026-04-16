# SocialTurn

Self-hosted social media scheduling and auto-publishing engine.

## What It Does

SocialTurn runs an evergreen queue — load content once and it keeps posting
indefinitely. Posts recycle automatically after sending, so the queue never
runs dry without intervention. You control the posting schedule per platform,
and the system handles everything else via a cron job that runs every five
minutes.

Supports Twitter/X, Facebook Pages, and Instagram Business accounts. Connect
unlimited accounts per platform. No central server, no subscription, no shared
infrastructure — your installation, your credentials, your data.

---

## Screenshots

[Screenshot: Queue dashboard]

[Screenshot: Content library]

[Screenshot: Account setup]

[Screenshot: Post creation]

> Screenshots will be added after the v1.0.0 release.

---

## Requirements

- PHP 8.2+ (pdo, pdo_mysql, gd, mbstring, finfo, curl)
- MySQL 8.0+
- Apache with mod_rewrite, or nginx with PHP-FPM
- Composer
- Cron access
- HTTPS (required — tokens stored in database)
- Postmark account for transactional email (free tier sufficient)

See [INSTALL.md](INSTALL.md) for full details.

---

## Quick Install

```bash
git clone https://github.com/LotusJeff/socialturn.git
cd socialturn
composer install --no-dev
cp config.sample.php config.php   # fill in all values
mysql -u root -p mydb < db/schema.sql
# configure web server, set up cron, open site in browser
```

Full step-by-step instructions including web server configuration, cron
setup, and platform credential acquisition: [INSTALL.md](INSTALL.md)

---

## Supported Platforms

| Platform | API | Notes |
|---|---|---|
| Twitter / X | API v2, OAuth 1.0a | Text and image posts |
| Facebook Pages | Graph API v19+ | Page access tokens |
| Instagram Business | Graph API v19+ | Connected via Facebook app |

---

## Known Limitations

- Token encryption at rest is not implemented in v0.9.0. Use HTTPS and
  restrict database access. See INSTALL.md Security Notes.

---

## License

MIT — see [LICENSE](LICENSE)

---

## Contributing

Bug reports and feature requests: [GitHub Issues](https://github.com/LotusJeff/socialturn/issues)
