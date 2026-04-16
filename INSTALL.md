# SocialTurn — Installation Guide

## Requirements

> **Current release:** 0.9.0 pre-release. Feature complete but integration and platform tests are pending live server validation. Not recommended for production use until 1.0.0 is tagged.

- **PHP 8.2+** with extensions: `pdo`, `pdo_mysql`, `gd`, `mbstring`, `finfo`, `curl`
- **MySQL 8.0+**
- **Web server:** Apache with mod_rewrite, or nginx with PHP-FPM
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

### 3. Configure the application

```bash
cp config.sample.php config.php
```

Open `config.php` and fill in every value. All constants are documented inline.
Do not leave any placeholder values in place before proceeding.

### 4. Create the database

```sql
CREATE DATABASE socialturn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Load the schema

```bash
mysql -u your_db_user -p socialturn < db/schema.sql
```

This creates all tables. Run this once on a fresh database.
For upgrades from an existing install, run `db/migrations/` in numbered order instead.

### 6. Set file permissions

The web server user needs write access to the images/ directory
for image uploads to work.

```bash
# Set ownership to the web server user
sudo chown -R www-data:www-data /path/to/socialturn

# Set directory and file permissions
sudo chmod -R 755 /path/to/socialturn

# Allow the web server to write uploaded images
sudo chmod -R 775 /path/to/socialturn/images
```

If your web server runs as a different user, replace `www-data`
with the correct user. To check:

```bash
ps aux | grep -E 'apache|nginx|php-fpm' | grep -v grep
```

The user in the first column of the worker process row is your
web server user.

### 7. Configure your web server

**Apache**

Enable `mod_rewrite` and point the document root to your SocialTurn directory.
The included `.htaccess` handles all routing and security rules automatically.

```apache
DocumentRoot /var/www/socialturn
<Directory /var/www/socialturn>
    AllowOverride All
</Directory>
```

**nginx**

Point the document root to your SocialTurn directory and include
`nginx.conf.sample` inside your `server {}` block:

```nginx
server {
    listen 443 ssl;
    server_name social.example.com;
    root /var/www/socialturn;
    index index.php;

    # SSL certificate configuration here

    include /var/www/socialturn/nginx.conf.sample;

    location ~ \.php {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+?\.php)(/.*)?$;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

PATH_INFO support is required. The `fastcgi_split_path_info` and
`fastcgi_param PATH_INFO` lines above are mandatory — without them the
router cannot parse the URL.

### 8. Verify config.php is not web-accessible

Browse directly to `https://yoursite.com/config.php`. You should receive
a 403 Forbidden response. If you receive a blank page or PHP output,
your web server is not enforcing the `.htaccess` or nginx rules. Do not
proceed until this is confirmed.

### 9. Set up the cron job

Add this to your crontab (`crontab -e`):

```
*/5 * * * * /usr/bin/php /path/to/socialturn/index.php cron/run
```

Replace `/path/to/socialturn` with the absolute path to your install.
Replace `/usr/bin/php` with the output of `which php` if different.

The cron job runs every 5 minutes. It checks for pending posts, dispatches
them to their platforms, and refills the queue when it drops below the
recycle threshold. It must be running for SocialTurn to post autonomously.

### 10. Complete first-run setup

Open your site in a browser. SocialTurn detects that no users exist and
sends a setup email to the `OWNER_EMAIL` address you configured in `config.php`.

Check your inbox for the setup email and follow the link to set your password.

### 11. Connect your first platform account

Log in, navigate to **Accounts**, create an account, and connect it to a
platform. See [Platform Credentials](#platform-credentials) for what you
will need before starting an OAuth flow.

---

## Platform Credentials

### Twitter / X

**Config constants:** `TWITTER_APIKEY`, `TWITTER_APISECRET`

**Developer portal:** [developer.twitter.com](https://developer.twitter.com)

Create a project and app. Set app permissions to **Read and Write** (required
for posting). Copy the **API Key** and **API Key Secret** from the app's
Keys and Tokens page.

**OAuth callback URL** to register in your app settings:
```
https://yoursite.com/connect/twitterCallback
```

### Facebook Pages + Instagram Business

**Config constants:** `META_APP_ID`, `META_APP_SECRET`

**Developer portal:** [developers.facebook.com](https://developers.facebook.com)

Create an app. Add the **Facebook Login** and **Instagram Graph API** products.
Required permissions: `pages_manage_posts`, `pages_read_engagement`,
`instagram_basic`, `instagram_content_publish`.

Copy the **App ID** and **App Secret** from App Settings > Basic.

**OAuth callback URL** to register in your app settings:
```
https://yoursite.com/connect/facebookCallback
```

Instagram connects through the same Facebook app and OAuth flow — no
separate Instagram app is needed.

### Email (Postmark)

**Config constants:** `POSTMARKAPP_API_KEY`, `POSTMARKAPP_MAIL_FROM_ADDRESS`, `POSTMARKAPP_MAIL_FROM_NAME`

**Portal:** [postmarkapp.com](https://postmarkapp.com)

Sign up, create a **Server**, and copy the **Server API Token** from the
API Tokens tab. Verify a sender signature or domain that matches the
`POSTMARKAPP_MAIL_FROM_ADDRESS` you configure.

The free tier (100 emails/month) is sufficient for all system emails
(setup, invites, password resets) in a single-tenant install.

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

Set `STORAGE_DRIVER` to `'s3'` in `config.php` and fill in the `S3_*`
constants: `S3_BUCKET`, `S3_REGION`, `S3_KEY`, `S3_SECRET`.

---

## Security Notes

- `config.php` is blocked by `.htaccess` and `nginx.conf.sample`. Verify
  the 403 check in step 8 passes before going live.
- The `images/` directory blocks PHP execution via `.htaccess` and nginx
  rules. Uploaded files are never interpreted as PHP.
- HTTPS is mandatory for production installs. OAuth tokens stored in the
  database are at risk on plain HTTP.
- Token encryption at rest is not implemented in v0.9.0. Restrict database
  access to localhost or a trusted host. Do not expose MySQL directly to
  the internet.
