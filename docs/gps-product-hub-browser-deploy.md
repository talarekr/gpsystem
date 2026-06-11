# GPS Product Hub browser deployment helper

This document describes the staging/test browser deployment helper for the GPS Product Hub Laravel application.

> **Staging-only:** this helper is intentionally simple and is not final production CI/CD. It is designed for protected staging/test deployments when SSH-based deploy commands, Deployer, and GitHub Actions are not being used.

## 1. How browser deploy works

1. Copy `deploy.example.php` to the server as `deploy.php`.
2. Place `deploy.php` in the Laravel project root, which is the directory that contains `artisan`, `composer.json`, `app/`, `bootstrap/`, `config/`, `public/`, `resources/`, `routes/`, and `storage/`.
3. Manually replace the placeholder inside `deploy.php` with one fixed permanent staging token on the server.
4. Open the protected URL in a browser:

   ```text
   https://gpsystem.thecamels.pl/deploy.php?token=MY_FIXED_TOKEN
   ```

5. The script validates the token and rejects invalid requests with HTTP 403.
6. The script acquires a lock file so two browser deployments cannot run at the same time.
7. The script downloads the GitHub ZIP, extracts it, copies the application package into the server application directory, runs Laravel post-deploy commands, prints a readable browser log, and cleans up temporary files.

## 2. GitHub ZIP download

The helper downloads the complete `main` branch ZIP from:

```text
https://github.com/talarekr/gpsystem/archive/refs/heads/main.zip
```

The ZIP is saved to temporary server storage, normally under PHP's `sys_get_temp_dir()` location. The ZIP is then extracted into a temporary directory, and the script searches the extracted contents for the Laravel repository directory containing both `artisan` and `composer.json`.

## 3. Copying the whole GitHub package to the server

After extraction, the helper syncs the extracted repository package into the current Laravel project root.

It may overwrite application code and repository-managed files, including:

- `app/`
- `bootstrap/`
- `config/`
- `database/`
- `docs/`
- `public/`
- `resources/`
- `routes/`
- `tests/`
- `composer.json`
- `composer.lock`, when present in the repository
- other normal repository files

It also removes stale target files that are no longer present in the repository, except for preserved or excluded server-local paths.

## 4. Preserved files and directories

The helper preserves server-local runtime state and never overwrites or deletes:

- `.env`
- `storage/`
- uploaded files inside `storage/`
- logs inside `storage/`
- server-local runtime data inside `storage/`
- `deploy.php`
- `deploy.lock`
- `vendor/` when `preserve_vendor` is enabled in the script configuration

The default example keeps `vendor/` preserved and still runs `composer install --no-dev --optimize-autoloader` after copying.

## 5. Why `.env` and `storage` must not be overwritten

`.env` contains server-specific secrets and environment settings such as database credentials, `APP_KEY`, mail settings, cache/session drivers, and integration credentials. It must never be committed with real values and must never be overwritten by a downloaded repository ZIP.

`storage/` contains Laravel runtime files and user/server-generated state, including logs, caches, sessions, uploaded files, and symbolic-link targets used by `php artisan storage:link`. Deleting or replacing `storage/` can break the application, remove uploaded files, or erase troubleshooting logs.

## 6. Where to place `deploy.php`

Place `deploy.php` in the Laravel project root on the server:

```text
/path/to/gpsystem/deploy.php
/path/to/gpsystem/artisan
/path/to/gpsystem/composer.json
/path/to/gpsystem/public/index.php
```

The web server document root should still point to Laravel's `public/` directory, not the project root:

```text
/path/to/gpsystem/public
```

If the hosting environment cannot execute `deploy.php` from the project root because the document root is correctly set to `public/`, either temporarily expose the script through a secure server rule or place a tightly protected wrapper in `public/` that includes the root script. Remove that exposure when deployment is complete.

## 7. Setting the private token manually

On the server only:

1. Copy `deploy.example.php` to `deploy.php`.
2. Open `deploy.php` on the server.
3. Replace the placeholder token:

   ```php
   const DEPLOY_TOKEN = 'CHANGE_ME_TO_LONG_RANDOM_TOKEN';
   ```

4. Use one fixed permanent staging token that is long, random, and private, for example 48+ random characters.
5. Do not commit `deploy.php` with the real token.
6. Do not print, share, or store the token in documentation.
7. Do not generate rotating tokens for this staging helper; use the same fixed token until you intentionally change it.
8. Do not require environment-based token setup unless you intentionally customize the script later.

Recommended additional protections:

- IP-restrict `deploy.php` at the web server or hosting panel level.
- Remove, rename, or IP-restrict `deploy.php` later if more security is needed.
- Remove `deploy.php` when staging deployment is not needed.
- Change the fixed token immediately if it is ever exposed.

## 8. Required PHP extensions

- `ZipArchive` / PHP `zip` extension

## 9. Required PHP functions

For ZIP download:

- `file_get_contents`, or
- `curl` functions such as `curl_init`

For Composer and Artisan post-deploy commands:

- `proc_open`

Some hosts list related command-execution functions such as `exec` or `shell_exec`; the example script uses `proc_open` so command output can be captured and displayed in the browser log.

## 10. Required server commands

The server should provide these commands in the PATH, or the script configuration should point to their absolute paths:

- `php`
- `composer`

Example configurable values in `deploy.php`:

```php
'php_binary' => 'php',
'composer_binary' => 'composer',
```

## 11. Required file permissions

The PHP/web-server user that runs `deploy.php` needs permission to:

- create and write the deployment lock file in the Laravel project root;
- write application files and directories in the Laravel project root;
- write temporary ZIP and extraction files under PHP's temporary directory;
- read the extracted ZIP contents;
- preserve and write Laravel runtime directories as needed;
- run `composer install` if Composer post-deploy installation is enabled;
- run `php artisan` commands if Artisan post-deploy steps are enabled.

Laravel still needs normal runtime write permissions for:

- `storage/`
- `bootstrap/cache/`

## 12. Web server document root

The web server document root should remain Laravel's `public/` directory:

```text
/path/to/gpsystem/public
```

Expected public paths after deployment:

```text
https://gpsystem.thecamels.pl/
https://gpsystem.thecamels.pl/admin
```

Do not change the document root to the Laravel project root just to expose the deploy helper permanently. If temporary browser access to `deploy.php` is needed, protect it carefully and remove it after use.

## 13. First-time setup steps

1. Upload or clone the Laravel application package to the server once.
2. Configure the web server document root to Laravel `public/`.
3. Create the server `.env` file manually.
4. Generate or set `APP_KEY` in `.env`.
5. Configure database, cache, queue, mail, and application URL values in `.env`.
6. Ensure `storage/` and `bootstrap/cache/` are writable by the PHP/web-server user.
7. Install dependencies once if needed:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

8. Run initial migrations if needed:

   ```bash
   php artisan migrate --force
   ```

9. Copy `deploy.example.php` to `deploy.php` on the server.
10. Replace the placeholder with the fixed private deploy token in `deploy.php`.
11. Restrict access to `deploy.php` by IP or remove it until needed.

## 14. Normal browser deploy URL

Open this URL with the fixed server token:

```text
https://gpsystem.thecamels.pl/deploy.php?token=MY_FIXED_TOKEN
```

The browser page shows each deployment step, including download, extract, copy, Composer, Artisan commands, cleanup, and any failed command.

## 15. Post-deploy verification

After a successful browser deploy, verify:

- the homepage loads at `https://gpsystem.thecamels.pl/`;
- `/admin` loads at `https://gpsystem.thecamels.pl/admin`;
- the first admin can log in;
- the dashboard and navigation appear;
- feature flags remain disabled unless intentionally enabled;
- `.env` values were not printed or changed;
- `storage/` contents, uploaded files, and logs remain present.

## 16. Post-deploy commands

