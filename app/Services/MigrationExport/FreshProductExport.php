<?php

namespace App\Services\MigrationExport;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Independent fresh DB capture, followed by resumable filesystem-only CSV projection. */
final class FreshProductExport
{
    public const TYPES = ['czesci', 'sprzedane', 'do_wystawienia'];

    public function prepare(int $owner, string $type, string $mode): array
    {
        if (! in_array($type, self::TYPES, true) || ! in_array($mode, ['sample', 'full'], true)) {
            throw new MigrationExportException('invalid_export_selection', 422);
        }
        $id = (string) Str::uuid();
        $dir = $this->root().'/'.$id;
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new MigrationExportException('export_directory_unavailable');
        }
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->run();
        $commit = trim($process->getOutput());
        if (! $process->isSuccessful() || ! preg_match('/^[a-f0-9]{40}$/D', $commit)) {
            throw new MigrationExportException('source_commit_unavailable');
        }
        $state = ['export_id' => $id, 'owner_id' => $owner, 'type' => $type, 'mode' => $mode, 'schema_version' => ProductExportProjection::VERSION, 'source_commit' => $commit, 'state' => 'capturing', 'captured_at' => null, 'total' => 0, 'processed' => 0, 'images' => 0, 'listings' => 0, 'issues' => 0, 'capture_offset' => 0, 'offsets' => [], 'files' => [], 'sample_part_ids' => []];
        $this->save($dir.'/state.json', $state);

