# GPS Product Hub — Staging Deployment Guide

## Purpose

This document describes how to deploy the GPS Product Hub staging application on the current DirectAdmin/shared-hosting environment:

- Staging domain: `https://gpsystem.thecamels.pl`
- Expected admin URL: `https://gpsystem.thecamels.pl/admin`
- Laravel app root: `/home/gpsystem/domains/gpsystem.thecamels.pl/app`
- Public root: `/home/gpsystem/domains/gpsystem.thecamels.pl/public_html`
- Browser deploy file: `/home/gpsystem/domains/gpsystem.thecamels.pl/public_html/deploy.php`

This is a staging/test deployment guide only. The application is still the MVP foundation: Laravel, Filament admin, authentication, roles, placeholder navigation, and disabled-by-default safety flags. It does not include product intake workflows, product catalog business logic, Woo sync, marketplace publishing, or external API writes.

Production should later move to VPS/SSH, GitHub Actions, Deployer, or another proper CI/CD approach. The browser helper exists to support the current shared-hosting workflow.

## Current shared-hosting constraint

The staging host has `proc_open` disabled. Browser deployment cannot depend on shell commands, including:

```bash
composer install
php artisan migrate
php artisan filament:assets
```

The repository therefore provides `deploy.example.php`, a one-file browser deployment helper that can be copied to `public_html/deploy.php`. It does not use shell execution, Composer, or `proc_open`.

See the detailed helper documentation in [`docs/gps-product-hub-browser-deploy.md`](gps-product-hub-browser-deploy.md).

## One-file browser deployment flow

For normal code-only staging deployments:

1. Push or merge the desired code to GitHub `main`.
2. Open:

   ```text
   https://gpsystem.thecamels.pl/deploy.php?token=MY_FIXED_STAGING_TOKEN
   ```

3. Watch the browser-readable log.
4. Verify:
   - `https://gpsystem.thecamels.pl/`
   - `https://gpsystem.thecamels.pl/admin`

The helper downloads:

```text
https://github.com/talarekr/gpsystem/archive/refs/heads/main.zip
```

Then it extracts the ZIP, syncs repository files to `/app`, handles optional dependency/assets ZIPs, syncs public files to `/public_html`, and runs Laravel migrations through the Console Kernel directly.

## Initial staging setup

### 1. Create/confirm DirectAdmin paths

Confirm the hosting account has these directories:

```text
/home/gpsystem/domains/gpsystem.thecamels.pl/app
/home/gpsystem/domains/gpsystem.thecamels.pl/public_html
```

The Laravel repository code lives in `/app`. The web-accessible document root is `/public_html`.

### 2. Create the public bridge files

`public_html/index.php` and `public_html/.htaccess` should route requests into Laravel in `/app`. These bridge files are server-local for this shared-hosting layout and are preserved by the browser deploy helper.

The helper also preserves `public_html/deploy.php`, so future repository public syncs do not overwrite the deploy file.

### 3. Create `.env`

Create `/home/gpsystem/domains/gpsystem.thecamels.pl/app/.env` manually from `.env.example` and set staging values.

Required values include:

- `APP_ENV=staging` or equivalent staging value
- `APP_KEY`
- `APP_URL=https://gpsystem.thecamels.pl`
- database connection settings
- cache/session/queue settings suitable for shared hosting
- mail settings if mail is enabled

Do not commit real `.env` values. The helper preserves `/app/.env` and never prints it.

### 4. Configure database

Create a staging database and user in DirectAdmin or the hosting database panel. Then set the database variables in `/app/.env`.

