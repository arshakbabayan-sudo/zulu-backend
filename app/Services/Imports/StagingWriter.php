<?php

namespace App\Services\Imports;

use App\Models\ImportSession;
use App\Models\ImportStagingRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StagingWriter
{
    private const INSERT_CHUNK = 250;

    /**
     * @param  list<array<string, mixed>>  $normalizedRows
     */
    public function bulkWriteChunk(ImportSession $session, array $normalizedRows): void
    {
        if ($normalizedRows === []) {
            return;
        }
    }

    /**
     * @param  Collection<int, ImportStagingRow>|list<ImportStagingRow>  $rows
     */
    public function bulkInsertModels(ImportSession $session, Collection|array $rows): void
    {
        $collection = $rows instanceof Collection ? $rows : collect($rows);
        if ($collection->isEmpty()) {
            return;
        }

        $arrays = [];
        foreach ($collection as $model) {
            if (! $model instanceof ImportStagingRow) {
                continue;
            }
            $arrays[] = $this->modelToInsertArray($model);
        }
        $this->bulkInsertStagingRowArrays($arrays);
    }

    /**
     * @param  list<array{
     *     import_session_id: int,
     *     entity_type: string,
     *     sheet_name: string|null,
     *     row_number: int,
     *     external_key: string|null,
     *     parent_external_key: string|null,
     *     payload_json: array<string, mixed>,
     *     validation_status: string,
     *     validation_errors_json: list<array<string, string>>|null
     * }>  $rows
     */
    public function bulkInsertStagingRowArrays(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now()->toDateTimeString();
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $payload = [];
            foreach ($chunk as $row) {
                $payload[] = [
                    'import_session_id' => $row['import_session_id'],
                    'entity_type' => $row['entity_type'],
                    'sheet_name' => $row['sheet_name'],
                    'row_number' => $row['row_number'],
                    'external_key' => $row['external_key'],
                    'parent_external_key' => $row['parent_external_key'],
                    'payload_json' => json_encode($row['payload_json']),
                    'validation_status' => $row['validation_status'],
                    'validation_errors_json' => isset($row['validation_errors_json']) && $row['validation_errors_json'] !== null
                        ? json_encode($row['validation_errors_json'])
                        : null,
                    'commit_status' => ImportStagingRow::COMMIT_PENDING,
                    'commit_errors_json' => null,
                    'committed_entity_id' => null,
                    'payload_hash' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('import_staging_rows')->insert($payload);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function modelToInsertArray(ImportStagingRow $model): array
    {
        return [
            'import_session_id' => (int) $model->import_session_id,
            'entity_type' => (string) $model->entity_type,
            'sheet_name' => $model->sheet_name,
            'row_number' => (int) $model->row_number,
            'external_key' => $model->external_key,
            'parent_external_key' => $model->parent_external_key,
            'payload_json' => $model->payload_json ?? [],
            'validation_status' => (string) $model->validation_status,
            'validation_errors_json' => $model->validation_errors_json,
        ];
    }

    /**
     * Optional hook to reset idempotency markers for a session before re-staging.
     */
    public function clearStagingForSession(ImportSession $session): void
    {
        $session->stagingRows()->delete();
    }
}