When the required commands are available, the helper runs:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

The script stops on critical command failures. `storage:link` and `queue:restart` are treated as non-critical in the example because some hosting environments already have the link or do not run queue workers.

## 17. Troubleshooting

### Invalid token

Symptoms:

- browser shows HTTP 403;
- deploy log says the token is invalid or not configured.

Fixes:

- confirm `DEPLOY_TOKEN` was changed in server-side `deploy.php`;
- confirm the browser URL uses the exact fixed token;
- ensure no spaces were copied into the token;
- change the fixed token immediately if it may have been exposed.

### ZIP download fails

Symptoms:

- deploy log stops during the GitHub ZIP download step.

Fixes:

- confirm the server can reach GitHub over HTTPS;
- confirm `curl` or `file_get_contents` URL access is enabled;
- check firewall, DNS, proxy, or hosting outbound-network restrictions;
- manually test the ZIP URL from the server if shell access is available.

### ZIP extract fails

Symptoms:

- deploy log reports ZipArchive is missing or extraction failed.

Fixes:

- enable the PHP `zip` extension;
- ensure enough temporary disk space is available;
- ensure the PHP user can write to the temporary directory;
- confirm the downloaded file is a valid ZIP and not an HTML error page.

### Copy fails

Symptoms:

- deploy log stops while copying files into the target directory.

Fixes:

- check ownership and permissions for the Laravel project root;
- ensure the PHP/web-server user can write application files;
- ensure read-only files are not blocking overwrite;
- verify disk space is available.

### Composer fails

Symptoms:

- `composer install --no-dev --optimize-autoloader` exits with a non-zero status.

Fixes:

- ensure `composer` is installed and available in PATH;
- set an absolute `composer_binary` path in `deploy.php` if needed;
- check PHP version and required PHP extensions;
- check network access to package repositories;
- confirm `composer.lock` is compatible with the server PHP version.

### Artisan command fails

Symptoms:

- a `php artisan ...` command exits with a non-zero status.

Fixes:

- ensure `php` is installed and available in PATH;
- set an absolute `php_binary` path in `deploy.php` if needed;
- check `.env` database credentials;
- confirm the database is reachable;
- inspect `storage/logs/laravel.log` after deploy;
- run the failing command manually over SSH if available.

### Wrong document root

Symptoms:

- homepage shows directory listing, source files, or a 404;
- `/admin` does not route through Laravel.

Fixes:

- set the web server document root to Laravel `public/`;
- confirm `public/index.php` is the front controller;
- confirm rewrite rules pass requests to Laravel.

### Permission denied

Symptoms:

- copy, cache, log, or lock-file operations fail with permission errors.

Fixes:

- set correct ownership for the project files;
- make `storage/` and `bootstrap/cache/` writable by the PHP/web-server user;
- ensure PHP can write to the system temporary directory;
- remove stale lock files only after confirming no deployment is running.

### 500 error after deploy

Symptoms:

- deployment appears successful but the site returns HTTP 500.

Fixes:

- check `storage/logs/laravel.log`;
- confirm `.env` exists and has a valid `APP_KEY`;
- confirm dependencies exist under `vendor/`;
- run `composer install --no-dev --optimize-autoloader`;
- clear and rebuild Laravel caches;
- confirm database migrations completed successfully.

### Stale config cache

Symptoms:

- changed `.env` values do not appear to take effect;
- the app behaves as if old settings are still active.

Fixes:

- run `php artisan config:clear`;
- run `php artisan cache:clear`;
- run `php artisan config:cache` after the correct `.env` is in place.

### Missing `vendor/autoload.php`

Symptoms:

- Laravel fails with a missing `vendor/autoload.php` error.

Fixes:

- ensure `composer install --no-dev --optimize-autoloader` completed successfully;
- ensure `vendor/` was not deleted if Composer cannot run on the host;
- keep `preserve_vendor` enabled on constrained shared hosting;
- verify Composer can write to the project directory.
