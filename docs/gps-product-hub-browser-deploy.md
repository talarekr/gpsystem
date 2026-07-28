# GPS Product Hub one-file browser deployment helper

> **Current production domain:** use `https://gpswiss.pl` for deploy and diagnostic endpoints. The former technical hostname is retired and must not be used as a browser URL.

> Server filesystem paths may still contain `domains/gpsystem.thecamels.pl`; those paths identify the existing hosting layout and do not make that hostname a valid public URL.

This document describes the staging browser deployment helper for the GPS Product Hub Laravel application on the current DirectAdmin/shared-hosting environment.

> **Staging only:** this is a pragmatic shared-hosting deployment helper. It is not the final production deployment architecture. Production should later move to VPS/SSH, GitHub Actions, Deployer, or another real CI/CD flow.

## Staging paths and URL

Current staging values used by `deploy.example.php`:

| Item | Value |
| --- | --- |
| Staging domain | `https://gpswiss.pl` |
| Laravel app root | `/home/gpsystem/domains/gpsystem.thecamels.pl/app` |
| Public root | `/home/gpsystem/domains/gpsystem.thecamels.pl/public_html` |
| Browser deploy file | `/home/gpsystem/domains/gpsystem.thecamels.pl/public_html/deploy.php` |
| GitHub ZIP | `https://github.com/talarekr/gpsystem/archive/refs/heads/main.zip` |
| Admin URL | `https://gpswiss.pl/admin` |

The helper is copied from repository file `deploy.example.php` to `public_html/deploy.php` on staging. The real deploy token is edited only on the staging server and must not be committed.

## Normal one-click deploy

For normal code-only changes:

1. Push/merge the desired changes to the GitHub `main` branch.
2. Open the protected staging URL:

   ```text
   https://gpswiss.pl/deploy.php?token=MY_FIXED_STAGING_TOKEN
   ```

3. Read the browser log until it ends with a successful completion message.
4. Verify:
   - `https://gpswiss.pl/`
   - `https://gpswiss.pl/admin`

No shell access is required for this flow.

## What the helper does

The single browser file performs the staging deployment steps that previously required several temporary helper files:

1. Validates the fixed staging token.
2. Downloads the GitHub `main` ZIP.
3. Extracts the ZIP to temporary storage.
4. Finds the Laravel repository folder inside the extracted ZIP.
5. Syncs repository files to `/home/gpsystem/domains/gpsystem.thecamels.pl/app`.
6. Preserves server-local `.env`, `storage/`, and `vendor/`.
7. Ensures Laravel runtime directories exist:
   - `storage/framework/cache/data`
   - `storage/framework/sessions`
   - `storage/framework/views`
   - `storage/logs`
   - `bootstrap/cache`
8. Automatically extracts `/app/vendor.zip` when present, then deletes the archive after successful extraction.
9. Automatically extracts `/public_html/public-assets.zip` when present, then deletes the archive after successful extraction.
10. Syncs deployable files from `/app/public` to `/public_html` while preserving public-hosting bridge files.
11. Runs Laravel migrations through the Laravel Console Kernel directly, not through shell commands.
12. Optionally seeds `RoleSeeder` through the same Console Kernel path.
13. Clears Laravel config/cache/view caches through the Console Kernel when Laravel can boot.
14. Cleans temporary files.
15. Prints browser-readable logs.

## Shared-hosting limitations

The staging host disables `proc_open`, so browser deployment must not rely on shell commands such as:

```bash
composer install
php artisan migrate
php artisan filament:assets
```

The redesigned helper does **not** use `proc_open`, `shell_exec`, `exec`, Composer, or shell-based Artisan commands. It uses PHP filesystem functions, `ZipArchive`, and Laravel's Console Kernel directly.

Required PHP capabilities:

- PHP `zip` extension / `ZipArchive`
- either `curl` functions or URL-enabled `file_get_contents` for downloading the GitHub ZIP
- write access for the PHP user to `/app`, `/public_html`, and temporary storage

## Token handling

`deploy.example.php` contains only a placeholder:

```php
const DEPLOY_TOKEN = 'CHANGE_ME_TO_LONG_RANDOM_TOKEN';
```

On staging only:

1. Copy `deploy.example.php` to `/home/gpsystem/domains/gpsystem.thecamels.pl/public_html/deploy.php`.
2. Replace the placeholder with one fixed, long, random staging token.
3. Keep using that token until it is intentionally rotated.
4. Never commit the real token.
5. Change the token immediately if it may have been exposed.

Recommended extra protections:

- IP-restrict `deploy.php` in DirectAdmin or web-server settings where possible.
- Remove or rename `deploy.php` when browser deployment is no longer needed.
- Do not print or paste the token in issue comments, docs, commits, or chat logs.

## Vendor handling without Composer

The host cannot run Composer during browser deploy. The helper therefore preserves the existing `/app/vendor` directory during normal deployments.

Normal code-only deploy:

- no `vendor.zip` is needed;
- `/app/vendor` remains untouched;
- Composer is not executed.

When dependencies change or vendor is missing:

1. Build/install dependencies locally in an environment compatible with staging.
2. Create a ZIP whose contents either include a top-level `vendor/` directory or are the contents of the vendor directory itself. The top-level form is preferred:

   ```text
   vendor.zip
   └── vendor/
       ├── autoload.php
       └── ...
   ```