Example MySQL/MariaDB placeholders:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gps_product_hub_staging
DB_USERNAME=gps_product_hub_user
DB_PASSWORD=replace_with_staging_secret
```

### 5. Install dependencies without hosting Composer

Because Composer cannot run through browser deploy on this host, prepare dependencies locally when needed and upload them as `/app/vendor.zip`.

Normal code-only deploys do not need this because the helper preserves `/app/vendor`.

When dependencies change or vendor is missing:

1. Run Composer locally in a compatible PHP environment.
2. Create `vendor.zip` containing a top-level `vendor/` directory.
3. Either commit it at repository root for a one-time dependency deployment or upload it to the server-side expected path:

   ```text
   /home/gpsystem/domains/gpsystem.thecamels.pl/app/vendor.zip
   ```

4. Run the normal browser deploy URL.

The helper looks for `vendor.zip` at `/app/vendor.zip` first and then in the downloaded repository package root. It extracts to a temporary directory, verifies `vendor/autoload.php`, backs up the old `/app/vendor`, swaps the new vendor into place, verifies `/app/vendor/autoload.php`, and restores the previous vendor if the swap fails. If no archive is found, the output warns that Composer dependencies may be missing and Socialite may not be installed. The post-deploy diagnostics print `socialite_installed`, Google OAuth env-key presence booleans, and whether `migrate` executed without exposing secret values.

### 6. Prepare generated public assets when needed

If generated Filament/vendor/public assets changed and cannot be generated on the host:

1. Generate them locally.
2. Package the files that should land in `public_html` as `public-assets.zip`.
3. Upload it to:

   ```text
   /home/gpsystem/domains/gpsystem.thecamels.pl/public_html/public-assets.zip
   ```

4. Run the normal browser deploy URL.

If `public-assets.zip` is absent, the helper logs a warning and continues. This is normal for code-only deployments.

### 7. Install the browser deploy helper

Copy repository `deploy.example.php` to:

```text
/home/gpsystem/domains/gpsystem.thecamels.pl/public_html/deploy.php
```

Edit only the staging copy and replace:

```php
const DEPLOY_TOKEN = 'CHANGE_ME_TO_LONG_RANDOM_TOKEN';
```

Use one fixed, long, random staging token. Do not commit the real token.

## Runtime directories

The browser helper ensures these Laravel runtime directories exist on every deploy:

```text
storage/framework/cache/data
storage/framework/sessions
storage/framework/views
storage/logs
bootstrap/cache
```

The PHP/web-server user must be able to write to `/app/storage`, `/app/bootstrap/cache`, `/public_html`, and PHP temporary storage.

## Public file sync behavior

The repository has a normal Laravel `public/` directory under `/app/public` after code sync. The helper copies deployable public files from:

```text
/home/gpsystem/domains/gpsystem.thecamels.pl/app/public
```

to:

```text
/home/gpsystem/domains/gpsystem.thecamels.pl/public_html
```

This supports repository-managed files such as:

```text
public/images/car-placeholder.svg
```

The helper preserves these public-root files:

- `index.php`
- `deploy.php`
- `.htaccess`
- `deploy.lock`
- `public-assets.zip`

## Migration behavior

When Laravel dependencies are available, the helper runs migrations without shell access:

1. Loads `/app/vendor/autoload.php`.
2. Boots Laravel from `/app/bootstrap/app.php`.
3. Resolves `Illuminate\Contracts\Console\Kernel`.
4. Calls:

   ```php
   $kernel->call('migrate', ['--force' => true]);
   ```

5. Optionally calls:

   ```php
   $kernel->call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
   ```

6. Clears Laravel config/cache/view caches through the same Kernel object.

If Laravel cannot boot because `vendor/autoload.php` is missing, the helper shows a clear warning and skips migrations. Upload `/app/vendor.zip` and rerun deploy.

## Deployment readiness review

### Laravel web root

For this shared-hosting layout, the public web root is:

```text
/home/gpsystem/domains/gpsystem.thecamels.pl/public_html
```

The Laravel application root is outside the public web root:

```text
/home/gpsystem/domains/gpsystem.thecamels.pl/app
```

Do not expose `/app` directly as a document root. It contains `.env`, source code, configuration, and other files that must not be served directly.

### Admin URL

The Filament admin panel is configured with:

- Panel path: `/admin`
- Panel brand: `GPS Product Hub`
- Login enabled: yes

Expected staging admin URL:

```text
https://gpsystem.thecamels.pl/admin
```

### Required PHP extensions

Recommended baseline:

- PHP 8.3 or newer
- `zip` / `ZipArchive`
- `curl` or URL-enabled `file_get_contents`
- database PDO extension matching staging DB (`pdo_mysql` for MySQL/MariaDB)

Laravel/Filament also require standard PHP extensions such as:

- `ctype`
- `dom`
- `fileinfo`
- `filter`
- `hash`
- `mbstring`
- `openssl`
- `pdo`
- `session`
- `tokenizer`
- `xml`
- `intl` recommended

## Safety status

The current staging deployment should be treated as an admin foundation only:

- Filament admin shell exists.
- Placeholder pages exist for future modules.
- Roles can be seeded.
- Risky integrations are disabled by default.
- No external API writes exist.
- No marketplace publishing exists.
- No Cars/Samochody business logic is changed by deployment.
- No Części module is implemented by deployment.

## Post-deploy verification

After a successful browser deploy, verify:

- homepage loads at `https://gpsystem.thecamels.pl/`;
- `/admin` loads at `https://gpsystem.thecamels.pl/admin`;
- an admin user can log in;
- dashboard/navigation appear;
- feature flags remain disabled unless intentionally enabled;
- `.env` values were not printed or changed;
- `storage/` contents, uploaded files, and logs remain present;
- repository public files needed by the UI are available under `public_html`.

## Troubleshooting summary

### Invalid token

- Check server-side `public_html/deploy.php` contains the real fixed staging token.
- Check the browser URL uses the exact token.
- Rotate the token if it may have leaked.

### Missing vendor/autoload.php

- Create and upload `/app/vendor.zip`.
- Rerun the normal browser deploy URL.
- Confirm the ZIP contains top-level `vendor/autoload.php` after extraction.

### Generated public asset missing

- Create and upload `/public_html/public-assets.zip`.
- Rerun the normal browser deploy URL.
- Confirm repository-managed public files still live under `/app/public` and are not bridge files preserved by the sync.

### Migration failure

- Check database credentials in `/app/.env`.
- Confirm database connectivity and permissions.
- Inspect `/app/storage/logs/laravel.log`.

### Stale deploy lock

If no deployment is active, run the deploy URL with manual unlock:

```text
https://gpsystem.thecamels.pl/deploy.php?token=MY_FIXED_STAGING_TOKEN&unlock=1
```
