<?php

namespace App\Services\ImportMigration;

use RuntimeException;
use SplFileObject;

class CsvReader
{
    /** @return \Generator<int, array<string, string|null>> */
    public function rows(string $path): \Generator
    {
        yield from $this->rowsFromLine($path, 2);
    }

    /** @return \Generator<int, array<string, string|null>> */
    public function rowsFromLine(string $path, int $startLine = 2): \Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        $file = new SplFileObject($path, 'rb');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(',', '"', '\\');

        $headers = null;
        foreach ($file as $index => $row) {
            $line = $index + 1;

            if ($row === false || $row === [null]) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(fn ($header) => trim((string) $header), $row);
                continue;
            }

            if ($line < $startLine) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $row[$i] ?? null;
            }
            $assoc['_row_number'] = (string) $line;

            yield $line => $assoc;
        }
    }
}
