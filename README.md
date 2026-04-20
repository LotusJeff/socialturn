# SocialTurn - *We Are in Final Testing for a 1.0.0 release*

# 📣 Social Media Scheduling — Simple, Unlimited, Powerful

A social media scheduling and publishing platform built for **solo creators, freelancers, and small businesses** who need a dependable way to plan, schedule, and automate content — without limits on channels, users, or posts.

![Unlimited channels](https://img.shields.io/badge/Channels-Unlimited-brightgreen) ![Unlimited users](https://img.shields.io/badge/Users-Unlimited-blue) ![Unlimited posts](https://img.shields.io/badge/Scheduled%20Posts-Unlimited-orange)

---

## ✨ Features

### Unlimited Channels, Users & Scheduled Posts
No artificial caps. Connect as many social accounts as you need, invite your whole team, and queue up as much content as you want. Ideal for agencies, growing teams, and creators managing multiple brands.

### Automated Evergreen Content Reuse
Recycle your best-performing posts automatically. Set evergreen content to republish on a schedule so your top content keeps driving traffic without any manual effort.

### Twitter & Facebook Integration
Publish directly to Twitter (X) and Facebook from a single dashboard. Manage all your social channels in one place without switching between apps.

### Custom Posting Schedules
Define exactly when your content goes live with fully customizable posting schedules. Set specific days, times, and frequencies that align with your audience's activity.

### Multiple Schedules in One Account
Run separate posting schedules for different teams or workflows — marketing, sales, operations, and more — all within a single account. No need for separate logins or plans.

### Tags at Post & Account Level
Organize and filter content with tags applied at both the individual post and account level. Quickly find, sort, and report on content by campaign, team, or topic.

### Pause & Shuffle Queues
Need to stop publishing temporarily? Pause any queue with one click. Shuffle queue order to vary your content mix without deleting or rescheduling individual posts.

### Flexible Publishing — Post Now, Schedule, or Autoschedule
Publish immediately, pick an exact date and time, or let the autoscheduler slot posts into your next available window. Full control over how and when your content goes live.

### Bulk Uploads
Upload and schedule dozens of posts at once via bulk import. Perfect for campaigns, content calendars, and teams that plan content in batches.

### Email Notifications
Stay informed without logging in. Receive email alerts for publishing confirmations, failures, and account activity so nothing slips through the cracks.

---

## 📊 How It Compares to Buffer

| Feature | This Platform | Buffer |
|---|---|---|
| Channels | Unlimited | Limited by plan |
| Users | Unlimited | Limited by plan |
| Scheduled posts | Unlimited | Limited by plan |
| Evergreen content recycling | ✅ Yes | ❌ No |
| Multiple schedules per account | ✅ Yes | ❌ No |
| Bulk uploads | ✅ Yes | ❌ No |
| Tags (post & account level) | ✅ Yes | Limited |
| Pause & shuffle queues | ✅ Yes | Partial |

---

## Who It's For

- Solo creators managing multiple social platforms
- Small businesses wanting professional scheduling without per-seat pricing
- Marketing teams needing organized, multi-schedule workflows
- Agencies handling content for multiple clients or brands

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
