<?php

namespace App\Services\Imports;

use App\Models\ImportSession;
use App\Models\ImportStagingRow;
use App\Services\Imports\Exceptions\ImportSessionNotStageableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportStageService
{
    public function __construct(
        private ImportTemplateRegistry $templateRegistry,
        private CsvImportParser $csvImportParser,
        private XlsxImportParser $xlsxImportParser,
        private StagingWriter $stagingWriter,
    ) {}

    /**
     * @return array{
     *     session_id: int,
     *     status: string,
     *     rows_total: int,
     *     rows_valid: int,
     *     rows_invalid: int,
     *     structural_errors?: list<string>
     * }
     */
    public function stage(ImportSession $session): array
    {
        if (! in_array($session->status, [
            ImportSession::STATUS_UPLOADED,
            ImportSession::STATUS_VALIDATION_FAILED,
        ], true)) {
            throw new ImportSessionNotStageableException(
                'Import session cannot be staged from status: '.$session->status
            );
        }

        return DB::transaction(function () use ($session) {
            $session->refresh();

            $session->update([
                'status' => ImportSession::STATUS_VALIDATING,
                'rows_total' => 0,
                'rows_valid' => 0,
                'rows_invalid' => 0,
                'validation_errors_count' => 0,
                'started_at' => $session->started_at ?? now(),
            ]);

            $template = $this->templateRegistry->get($session->template_version);
            if ($template === null) {
                return $this->failStructural($session, ['Unknown template_version: '.$session->template_version]);
            }

            $extension = $this->resolveExtension($session);
            if ($extension === null) {
                return $this->failStructural($session, ['Could not determine file type (csv/xlsx) from filename or mime type.']);
            }

            if (! in_array($extension, $template['allowed_extensions'], true)) {
                return $this->failStructural($session, [
                    'File type .'.$extension.' is not allowed for template '.$session->template_version.'.',
                ]);
            }

            $absolutePath = Storage::disk($session->file_disk)->path($session->file_path);
            if (! is_readable($absolutePath)) {
                return $this->failStructural($session, ['Uploaded file is not readable from storage.']);
            }

            try {
                $parseResult = $extension === 'csv'
                    ? $this->csvImportParser->parse($absolutePath, $template)
                    : $this->xlsxImportParser->parse($absolutePath, $template);
            } catch (\Throwable $e) {
                return $this->failStructural($session, ['Parse error: '.$e->getMessage()]);
            }

            if (! $parseResult->structuralOk) {
                return $this->failStructural($session, $parseResult->structuralErrors);
            }

            $this->stagingWriter->clearStagingForSession($session);

            $insertRows = [];
            $rowsValid = 0;
            $rowsInvalid = 0;
            $validationMessageCount = 0;

            foreach ($parseResult->rows as $row) {
                $rowErrors = $this->rowStructuralErrors($template, $row['data']);
                $ok = $rowErrors === [];
                if ($ok) {
                    $rowsValid++;
                } else {
                    $rowsInvalid++;
                    $validationMessageCount += count($rowErrors);
                }

                $extKey = $this->scalarFromColumn($row['data'], $template['external_key_column']);
                $parentKey = $template['parent_external_key_column'] !== null
                    ? $this->scalarFromColumn($row['data'], $template['parent_external_key_column'])
                    : null;

                $insertRows[] = [
                    'import_session_id' => $session->id,
                    'entity_type' => $template['entity_type'],
                    'sheet_name' => $row['sheet_name'],
                    'row_number' => $row['row_number'],
                    'external_key' => $extKey,
                    'parent_external_key' => $parentKey,
                    'payload_json' => [
                        'data' => $row['data'],
                        'raw' => $row['raw'],
                    ],
                    'validation_status' => $ok ? ImportStagingRow::VALIDATION_OK : ImportStagingRow::VALIDATION_INVALID,
                    'validation_errors_json' => $ok ? null : $rowErrors,
                ];
            }

            $this->stagingWriter->bulkInsertStagingRowArrays($insertRows);

            $session->update([
                'status' => ImportSession::STATUS_STAGED,
                'rows_total' => count($insertRows),
                'rows_valid' => $rowsValid,
                'rows_invalid' => $rowsInvalid,
                'validation_errors_count' => $validationMessageCount,
                'finished_at' => now(),
            ]);

            return [
                'session_id' => $session->id,
                'status' => $session->fresh()->status,
                'rows_total' => count($insertRows),
                'rows_valid' => $rowsValid,
                'rows_invalid' => $rowsInvalid,
            ];
        });
    }

    /**
     * @param  list<string>  $errors
     * @return array{session_id: int, status: string, rows_total: int, rows_valid: int, rows_invalid: int, structural_errors: list<string>}
     */
    private function failStructural(ImportSession $session, array $errors): array
    {
        $this->stagingWriter->clearStagingForSession($session);

        $session->update([
            'status' => ImportSession::STATUS_VALIDATION_FAILED,
            'rows_total' => 0,
            'rows_valid' => 0,
            'rows_invalid' => 0,
            'validation_errors_count' => count($errors),
            'finished_at' => now(),
        ]);

        return [
            'session_id' => $session->id,
            'status' => ImportSession::STATUS_VALIDATION_FAILED,
            'rows_total' => 0,
            'rows_valid' => 0,
            'rows_invalid' => 0,
            'structural_errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string|null>  $data
     * @return list<array{code: string, message: string}>
     */
    private function rowStructuralErrors(array $template, array $data): array
    {
        $errors = [];
        $extCol = $template['external_key_column'];
        $extVal = $data[$extCol] ?? null;
        if ($extVal === null || trim((string) $extVal) === '') {
            $errors[] = [
                'code' => 'missing_external_key',
                'message' => 'Missing or empty value for '.$extCol,
            ];
        }

        if ($template['parent_external_key_column'] !== null) {
            $pCol = $template['parent_external_key_column'];
            $pVal = $data[$pCol] ?? null;
            if ($pVal === null || trim((string) $pVal) === '') {
                $errors[] = [
                    'code' => 'missing_parent_external_key',
                    'message' => 'Missing or empty value for '.$pCol,
                ];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string|null>  $data
     */
    private function scalarFromColumn(array $data, string $column): ?string
    {
        $v = $data[$column] ?? null;
        if ($v === null) {
            return null;
        }
        $t = trim((string) $v);

        return $t === '' ? null : $t;
    }

    private function resolveExtension(ImportSession $session): ?string
    {
        $ext = strtolower(pathinfo($session->original_filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'xlsx'], true)) {
            return $ext;
        }

        return match ($session->mime_type) {
            'text/csv', 'text/plain', 'application/csv' => 'csv',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => null,
        };
    }
}