        return $this->reply($state);
    }

    private function runCapture(string $dir, array $state): array
    {
        try {
            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }
            $this->capture($dir, $state);
            $state['state'] = 'ready';
            $this->save($dir.'/state.json', $state);

            return $this->reply($state);
        } catch (\Throwable $e) {
            $state['state'] = 'capture_failed';
            $state['error'] = 'fresh_capture_failed_start_new_export';
            $this->save($dir.'/state.json', $state);
            throw $e;
        }
    }

    private function capture(string $dir, array &$state): void
    {
        $db = DB::connection();
        if ($db->transactionLevel() !== 0 && ! app()->environment('testing')) {
            throw new MigrationExportException('capture_requires_new_transaction');
        }
        $driver = $db->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new MigrationExportException('capture_driver_unsupported');
        }
        if ($driver === 'mysql') {
            $db->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        ReadOnlyDatabase::run(function () use ($dir, &$state, $driver, $db) {
            return $db->transaction(function () use ($dir, &$state, $driver, $db) {
                $tables = ['parts', 'cars', 'part_categories', 'storage_locations', 'part_images', 'marketplace_listings', 'marketplace_accounts', 'marketplace_category_mappings', 'allegro_shipping_rates', 'part_translations', 'category_translations'];
                $available = array_values(array_filter($tables, fn ($t) => Schema::hasTable($t)));
                foreach (array_slice($tables, 0, 6) as $required) {
                    if (! in_array($required, $available, true)) {
                        throw new MigrationExportException('capture_required_table_missing');
                    }
                }
                if ($driver === 'mysql') {
                    $engines = $db->table('information_schema.tables')->where('table_schema', $db->getDatabaseName())->whereIn('table_name', $available)->pluck('engine');
                    if ($engines->count() !== count($available) || $engines->contains(fn ($e) => strtoupper($e) !== 'INNODB')) {
                        throw new MigrationExportException('capture_requires_innodb');
                    }
                }
                $state['captured_at'] = $db->selectOne('SELECT CURRENT_TIMESTAMP AS capture_time')->capture_time;
                $state['consistency'] = $driver === 'mysql' ? 'repeatable_read_read_only_innodb' : 'sqlite_read_transaction';
                $schema = [];
                foreach ($available as $table) {
                    $schema[$table] = Schema::getColumnListing($table);
                }
                $this->save($dir.'/schema.json', $schema);
                $query = DB::table('parts');
                if ($state['type'] === 'sprzedane') {
                    $query->where('status', 'sold');
                }
                if ($state['type'] === 'do_wystawienia') {
                    $query->where('needs_listing', true);
                }
                if ($state['mode'] === 'sample') {
                    // Indexed correlated EXISTS avoid random selection and gallery/listing Cartesian products.
                    $score = "(CASE WHEN car_id IS NOT NULL THEN 2 ELSE 0 END + CASE WHEN category_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN storage_location_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN description IS NOT NULL AND description <> '' THEN 1 ELSE 0 END + CASE WHEN EXISTS (SELECT 1 FROM part_images i WHERE i.part_id = parts.id) THEN 2 ELSE 0 END + CASE WHEN (SELECT COUNT(*) FROM part_images i WHERE i.part_id = parts.id) >= 2 THEN 1 ELSE 0 END + CASE WHEN price IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN EXISTS (SELECT 1 FROM marketplace_listings l WHERE l.part_id = parts.id AND l.price IS NOT NULL) THEN 1 ELSE 0 END + CASE WHEN EXISTS (SELECT 1 FROM marketplace_listings l WHERE l.part_id = parts.id AND l.marketplace = 'ebay_de') THEN 2 ELSE 0 END + CASE WHEN EXISTS (SELECT 1 FROM marketplace_listings l WHERE l.part_id = parts.id AND l.marketplace LIKE 'ovoko%') THEN 2 ELSE 0 END)";
                    $ids = (clone $query)->orderByRaw($score.' DESC')->orderBy('id')->limit(5)->pluck('id')->all();
                    $state['sample_part_ids'] = array_map('strval', $ids);
                    $query->whereIn('id', $ids);
                }
                $state['total'] = (clone $query)->count();
                $state['capture_processed'] = 0;
                $this->save($dir.'/state.json', $state);
                $stream = fopen($dir.'/capture.pending', 'xb');
                chmod($dir.'/capture.pending', 0600);
                try {
                    $query->orderBy('id')->chunkById(100, function ($parts) use ($stream, $schema, $dir, &$state) {
                        $ids = $parts->pluck('id')->all();
                        $cars = $this->indexed('cars', $parts->pluck('car_id')->filter()->all());
                        $categories = $this->indexed('part_categories', $parts->pluck('category_id')->filter()->all());
                        $locations = $this->indexed('storage_locations', $parts->pluck('storage_location_id')->filter()->all());
                        $images = $this->rows('part_images', 'part_id', $ids);
                        $listings = $this->rows('marketplace_listings', 'part_id', $ids);
                        $accountIds = array_values(array_unique(array_filter(array_column($listings, 'marketplace_account_id'))));
                        $accounts = [];
                        if (isset($schema['marketplace_accounts'])) {
                            // Never read credentials. Only the marketplace ID is retained from settings.
                            $fields = array_values(array_intersect(['id', 'code', 'marketplace', 'api_settings'], $schema['marketplace_accounts']));
                            foreach (DB::table('marketplace_accounts')->whereIn('id', $accountIds)->get($fields) as $a) {
                                $a = (array) $a;
                                $settings = json_decode($a['api_settings'] ?? '{}', true) ?: [];
                                $a['api_settings'] = ['marketplace_id' => $settings['marketplace_id'] ?? null];
                                $accounts[(string) $a['id']] = $a;
                            }
                        }
                        $rates = isset($schema['allegro_shipping_rates']) ? $this->rows('allegro_shipping_rates', 'marketplace_account_id', $accountIds) : [];
                        $mappings = isset($schema['marketplace_category_mappings']) ? $this->rows('marketplace_category_mappings', 'local_category_id', array_keys($categories)) : [];
                        $translations = isset($schema['part_translations']) ? $this->rows('part_translations', 'part_id', $ids) : [];
                        $categoryTranslations = isset($schema['category_translations']) ? $this->rows('category_translations', 'category_id', array_keys($categories)) : [];
                        foreach ($parts as $part) {
                            $p = $this->cleanRow((array) $part);
                            $capture = ['part' => $p, 'car' => $cars[(string) ($p['car_id'] ?? '')] ?? null, 'category' => $categories[(string) ($p['category_id'] ?? '')] ?? null, 'location' => $locations[(string) ($p['storage_location_id'] ?? '')] ?? null, 'accounts' => $accounts, 'shipping_rates' => $rates,
                                'images' => array_values(array_filter($images, fn ($i) => (string) $i['part_id'] === (string) $p['id'])),
                                'listings' => array_values(array_filter($listings, fn ($l) => (string) $l['part_id'] === (string) $p['id'])),
                                'mappings' => array_values(array_filter($mappings, fn ($m) => (string) $m['local_category_id'] === (string) ($p['category_id'] ?? ''))),
                                'translations' => array_values(array_filter($translations, fn ($t) => (string) $t['part_id'] === (string) $p['id'])),
                                'category_translations' => array_values(array_filter($categoryTranslations, fn ($t) => (string) $t['category_id'] === (string) ($p['category_id'] ?? ''))),
                                'store_url' => $this->storeUrl($p)];
                            $bytes = json_encode($capture, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                            if (fwrite($stream, $bytes) !== strlen($bytes)) {
                                throw new MigrationExportException('capture_write_failed');
                            }
                            $state['capture_processed']++;
                        }
                        $this->save($dir.'/state.json', $state);
                    });
                    if (! fflush($stream)) {
                        throw new MigrationExportException('capture_flush_failed');
                    }
                } finally {
                    fclose($stream);
                }
            });
        });
        // Seal only after a successfully completed transaction. Never adopt partial captures.
        if (! rename($dir.'/capture.pending', $dir.'/capture.jsonl')) {
            throw new MigrationExportException('capture_seal_failed');
        }
        $state['capture_sha256'] = hash_file('sha256', $dir.'/capture.jsonl');
        $state['capture_finished_at'] = now()->toIso8601String();
    }

    private function cleanRow(array $row): array
    {
        // MySQL DECIMAL already arrives as text; SQLite test storage may return floats.
        foreach ($row as $field => &$value) {
            if ($value !== null && in_array($field, ['price', 'allegro_price', 'ovoko_price', 'ebay_price'], true) && ! is_string($value)) {
                $value = number_format((float) $value, 2, '.', '');
            }
        }
        unset($value);

        return MigrationDataPolicy::clean($row);
    }

    private function rows(string $table, string $key, array $ids): array
    {
        return DB::table($table)->whereIn($key, $ids)->orderBy('id')->get()->map(fn ($r) => $this->cleanRow((array) $r))->all();
    }

    private function indexed(string $table, array $ids): array
    {
        return array_column($this->rows($table, 'id', $ids), null, 'id');
    }

    private function storeUrl(array $part): ?string
    {
        $base = rtrim((string) config('app.url'), '/');
        $host = parse_url($base, PHP_URL_HOST);
        if (! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true) || ! isset(config('storefronts', [])[$host]) || (config('storefronts', [])[$host]['storefront_code'] ?? null) !== 'gpswiss_pl') {
            return null;
        }
        $slug = $part['slug'] ?: $part['id'];

        return $base.route('storefront.product', ['slug' => $slug], false);
    }

    public function status(int $owner, string $id): array
    {
        return $this->reply($this->load($this->directory($id), $owner));
    }

    public function action(int $owner, string $id, string $action): array
    {
        $dir = $this->directory($id);

        return $this->locked($dir, function () use ($dir, $owner, $action) {
            $s = $this->load($dir, $owner);
            if (! in_array($action, ['capture', 'tick', 'resume', 'stop'], true)) {
                throw new MigrationExportException('invalid_export_action', 422);
            }
            if ($action === 'capture') {
                if ($s['state'] !== 'capturing' || is_file($dir.'/capture.pending')) {
                    throw new MigrationExportException('capture_retry_requires_new_export');
                }

                return $this->runCapture($dir, $s);
            }
            if ($s['state'] === 'done') {
                return $this->reply($s);
            }
            if (in_array($s['state'], ['capture_failed', 'capturing'], true)) {
                throw new MigrationExportException('capture_not_ready');
            }
            if ($action === 'stop') {
                $s['state'] = 'stopped';
            } elseif ($action === 'resume') {
                if (! hash_equals($s['capture_sha256'], (string) hash_file('sha256', $dir.'/capture.jsonl'))) {
                    throw new MigrationExportException('capture_integrity_failed');
                }
                $s['state'] = 'ready';
            } elseif ($s['state'] !== 'stopped') {
                $this->emit($dir, $s);
            }
            $this->save($dir.'/state.json', $s);

            return $this->reply($s);
        });
    }

    private function emit(string $dir, array &$s): void
    {
        $definitions = [$s['type'].'.csv' => ProductExportProjection::columns(), 'zdjecia_czesci.csv' => ProductExportProjection::PHOTOS, 'marketplace_listingi_czesci.csv' => ProductExportProjection::RELATIONS, 'issues.csv' => ['part_id', 'reason_code', 'details'], 'relacje_czesci.csv' => ['part_id', 'car', 'category', 'location', 'mappings', 'translations', 'category_translations']];
        foreach ($definitions as $name => $columns) {
            $stream = fopen($dir.'/'.$name, 'c+b');
            chmod($dir.'/'.$name, 0600);
            $offset = $s['offsets'][$name] ?? 0;
            if (fstat($stream)['size'] < $offset || ! ftruncate($stream, $offset)) {
                fclose($stream);
                throw new MigrationExportException('output_truncated');
            }
            fclose($stream);
            if (! $offset) {
                $this->append($dir, $s, $name, "\xEF\xBB\xBF".$this->csv($columns));
            }
        }
        $capture = fopen($dir.'/capture.jsonl', 'rb');
        try {
            fseek($capture, $s['capture_offset']);
            for ($n = 0; $n < 25 && ($line = fgets($capture)) !== false; $n++) {
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                $out = ProductExportProjection::project($data);
                foreach ([$s['type'].'.csv' => [$out['part']], 'zdjecia_czesci.csv' => $out['photos'], 'marketplace_listingi_czesci.csv' => $out['listings'], 'issues.csv' => $out['issues'], 'relacje_czesci.csv' => [['part_id' => $data['part']['id']] + $data]] as $name => $rows) {
                    foreach ($rows as $row) {
                        $this->append($dir, $s, $name, $this->csv(array_map(fn ($c) => $row[$c] ?? null, $definitions[$name])));
                    }
                }
                $s['processed']++;
                $s['images'] += count($out['photos']);
                $s['listings'] += count($out['listings']);
                $s['issues'] += count($out['issues']);
                $s['capture_offset'] = ftell($capture);
            }
            if (feof($capture)) {
                if ($s['processed'] !== $s['total']) {
                    throw new MigrationExportException('capture_count_mismatch');
                }
                $s['state'] = 'done';
                foreach ($definitions as $name => $columns) {
                    $s['files'][$name] = ['sha256' => hash_file('sha256', $dir.'/'.$name), 'bytes' => filesize($dir.'/'.$name)];
                }
                $this->save($dir.'/manifest.json', array_intersect_key($s, array_flip(['export_id', 'type', 'mode', 'schema_version', 'source_commit', 'captured_at', 'capture_finished_at', 'consistency', 'capture_sha256', 'total', 'images', 'listings', 'issues', 'files', 'sample_part_ids'])) + ['columns' => $definitions, 'null' => '\\N', 'delimiter' => ';', 'encoding' => 'UTF-8 BOM', 'store_url_contract' => 'configured PL app.url + storefront.product; URL identity does not imply current availability']);
                $s['files']['manifest.json'] = ['sha256' => hash_file('sha256', $dir.'/manifest.json'), 'bytes' => filesize($dir.'/manifest.json')];
            }
        } finally {
            fclose($capture);
        }
    }

    public function download(int $owner, string $id, string $file): string
    {
        $dir = $this->directory($id);
        $s = $this->load($dir, $owner);
        if ($s['state'] !== 'done' || ! isset($s['files'][$file])) {
            throw new MigrationExportException('export_file_unavailable', 404);
        }
        if (! hash_equals($s['files'][$file]['sha256'], (string) hash_file('sha256', $dir.'/'.$file))) {
            throw new MigrationExportException('export_file_integrity_failed');
        }

        return $dir.'/'.$file;
    }

    private function csv(array $values): string
    {
        $f = fopen('php://temp', 'w+b');
        fputcsv($f, array_map(function ($v) {
            if ($v === null) {
                return '\\N';
            }
            $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
            if (str_starts_with($v, '\\')) {
                $v = '\\'.$v;
            }

            return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $v) ? "'".$v : $v;
        }, $values), ';', '"', '');
        rewind($f);
        $bytes = stream_get_contents($f);
        fclose($f);

        return $bytes;
    }

    private function append(string $dir, array &$s, string $name, string $bytes): void
    {
        if (file_put_contents($dir.'/'.$name, $bytes, FILE_APPEND) !== strlen($bytes)) {
            throw new MigrationExportException('csv_write_failed');
        }
        $s['offsets'][$name] = ($s['offsets'][$name] ?? 0) + strlen($bytes);
    }

    private function root(): string
    {
        return config('migration_export.product_root', storage_path('app/private/product-migration-exports'));
    }

    private function directory(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new MigrationExportException('invalid_export_id', 404);
        }
        $dir = $this->root().'/'.$id;
        if (! is_dir($dir)) {
            throw new MigrationExportException('export_not_found', 404);
        }

return $dir;
    }

    private function load(string $dir, int $owner): array
    {
        $s = json_decode(file_get_contents($dir.'/state.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($s['owner_id'] !== $owner) {
            throw new MigrationExportException('export_not_found', 404);
        }

return $s;
    }

    private function reply(array $s): array
    {
        return array_diff_key($s, array_flip(['owner_id', 'offsets', 'capture_offset']));
    }

    private function save(string $path, array $value): void
    {
        $bytes = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path.'.tmp', $bytes) !== strlen($bytes) || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
            throw new MigrationExportException('checkpoint_write_failed');
        }
    }

    private function locked(string $dir, callable $work): mixed
    {
        $f = fopen($dir.'/lock', 'c');
        chmod($dir.'/lock', 0600);
        if (! flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            throw new MigrationExportException('export_busy');
        }
        try {
            return $work();
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }
}
