<?php

namespace App\Services\Statuses;

use App\Models\Status;

class StatusService
{
    /**
     * @param  array{entity_type:string,name:string,code:string}  $data
     */
    public function create(array $data): Status
    {
        return Status::query()->create($data);
    }

    public function findByCode(string $entity_type, string $code): ?Status
    {
        return Status::query()
            ->where('entity_type', $entity_type)
            ->where('code', $code)
            ->first();
    }
}
