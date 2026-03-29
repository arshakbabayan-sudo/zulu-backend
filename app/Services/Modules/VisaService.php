<?php

namespace App\Services\Modules;

use App\Models\Visa;
use App\Models\VisaApplication;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisaService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function submitApplication(int $userId, int $visaId, array $data, array $files): VisaApplication
    {
        Visa::query()->findOrFail($visaId);

        $validator = Validator::make($data, [
            'passport_number' => ['required', 'string', 'max:100'],
            'entry_date' => ['nullable', 'date'],
            'exit_date' => ['nullable', 'date', 'after_or_equal:entry_date'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $storedFiles = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $storedFiles[] = $file->store('visas/applications', 'public');
            }
        }

        $application = VisaApplication::query()->create([
            'user_id' => $userId,
            'visa_id' => $visaId,
            'status' => 'pending',
            'passport_number' => (string) $data['passport_number'],
            'entry_date' => $data['entry_date'] ?? null,
            'exit_date' => $data['exit_date'] ?? null,
            'files' => $storedFiles,
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        return $application->fresh(['visa', 'user']);
    }

    public function updateStatus(int $applicationId, string $status, ?string $note = null): bool
    {
        $validator = Validator::make(
            ['status' => $status],
            ['status' => ['required', Rule::in(['pending', 'processing', 'approved', 'rejected'])]]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $application = VisaApplication::query()->findOrFail($applicationId);

        $payload = ['status' => $status];
        if ($note !== null) {
            $payload['admin_notes'] = $note;
        }

        return $application->update($payload);
    }

    public function listApplications(?int $userId = null): Collection
    {
        return VisaApplication::query()
            ->with(['user:id,name,email', 'visa:id,country_id,country,name,visa_type'])
            ->when($userId !== null, fn($q) => $q->where('user_id', $userId))
            ->latest('id')
            ->get();
    }
}
