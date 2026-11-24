<?php

namespace App\Domain\Import;

use DateTimeInterface;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use RuntimeException;

/**
 * Reads an .xlsx or .csv into ['Works' => [[rowNumber, ['code' => ..., ...]], ...], ...].
 * A CSV is treated as a single Works sheet. Blank rows are skipped.
 */
class WorkbookReader
{
    public const MAX_ROWS = 20000;

    /** @return array<string, list<array{0:int, 1:array<string, mixed>}>> */
    public function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            throw new RuntimeException('Upload an .xlsx or .csv file.');
        }

        // ReaderFactory picks the reader from the extension, so give it one.
        $typed = $path.'.'.$ext;
        if (! @copy($path, $typed)) {
            throw new RuntimeException('Could not read the uploaded file.');
        }

        try {
            $reader = ReaderFactory::createFromFile($typed);
            $reader->open($typed);
            $out = [];
            $total = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                $name = $ext === 'csv' ? 'Works' : Template::canonicalSheet($sheet->getName());
                if ($name === null) {
                    continue; // e.g. a "Read me" sheet
                }
                $headers = null;
                $rowNo = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNo++;
                    $values = array_map(fn ($v) => $this->clean($v), $row->toArray());
                    if ($headers === null) {
                        $headers = array_map(fn ($h) => Template::normaliseHeader((string) $h), $values);

                        continue;
                    }
                    if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    if (++$total > self::MAX_ROWS) {
                        throw new RuntimeException('The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
                    }
                    $assoc = [];
                    foreach ($headers as $i => $h) {
                        if ($h !== '') {
                            $assoc[$h] = $values[$i] ?? null;
                        }
                    }
                    $out[$name][] = [$rowNo, $assoc];
                }
            }
            $reader->close();

            return $out;
        } finally {
            @unlink($typed);
        }
    }

    private function clean(mixed $v): mixed
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_string($v)) {
            $v = trim($v);

            return $v === '' ? null : $v;
        }

        return $v;
    }
}
