<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class XlsxImportParser
{
    /**
     * @param  array{
     *     sheet_names: list<string>,
     *     required_headers: list<string>,
     * }  $template
     */
    public function parse(string $absolutePath, array $template): ImportParseResult
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolutePath);
        } catch (\Throwable $e) {
            return new ImportParseResult(false, ['Could not read XLSX file: '.$e->getMessage()], []);
        }

        try {
            $sheet = $this->resolveSheet($spreadsheet->getAllSheets(), $template['sheet_names']);
            if ($sheet === null) {
                $expected = implode(', ', $template['sheet_names']);

                return new ImportParseResult(false, ['Missing required worksheet. Expected one of: '.$expected.'.'], []);
            }

            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();
            if ($highestRow < 1) {
                return new ImportParseResult(false, ['Worksheet is empty.'], []);
            }

            $headerRow = [];
            $maxCol = Coordinate::columnIndexFromString($highestCol);
            for ($c = 1; $c <= $maxCol; $c++) {
                $coord = Coordinate::stringFromColumnIndex($c).'1';
                $cell = $sheet->getCell($coord);
                $headerRow[] = $this->stringifyCellValue($cell);
            }

            /** @var list<string> $rawHeaders */
            $rawHeaders = array_map(static fn ($h) => (string) $h, $headerRow);
            $normPerCol = [];
            foreach ($rawHeaders as $i => $raw) {
                $normPerCol[$i] = ImportHeaderNormalizer::normalize($raw);
            }

            $headerErrors = $this->validateRequiredHeaders($normPerCol, $template['required_headers']);
            if ($headerErrors !== []) {
                return new ImportParseResult(false, $headerErrors, []);
            }

            $rows = [];
            for ($r = 2; $r <= $highestRow; $r++) {
                $line = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $coord = Coordinate::stringFromColumnIndex($c).$r;
                    $cell = $sheet->getCell($coord);
                    $line[] = $this->stringifyCellValue($cell);
                }

                if ($this->isRowEmpty($line)) {
                    continue;
                }

                $built = $this->buildRowAssoc($rawHeaders, $normPerCol, $line);
                $rows[] = [
                    'sheet_name' => $sheet->getTitle(),
                    'row_number' => $r,
                    'raw' => $built['raw'],
                    'data' => $built['data'],
                ];
            }

            return new ImportParseResult(true, [], $rows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  Worksheet[]  $sheets
     * @param  list<string>  $expectedNames
     */
    private function resolveSheet(array $sheets, array $expectedNames): ?Worksheet
    {
        foreach ($expectedNames as $expected) {
            foreach ($sheets as $sheet) {
                if (strcasecmp(trim($sheet->getTitle()), trim($expected)) === 0) {
                    return $sheet;
                }
            }
        }

        return null;
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
            $cell = $line[$i] ?? null;
            $trimmed = trim((string) $cell);
            $value = $trimmed === '' ? null : $trimmed;
            $raw[$label] = $value;
            if ($norm !== '' && ! array_key_exists($norm, $data)) {
                $data[$norm] = $value;
            }
        }

        return ['raw' => $raw, 'data' => $data];
    }

    /**
     * @param  list<string|null>  $line
     */
    private function isRowEmpty(array $line): bool
    {
        foreach ($line as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stringifyCellValue(Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return trim((string) $value);
    }
}