3. Upload the archive to:

   ```text
   /home/gpsystem/domains/gpsystem.thecamels.pl/app/vendor.zip
   ```

4. Open the normal deploy URL.
5. The helper detects `vendor.zip`, removes the old `/app/vendor`, extracts the archive into `/app/vendor`, supports either archive layout, normalizes Windows-style ZIP path separators, and deletes `vendor.zip` after successful extraction.

If `vendor/autoload.php` is still missing after deployment, migrations are skipped with a clear browser warning because Laravel cannot boot without dependencies.

## Public assets handling

Some public assets, especially generated Filament/vendor assets, may need to be prepared locally because the host cannot run shell commands such as `php artisan filament:assets`.

When generated public assets change:

1. Generate the assets locally.
2. Package the files that must be copied into `public_html` as `public-assets.zip`.
3. Upload the archive to:

   ```text
   /home/gpsystem/domains/gpsystem.thecamels.pl/public_html/public-assets.zip
   ```

4. Open the normal deploy URL.
5. The helper detects `public-assets.zip`, extracts it into `/public_html`, normalizes Windows-style ZIP path separators, and deletes the archive after successful extraction.

If `public-assets.zip` is absent, deployment continues and logs a warning. This is expected for normal code-only deploys.

## Public file sync from `/app/public` to `/public_html`

After syncing the repository into `/app`, the helper syncs deployable repository public files from:

```text
/home/gpsystem/domains/gpsystem.thecamels.pl/app/public
```

to:

```text
/home/gpsystem/domains/gpsystem.thecamels.pl/public_html
```

This supports repository-managed public files such as:

```text
public/images/car-placeholder.svg
```

The sync preserves the shared-hosting bridge files and deployment files in `public_html`:

- `index.php`
- `deploy.php`
- `.htaccess`
- `deploy.lock`
- `public-assets.zip`

The helper copies repository public files but does not delete other existing files from `public_html`, so generated assets uploaded through `public-assets.zip` are not removed by the repository public sync.

## Migration behavior without shell

When `/app/vendor/autoload.php` and `/app/bootstrap/app.php` are available, the helper boots Laravel from PHP:

1. `require_once /app/vendor/autoload.php`
2. `require /app/bootstrap/app.php`
3. Resolve `Illuminate\Contracts\Console\Kernel` from the application container.
4. Call `$kernel->bootstrap()`.
5. Call migrations directly:

   ```php
   $kernel->call('migrate', ['--force' => true]);
   ```

6. Optionally call the role seeder directly:

   ```php
   $kernel->call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
   ```

7. Clear Laravel config/cache/view caches through the same Kernel object.

This avoids `php artisan ...` shell execution and does not require `proc_open`.

Migration failures are critical because the database schema must match the deployed code. `RoleSeeder` is treated as optional/non-critical so an already-seeded staging database does not block normal code deploys unnecessarily.

## Lock behavior and manual unlock

The helper uses `public_html/deploy.lock` to prevent accidental overlapping browser deploys. To avoid stuck locks:

- the lock is deleted at the end of a normal deploy;
- locks older than the configured stale timeout are removed automatically;
- a manual unlock is available by adding `&unlock=1` to the deploy URL after confirming no deployment is active:

  ```text
  https://gpswiss.pl/deploy.php?token=MY_FIXED_STAGING_TOKEN&unlock=1
  ```

## Safety confirmations

The staging helper intentionally keeps risky integrations disabled:

- no external API writes are enabled;
- no marketplace publishing is enabled;
- no business module logic is implemented by the helper;
- no Cars/Samochody or Części feature logic is changed by deployment;
- no `.env` secrets are overwritten or printed;
- no `storage/` contents are overwritten or deleted;
- no shell commands are executed;
- no Composer install is attempted on the host.

## Troubleshooting

### Invalid token

- Confirm the real token was edited into server-side `public_html/deploy.php`.
- Confirm the browser URL contains the exact token.
- Rotate the token if it may have been exposed.

### GitHub ZIP download fails

- Confirm the host can reach GitHub over HTTPS.
- Confirm `curl` or URL-enabled `file_get_contents` is available.
- Check hosting firewall/DNS/outbound network rules.

### ZIP extraction fails

- Confirm the PHP `zip` extension is enabled.
- Confirm enough disk space is available.
- Confirm PHP can write to temporary storage, `/app`, and `/public_html`.
- Recreate `vendor.zip` or `public-assets.zip` if the uploaded archive is corrupt.

### Laravel cannot boot

- Confirm `/app/vendor/autoload.php` exists.
- Upload `/app/vendor.zip` if dependencies changed or vendor is missing.
- Confirm `/app/.env` exists and contains a valid `APP_KEY` and database settings.
- Check `/app/storage/logs/laravel.log`.

### Migration fails

- Confirm database credentials in `.env`.
- Confirm the database server is reachable from staging.
- Confirm the staging database user can create/alter tables.
- Check the browser log and Laravel log.

### Public file missing after deploy

- Confirm the file exists in repository `public/`.
- Confirm the browser log includes the public sync step.
- Confirm the file is not one of the preserved bridge files.
- If it is a generated Filament/vendor asset, package and upload `public-assets.zip`.

### Stuck deployment lock

- Wait for the current deploy to finish if one is active.
- If no deploy is active and the lock is stale, retry with `&unlock=1`.
