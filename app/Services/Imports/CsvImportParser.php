<?php

namespace App\Services\Imports;

class CsvImportParser
{
    /**
     * @param  array{
     *     required_headers: list<string>,
     * }  $template
     */
    public function parse(string $absolutePath, array $template): ImportParseResult
    {
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            return new ImportParseResult(false, ['Could not open CSV file for reading.'], []);
        }

        try {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $headerLine = fgetcsv($handle);
            if ($headerLine === false || $headerLine === [null] || $headerLine === []) {
                return new ImportParseResult(false, ['CSV file has no header row.'], []);
            }

            /** @var list<string> $rawHeaders */
            $rawHeaders = array_map(static fn ($h) => (string) $h, $headerLine);
            $normPerCol = [];
            foreach ($rawHeaders as $i => $raw) {
                $normPerCol[$i] = ImportHeaderNormalizer::normalize($raw);
            }

            $headerErrors = $this->validateRequiredHeaders($normPerCol, $template['required_headers']);
            if ($headerErrors !== []) {
                return new ImportParseResult(false, $headerErrors, []);
            }

            $rows = [];
            $rowNumber = 2;
            while (($line = fgetcsv($handle)) !== false) {
                if ($this->isCsvRowEmpty($line)) {
                    $rowNumber++;

                    continue;
                }

                $built = $this->buildRowAssoc($rawHeaders, $normPerCol, $line);
                $rows[] = [
                    'sheet_name' => null,
                    'row_number' => $rowNumber,
                    'raw' => $built['raw'],
                    'data' => $built['data'],
                ];
                $rowNumber++;
            }

            return new ImportParseResult(true, [], $rows);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string|null>  $line
     */
    private function isCsvRowEmpty(array $line): bool
    {
        foreach ($line as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<int, string>  $normPerCol
     * @param  list<string>  $required
     * @return list<string>
     */
    private function validateRequiredHeaders(array $normPerCol, array $required): array
    {
        $present = [];
        foreach ($normPerCol as $norm) {
            if ($norm !== '') {
                $present[$norm] = true;
            }
        }

        $errors = [];
        foreach ($required as $req) {
            if (! isset($present[$req])) {
                $errors[] = 'Missing required column: '.$req;
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $rawHeaders
     * @param  list<int, string>  $normPerCol
     * @param  list<string|null>  $line
     * @return array{raw: array<string, string|null>, data: array<string, string|null>}
     */
    private function buildRowAssoc(array $rawHeaders, array $normPerCol, array $line): array
    {
        $raw = [];
        $data = [];
        $count = max(count($rawHeaders), count($line));
        for ($i = 0; $i < $count; $i++) {
            $label = $rawHeaders[$i] ?? 'column_'.$i;
            $norm = $normPerCol[$i] ?? '';
            $cell = array_key_exists($i, $line) ? $line[$i] : null;
            $trimmed = trim((string) $cell);
            $value = $trimmed === '' ? null : $trimmed;
            $raw[$label] = $value;
            if ($norm !== '' && ! array_key_exists($norm, $data)) {
                $data[$norm] = $value;
            }
        }

        return ['raw' => $raw, 'data' => $data];
    }
}
