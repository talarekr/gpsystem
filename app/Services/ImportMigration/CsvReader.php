<?php

namespace App\Services\ImportMigration;

class CsvReader
{
    /** @return \Generator<int, array<string, string|null>> */
    public function rows(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV: {$path}");
        }
        $headers = null; $line = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($headers === null) {
                $headers = array_map(fn ($h) => trim((string) $h), $row);
                continue;
            }
            if ($row === [null] || $row === false) continue;
            $assoc = [];
            foreach ($headers as $i => $header) $assoc[$header] = $row[$i] ?? null;
            $assoc['_row_number'] = (string) $line;
            yield $line => $assoc;
        }
        fclose($handle);
    }
}
