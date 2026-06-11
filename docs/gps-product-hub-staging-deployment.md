# GPS Product Hub — Staging Deployment Guide

## Purpose

This document describes how to install and run the current GPS Product Hub MVP Ticket 1 foundation on the staging/test domain:

- Staging domain: `https://gpsystem.thecamels.pl`
- Expected admin URL: `https://gpsystem.thecamels.pl/admin`

This is a staging/test deployment guide only. The current application is still the MVP Ticket 1/1A foundation: Laravel, Filament admin, authentication, roles, placeholder navigation, and disabled-by-default safety flags. It does not include product intake, staging workflows, product catalog data, Woo sync, marketplace publishing, or external API writes.

## Deployment readiness review

### Laravel web root

The web server document root should point to the Laravel `public` directory:

```text
/path/to/gpsystem/public
```

Do not point the web server document root at the repository root. The repository root contains `.env`, source code, configuration, and other files that must not be served directly.

### Admin URL

The Filament admin panel is configured with:

- Panel path: `/admin`
- Panel brand: `GPS Product Hub`
- Login enabled: yes

For the staging domain, the expected admin URL is:

```text
https://gpsystem.thecamels.pl/admin
```

### Local-only assumptions

The foundation is suitable for a normal Laravel staging server. It does not intentionally depend on local-only paths or local-only services. The default `.env.example` uses SQLite for local development, but the staging server should normally use MySQL/MariaDB or PostgreSQL.

### Current feature status

The current staging deployment should be treated as an admin foundation only:

- Filament admin shell exists.
- Placeholder pages exist for future modules.
- Roles can be seeded.
- Risky integrations are disabled by default.
- No external API writes exist.
- No marketplace publishing exists.

## Composer dependency review

The project intentionally keeps these package requirements:

- `laravel/framework`
- `filament/filament`
- `spatie/laravel-permission`

The current Codex environment may be unable to download dependencies from Packagist because the environment proxy returns HTTP 403. Do not remove Laravel, Filament, or Spatie Permission dependencies because of that Codex environment limitation. The real staging server must have normal Composer/Packagist access or an approved Composer mirror/cache.

Recommended install commands:

For temporary staging/debug with dev tools:

```bash
composer install
```

For a more production-like staging setup:

```bash
composer install --no-dev --optimize-autoloader
```

If using the production-like command, run tests in CI or another environment before deployment because PHPUnit and development tools will not be installed on the server.

## Required server dependencies

Recommended baseline:

- PHP 8.3 or newer.
- Composer 2.x.
- Web server: Nginx or Apache.
- Database: PostgreSQL or MySQL/MariaDB.
- Node.js and npm only if frontend assets need to be built on the server.
- Redis recommended for future queue/cache work, but the MVP foundation can run with database-backed queue/cache if needed.

Recommended PHP extensions:

- `ctype`
- `curl`
- `dom`
- `fileinfo`
- `filter`
- `hash`
- `mbstring`
- `openssl`
- `pdo`
- `pdo_mysql` for MySQL/MariaDB or `pdo_pgsql` for PostgreSQL
- `session`
- `tokenizer`
- `xml`
- `zip`
- `intl` recommended
- `redis` recommended if using Redis queues/cache

## Deployment steps

### 1. Prepare code on server

Example:

```bash
cd /var/www
git clone <repository-url> gpsystem
cd /var/www/gpsystem
```

Or deploy by your normal release process.

### 2. Install Composer dependencies

Temporary staging/debug:

```bash
composer install
```

Production-like staging:

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Install/build frontend assets if required

If the deployment process requires local asset builds, run:

```bash
npm install
npm run build
```

The current Ticket 1 foundation does not add custom frontend build assets, but Filament/Laravel deployments commonly include a Node build step once frontend assets are introduced.

### 4. Create `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env` for staging values. Do not commit real `.env` files.

### 5. Configure database

Create a staging database and database user in PostgreSQL or MySQL/MariaDB, then set the database variables in `.env`.

Example PostgreSQL placeholders:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gps_product_hub_staging
DB_USERNAME=gps_product_hub_user
DB_PASSWORD=replace_with_staging_secret
```

Example MySQL/MariaDB placeholders:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gps_product_hub_staging
DB_USERNAME=gps_product_hub_user
DB_PASSWORD=replace_with_staging_secret
```

### 6. Run migrations and seed MVP roles

```bash
php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
```

The role seeder creates the MVP role records:

- `owner_admin`
- `manager`
- `warehouse_product_staff`
- `pricing_staff`
- `viewer`

### 7. Create the storage symlink

```bash
php artisan storage:link
```

### 8. Optimize Laravel caches

For staging after `.env` is correct:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

When changing `.env` or config files, clear and rebuild caches:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 9. Configure queue worker

The MVP foundation does not yet process business jobs, but Laravel queue tables are present. For staging, database queue can be used initially:

```dotenv
QUEUE_CONNECTION=database
```

A basic worker can be managed by Supervisor/systemd:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

When Redis/Horizon is introduced later, prefer Horizon for queue monitoring. Horizon is recommended for the long-term architecture but is not required for the current Ticket 1 foundation.

### 10. Configure scheduler cron

Add the standard Laravel scheduler entry for the deploy user:

```cron
* * * * * cd /var/www/gpsystem && php artisan schedule:run >> /dev/null 2>&1
```

The MVP foundation does not add scheduled business automation yet, but this prepares the server for future Laravel jobs.

### 11. Configure file permissions

The web/PHP user must be able to write to:

```text
storage
bootstrap/cache
```

Example:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

Adjust user/group names for the actual server.

### 12. Configure web server and HTTPS

Use HTTPS for the staging domain:

```text
https://gpsystem.thecamels.pl
```

The web server should:

- Serve `gpsystem.thecamels.pl` over SSL/TLS.
- Point document root to `/var/www/gpsystem/public` or the equivalent release `public` directory.
- Route missing files to `public/index.php` using standard Laravel Nginx/Apache rules.
- Deny access to hidden files such as `.env`.

## Recommended staging `.env` values

Use placeholders only. Do not put real secrets in documentation or git.

```dotenv
APP_NAME="GPS Product Hub"
APP_ENV=staging
APP_KEY=base64:generated_by_php_artisan_key_generate
APP_DEBUG=false
APP_URL=https://gpsystem.thecamels.pl

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gps_product_hub_staging
DB_USERNAME=gps_product_hub_user
DB_PASSWORD=replace_with_staging_secret

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

CACHE_STORE=database
QUEUE_CONNECTION=database

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_FROM_ADDRESS=no-reply@gpsystem.thecamels.pl
MAIL_FROM_NAME="GPS Product Hub"

GPS_INTEGRATIONS_ENABLED=false
GPS_WOO_WRITES_ENABLED=false
GPS_MARKETPLACE_PUBLISHING_ENABLED=false
GPS_EXTERNAL_API_WRITES_ENABLED=false
GPS_EBAY_PUBLISHING_ENABLED=false
GPS_ALLEGRO_INTEGRATION_ENABLED=false
GPS_OVOKO_INTEGRATION_ENABLED=false
GPS_NBP_RATES_ENABLED=false
```

`APP_DEBUG=false` is recommended for staging. Temporarily set `APP_DEBUG=true` only during initial setup/debug, then turn it back off and rebuild config cache.

## Safety flag review

The staging domain can run safely without accidentally publishing because the current foundation has no publishing code and these flags default to `false`:

- `GPS_INTEGRATIONS_ENABLED`
- `GPS_WOO_WRITES_ENABLED`
- `GPS_MARKETPLACE_PUBLISHING_ENABLED`
- `GPS_EXTERNAL_API_WRITES_ENABLED`
- `GPS_EBAY_PUBLISHING_ENABLED`
- `GPS_ALLEGRO_INTEGRATION_ENABLED`
- `GPS_OVOKO_INTEGRATION_ENABLED`
- `GPS_NBP_RATES_ENABLED`

Current intended behavior:

- Woo writes disabled.
- eBay publishing disabled.
- Allegro integration disabled.
- Ovoko integration disabled.
- NBP rates disabled unless later configured as read-only.
- Risky automation disabled.
- No production credentials present.

## First admin creation method

Do not hardcode a real password in a seeder. For staging, create the first admin via `php artisan tinker` after migrations and role seeding.

```bash
php artisan tinker
```

Then run the following, replacing the email and password with staging-only values:

```php
use App\Models\User;
use App\Enums\UserRole;

$user = User::create([
    'name' => 'GPS Admin',
    'email' => 'admin@example.com',
    'password' => 'replace-with-a-strong-staging-password',
]);

$user->assignRole(UserRole::OwnerAdmin->value);
```

Then exit tinker:

```php
exit
```

After login, change credentials according to the team policy. Do not commit the staging email/password anywhere.

## Deployment verification checklist

After deployment, verify:

- `https://gpsystem.thecamels.pl` responds and redirects or routes to the application.
- `https://gpsystem.thecamels.pl/admin` loads the Filament login page.
- The first admin can log in.
- The dashboard loads.
- Placeholder navigation appears:
  - Dashboard
  - Mobile Intake
  - Staging Items
  - Product Catalog
  - Product Command Center
  - Pricing
  - Stock / Locations
  - Readiness
  - Woo Sync Preparation
  - Orders
  - Error Center
  - Settings
  - Users / Roles
- Migrations ran successfully.
- Role seeder ran successfully.
- `storage` and `bootstrap/cache` are writable by PHP.
- `storage/logs/laravel.log` is writable.
- `php artisan config:cache` succeeds.
- `php artisan route:cache` succeeds.
- Risky feature flags are disabled.
- No external API credentials are present.
- No external API writes are possible.
- No marketplace publishing is possible.

## Useful verification commands

Run on the staging server from the project root:

```bash
php artisan about
php artisan migrate:status
php artisan route:list --path=admin
php artisan tinker
```

Inside tinker, verify roles and flags:

```php
use Spatie\Permission\Models\Role;

Role::pluck('name')->all();
config('product-hub.feature_flags');
```

All feature flags should be `false` unless a later ticket explicitly enables a safe read-only capability.

## Current Ticket 1A boundaries

This staging readiness pass does not add:

- Staging items.
- Mobile intake functionality.
- Product catalog data model.
- Photo upload.
- Duplicate detection.
- Readiness engine.
- Woo sync.
- eBay publishing.
- Allegro integration.
- Ovoko integration.
- NBP integration.
- External API writes.
