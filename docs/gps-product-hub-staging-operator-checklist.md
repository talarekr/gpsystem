# GPS Product Hub — Staging Operator Checklist

> **Current production domain:** use `https://gpswiss.pl` for deploy and diagnostic endpoints. The former technical hostname is retired and must not be used as a browser URL.

> Server filesystem paths may still contain `domains/gpsystem.thecamels.pl`; those paths identify the existing hosting layout and do not make that hostname a valid public URL.

Target staging URL:

```text
https://gpswiss.pl
```

Target admin URL:

```text
https://gpswiss.pl/admin
```

This checklist is for deploying the current GPS Product Hub foundation only. It does not enable product, staging, sync, marketplace, or external API features.

## 1. Server prerequisites check

Run:

```bash
php -v
composer --version
php -m
```

Confirm:

- PHP 8.3 or newer is installed.
- Composer 2.x is installed.
- Web server is available: Nginx or Apache.
- Database is available: PostgreSQL or MySQL/MariaDB.
- Required PHP extensions are installed:
  - `ctype`
  - `curl`
  - `dom`
  - `fileinfo`
  - `filter`
  - `hash`
  - `mbstring`
  - `openssl`
  - `pdo`
  - `pdo_mysql` or `pdo_pgsql`
  - `session`
  - `tokenizer`
  - `xml`
  - `zip`
- Recommended extensions:
  - `intl`
  - `redis` if Redis will be used later

## 2. Directory setup

Example server path:

```bash
sudo mkdir -p /var/www/gpsystem
sudo chown -R $USER:www-data /var/www/gpsystem
cd /var/www/gpsystem
```

Adjust user/group names for the actual server.

## 3. Git clone or pull

For first deployment:

```bash
cd /var/www
git clone <repository-url> gpsystem
cd /var/www/gpsystem
```

For updating an existing checkout:

```bash
cd /var/www/gpsystem
git status
git pull --ff-only
```

## 4. Composer install

For temporary staging/debug:

```bash
composer install
```

For more production-like staging:

```bash
composer install --no-dev --optimize-autoloader
```

If `vendor/autoload.php` is missing, Composer dependencies have not been installed successfully.

## 5. `.env` setup

Create `.env`:

```bash
cp .env.example .env
```

Edit `.env` with staging placeholders only. Do not commit real secrets.

Recommended staging values:

```dotenv
APP_NAME="GPS Product Hub"
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://gpswiss.pl

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
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS=no-reply@gpswiss.pl
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

Use MySQL/MariaDB instead if that is the chosen staging database:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gps_product_hub_staging
DB_USERNAME=gps_product_hub_user
DB_PASSWORD=replace_with_staging_secret
```

## 6. Database creation reminder

Create the staging database and user before running migrations.

Example PostgreSQL reminder:

```bash
sudo -u postgres psql
```

Then create a database/user according to the server policy. Use a strong staging-only password. Do not store real passwords in git or documentation.

Example MySQL/MariaDB reminder:

```bash
sudo mysql
```

Then create a database/user according to the server policy.

## 7. Laravel key generation

Run once after `.env` exists:

```bash
php artisan key:generate
```

Verify `.env` now contains `APP_KEY=...`.

## 8. Run migrations

```bash
php artisan migrate --force
```

## 9. Seed MVP roles

```bash
php artisan db:seed --class=RoleSeeder --force
```

This creates the current MVP roles:

- `owner_admin`
- `manager`
- `warehouse_product_staff`
- `pricing_staff`
- `viewer`

## 10. Create first admin user

Use `php artisan tinker`. Do not hardcode real credentials in a seeder.

```bash
php artisan tinker
```

Paste and edit the email/password values:

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

Exit tinker:

```php
exit
```

## 11. Storage link

```bash
php artisan storage:link
```

## 12. Cache optimization

After `.env` is correct:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If `.env` or config changes later:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 13. Web server document root

The web server document root must be Laravel's `public` directory:

```text
/var/www/gpsystem/public
```

Do not point the document root to:

```text
/var/www/gpsystem
```

The repository root contains `.env`, source code, and configuration files that must not be web-accessible.

## 14. HTTPS/SSL reminder

Configure SSL/TLS for:

