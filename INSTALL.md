# SocialTurn — Installation Guide

## Requirements

> **Current release:** 0.9.0 pre-release. Feature complete but integration and platform tests are pending live server validation. Not recommended for production use until 1.0.0 is tagged.

- **PHP 8.2+** with extensions: `pdo`, `pdo_mysql`, `gd`, `mbstring`, `finfo`, `curl`
- **MySQL 8.0+**
- **Web server:** Apache, or nginx with PHP-FPM (no URL rewriting required)
- **Composer** — [getcomposer.org](https://getcomposer.org)
- **Cron access** on your server
- **HTTPS** — OAuth tokens are stored in the database; plain HTTP installs are a security risk
- **Postmark account** for transactional email (free tier: 100 emails/month) — [postmarkapp.com](https://postmarkapp.com)
- **Platform developer accounts** — see [Platform Credentials](#platform-credentials)

---

## Installation Steps

### 1. Clone the repository

```bash
git clone https://github.com/LotusJeff/socialturn.git
cd socialturn
```

### 2. Install dependencies

```bash
composer install --no-dev
```

### 3. Create the database

You can create the database within your hosting panel or via phpmyadmin or via the mysql command below

```sql
CREATE DATABASE socialturn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The install wizard loads the schema automatically — you only need to create the empty database first.

### 4. Set file permissions

The web server user needs:
- **Write access to `images/`** for uploaded images
- **Write access to the socialturn root directory** so the install wizard can write `boot.php`
- **Write access to the directory where `socialturn.ini` will be stored** (ideally one level above the web root — the wizard auto-detects this path and lets you confirm it)

```bash
# Set ownership to the web server user
# If your webserver runs as a different user, replace `www-data` with the correct user.
sudo chown -R www-data:www-data /path/to/socialturn
```

### 5. Run the install wizard

Open your browser and navigate to:

```
https://yoursite.com/socialturn/install.php
```

The wizard walks you through four steps:

1. **Database & Site URL** — enter your MySQL credentials and the full public URL
   to your installation (with trailing slash). The wizard also shows the path where
   `socialturn.ini` will be written — by default one level above your web root
   (e.g. `/var/www/yoursite.com/socialturn.ini`). You can change this path. A
   yellow advisory is shown if the path resolves inside the web root; this is
   non-blocking but less secure.
2. **Admin Account** — choose your admin email address and password.
   This creates the first admin user directly — no email verification required.
3. **Email (optional)** — enter Postmark credentials for password resets and team invites.
   You can skip this step and configure it later in Settings → Email.
4. **Platform Credentials (optional)** — enter Twitter/X and Facebook/Instagram
   developer app keys. You can skip and configure later in Settings → Platform Credentials.

Click **Install SocialTurn** on the final step. The wizard:
- Tests the database connection
- Loads the schema (`db/schema.sql`)
- Creates your organization and admin account
- Writes `socialturn.ini` (credentials) at your chosen path
- Writes `boot.php` in the web root pointing to `socialturn.ini`

> **After the wizard completes, delete `install.php` immediately.**
> SocialTurn will display a security warning on every page until the file is removed.
> Run:
```
sudo rm /path/to/socialturn/install.php
```

### 6. Set up the cron job

Add this to your crontab (`crontab -e`):

```
*/5 * * * * /usr/bin/php /path/to/socialturn/index.php cron/run
```

Replace `/path/to/socialturn` with the absolute path to your install.
Replace `/usr/bin/php` with the output of `which php` if different.

The cron job runs every 5 minutes. It checks for pending posts, dispatches
them to their platforms, and refills the queue when it drops below the
recycle threshold. It must be running for SocialTurn to post autonomously.

### 7. Connect your first platform account

Log in, navigate to **Accounts**, create an account, and connect it to a platform.
See [Platform Credentials](#platform-credentials) for what you will need before
starting an OAuth flow.

---

## Platform Credentials

### Twitter / X

**Settings location:** Settings → Platform Credentials → Consumer Key / Consumer Secret

**Developer portal:** [developer.twitter.com](https://developer.twitter.com)

Create a project and app. Set app permissions to **Read and Write** (required
for posting). Copy the **Consumer Key** (API Key) and **Consumer Secret** (API Key Secret)
from the app's Keys and Tokens page. In the developer portal these are labelled
"API Key" and "API Key Secret" — Twitter uses both names interchangeably.

**OAuth callback URL** to register in your app settings:
```
https://yoursite.com/socialturn/index.php?c=connect&a=twitterCallback
```

### Facebook Pages + Instagram Business

**Settings location:** Settings → Platform Credentials → App ID / App Secret

**Developer portal:** [developers.facebook.com](https://developers.facebook.com)

Create an app. Add the **Facebook Login** and **Instagram Graph API** products.
Required permissions: `pages_manage_posts`, `pages_read_engagement`,
`instagram_basic`, `instagram_content_publish`.

Copy the **App ID** and **App Secret** from App Settings > Basic.

**OAuth callback URL** to register in your app settings:
```
https://yoursite.com/socialturn/index.php?c=connect&a=facebookCallback
```

Instagram connects through the same Facebook app and OAuth flow — no
separate Instagram app is needed.

### Email (Postmark)

**Settings location:** Settings → Email

**Portal:** [postmarkapp.com](https://postmarkapp.com)

Sign up, create a **Server**, and copy the **Server API Token** from the
API Tokens tab.

**Sender verification (required)**

The From address must be at your own domain — public email providers (Gmail,
Yahoo, Outlook, etc.) are not permitted as senders.

In the Postmark dashboard, create a Sender Signature for your from address
(e.g. `noreply@yourdomain.com`). Postmark will send a verification email —
click the link to confirm.

**DNS records for email deliverability (strongly recommended)**

Without these records system emails will likely land in spam or be rejected.

In your Postmark dashboard, go to your Sender Signature and copy the DKIM and
SPF DNS records provided. Add them to your domain's DNS at your DNS provider:

- **DKIM** — a TXT record that proves emails are sent by an authorized server.
  Postmark provides the name and value.
- **SPF** — a TXT record that authorizes Postmark to send on behalf of your
  domain. If an SPF record already exists for your domain, add Postmark's
  `include` value to it rather than creating a new record.

DNS changes can take up to 24-48 hours to propagate. Postmark's dashboard
shows verification status for both records.

The free tier (100 emails/month) is sufficient for all system emails
(invites, password resets) in a single-tenant install.

---

## Storage

### Local (default)

No additional configuration. Uploaded images are stored in the `images/`
directory. That directory must be writable by the web server user:

```bash
chmod 755 images/
# or, if needed:
chown www-data:www-data images/
```

### S3

Install the AWS SDK (not included by default):

```bash
composer require aws/aws-sdk-php
```

Set `STORAGE_DRIVER` to `'s3'` and configure S3 credentials via the Settings
interface (S3 support is planned for v2.0).

---

## Security Notes

- `socialturn.ini` is ideally stored **above the web root** (the install wizard
  auto-detects this path). If stored inside the web root, it is blocked by
  `.htaccess` and the nginx deny rules. Verify the 403 checks in step 7 pass
  before going live.
- `socialturn.ini` is written with permissions `0644` by the install wizard.
- `boot.php` is blocked by `.htaccess` and the nginx deny rules. It contains
  no credentials but does reveal the filesystem path to `socialturn.ini`.
- The `images/` directory blocks PHP execution via `.htaccess` and nginx rules.
  Uploaded files are never interpreted as PHP.
- HTTPS is mandatory for production installs. OAuth tokens stored in the
  database are at risk on plain HTTP.
- Token encryption at rest is not implemented in v0.9.0. Restrict database
  access to localhost or a trusted host. Do not expose MySQL directly to
  the internet.
- Delete `install.php` immediately after installation. SocialTurn displays
  a persistent security warning until the file is removed.
