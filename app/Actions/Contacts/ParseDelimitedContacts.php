<?php

namespace App\Actions\Contacts;

/**
 * Parses delimited contact text (CSV file contents or pasted rows) into a
 * header row and data rows. The delimiter is auto-detected from the first line.
 */
class ParseDelimitedContacts
{
    /**
     * @return array{header: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function handle(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            return ['header' => [], 'rows' => []];
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return ['header' => [], 'rows' => []];
        }

        $delimiter = $this->detectDelimiter($lines[0]);

        $rows = array_map(
            fn (string $line): array => array_map('trim', str_getcsv($line, $delimiter, '"', '\\')),
            $lines,
        );

        $header = array_shift($rows);

        return [
            'header' => $header,
            'rows' => array_values($rows),
        ];
    }

    private function detectDelimiter(string $line): string
    {
        foreach (["\t", ',', ';', '|'] as $delimiter) {
            if (str_contains($line, $delimiter)) {
                return $delimiter;
            }
        }

        return ',';
    }
}