```text
gpswiss.pl
```

The final public URL should be:

```text
https://gpswiss.pl
```

The admin URL should be:

```text
https://gpswiss.pl/admin
```

## 15. Queue worker note

The current foundation does not run product or marketplace jobs yet, but the queue tables exist.

For database queue staging setup:

```dotenv
QUEUE_CONNECTION=database
```

Optional worker command:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Manage the worker with Supervisor or systemd when queue usage begins.

## 16. Scheduler cron note

Add the standard Laravel scheduler cron entry for the deploy user:

```cron
* * * * * cd /var/www/gpsystem && php artisan schedule:run >> /dev/null 2>&1
```

No business automation is currently implemented, but this prepares staging for future tickets.

## 17. File permissions

The web/PHP user must be able to write to:

```text
storage
bootstrap/cache
```

Example for Debian/Ubuntu with `www-data`:

```bash
cd /var/www/gpsystem
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

Adjust ownership for the actual deploy user and web server user.

## 18. Verification steps

Run from the project root:

```bash
php artisan about
php artisan migrate:status
php artisan route:list --path=admin
```

Verify in browser:

- `https://gpswiss.pl` responds.
- `https://gpswiss.pl/admin` loads the Filament login page.
- First admin can log in.
- Dashboard appears.
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

Verify risky feature flags:

```bash
php artisan tinker
```

```php
config('product-hub.feature_flags');
```

Expected: all flags are `false` unless a later ticket explicitly enables a safe read-only capability.

## Troubleshooting

### HTTP 500

Check logs:

```bash
tail -100 storage/logs/laravel.log
```

Common causes:

- Missing Composer dependencies.
- Missing `APP_KEY`.
- Database connection failure.
- Wrong file permissions on `storage` or `bootstrap/cache`.
- Old config cache after changing `.env`.

Run:

```bash
php artisan optimize:clear
```

Then retry.

### Blank page

Check:

```bash
tail -100 storage/logs/laravel.log
php artisan about
```

Also confirm the web server is pointing to `public` and PHP-FPM/Apache PHP is running.

### Wrong document root

Symptoms:

- Source files are visible in browser.
- `/admin` returns 404 from the web server.
- `.env` might be exposed if the server is dangerously misconfigured.

Fix the virtual host document root to:

```text
/var/www/gpsystem/public
```

### Missing `vendor/autoload.php`

Run:

```bash
composer install
```

or:

```bash
composer install --no-dev --optimize-autoloader
```

If Composer cannot reach Packagist, fix server network/DNS/proxy access or use an approved Composer mirror/cache.

### Storage permission errors

Run:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

Adjust `www-data` if the server uses a different PHP/web user.

### Database connection errors

Check `.env`:

```dotenv
DB_CONNECTION=
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

Then clear cached config:

```bash
php artisan optimize:clear
php artisan config:cache
```

Confirm the database exists and the database user has privileges.

### Migrations failing

Run:

```bash
php artisan migrate:status
php artisan migrate --force -v
```

Check:

- Database credentials.
- Database permissions.
- Whether tables already exist from a partial install.
- Laravel log output.

### Admin login forbidden

If login works but admin access is forbidden, the user likely does not have one of the allowed MVP roles.

Run:

```bash
php artisan tinker
```

Then:

```php
use App\Models\User;
use App\Enums\UserRole;

$user = User::where('email', 'admin@example.com')->first();
$user->assignRole(UserRole::OwnerAdmin->value);
```

Also confirm the role seeder was run:

```bash
php artisan db:seed --class=RoleSeeder --force
```

### `APP_KEY` missing

Run:

```bash
php artisan key:generate
php artisan optimize:clear
php artisan config:cache
```

### Config cache using old `.env`

If changed `.env` values are not reflected, run:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then reload PHP-FPM if needed:

```bash
sudo systemctl reload php*-fpm
```

## Safety confirmation

This checklist does not enable:

- Product features.
- Staging item workflows.
- Mobile intake functionality.
- WooCommerce sync.
- eBay publishing.
- Allegro integration.
- Ovoko integration.
- NBP integration.
- External API writes.

All risky feature flags should remain disabled on staging until a later ticket explicitly changes that behavior.
