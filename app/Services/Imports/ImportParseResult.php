<?php

namespace App\Services\Imports;

/**
 * Outcome of structural parse (headers / sheet) plus data rows.
 */
final class ImportParseResult
{
    /**
     * @param  list<string>  $structuralErrors
     * @param  list<array{
     *     sheet_name: string|null,
     *     row_number: int,
     *     raw: array<string, string|null>,
     *     data: array<string, string|null>
     * }>  $rows
     */
    public function __construct(
        public bool $structuralOk,
        public array $structuralErrors,
        public array $rows,
    ) {}
}
