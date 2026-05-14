<?php

namespace App\Services\UserAccount;

use App\Mail\DataExportReady;
use App\Models\DataExportRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DataExportService
{
    private const STORAGE_DIR = 'data-exports';

    /**
     * Build the ZIP synchronously. For ZULU's current scale (small per-user
     * dataset) a synchronous build is fine; if exports get large we'll move
     * this into a queued job.
     */
    public function generate(User $user): DataExportRequest
    {
        $request = DataExportRequest::query()->create([
            'user_id' => $user->id,
            'status' => DataExportRequest::STATUS_GENERATING,
        ]);

        try {
            $payload = $this->collect($user);
            $filename = sprintf('zulu-data-%d-%s.zip', $user->id, now()->format('Ymd-His'));
            $relativePath = self::STORAGE_DIR.'/'.$filename;
            $absolutePath = Storage::disk('local')->path($relativePath);
            @mkdir(dirname($absolutePath), 0775, true);

            $zip = new ZipArchive;
            if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open ZIP for writing');
            }

            $zip->addFromString('README.txt', $this->buildReadme($user));
            $zip->addFromString('profile.json', $this->jsonPretty($payload['profile']));
            $zip->addFromString('bookings.json', $this->jsonPretty($payload['bookings']));
            $zip->addFromString('vouchers.json', $this->jsonPretty($payload['vouchers']));
            $zip->addFromString('reviews.json', $this->jsonPretty($payload['reviews']));
            $zip->addFromString('favorites.json', $this->jsonPretty($payload['favorites']));
            $zip->addFromString('saved_items.json', $this->jsonPretty($payload['saved_items']));
            $zip->addFromString('notifications.json', $this->jsonPretty($payload['notifications']));
            $zip->addFromString('loyalty.json', $this->jsonPretty($payload['loyalty']));
            $zip->addFromString('login_history.json', $this->jsonPretty($payload['login_history']));
            $zip->addFromString('support_tickets.json', $this->jsonPretty($payload['support_tickets']));
            $zip->addFromString('payment_methods.json', $this->jsonPretty($payload['payment_methods']));
            $zip->addFromString('company_memberships.json', $this->jsonPretty($payload['company_memberships']));
            // B2B-specific (agent / operator) sections — empty arrays when N/A.
            $zip->addFromString('commissions.json', $this->jsonPretty($payload['commissions']));
            $zip->addFromString('contracts.json', $this->jsonPretty($payload['contracts']));
            $zip->close();

            $size = filesize($absolutePath) ?: 0;
            $token = Str::random(64);

            $request->update([
                'status' => DataExportRequest::STATUS_READY,
                'download_token' => $token,
                'file_size_bytes' => $size,
                'file_path' => $relativePath,
                'ready_at' => now(),
                'expires_at' => now()->addDays(DataExportRequest::LINK_LIFETIME_DAYS),
            ]);

            try {
                Mail::to($user->email)->queue(new DataExportReady($user, $token));
            } catch (\Throwable $e) {
                // Mail failure leaves the download usable via the in-app
                // notification; just log.
                Log::warning('DataExport email failed to queue', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            $request->update([
                'status' => DataExportRequest::STATUS_FAILED,
                'failure_reason' => substr($e->getMessage(), 0, 500),
            ]);
            Log::error('DataExport generation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $request->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(User $user): array
    {
        $userId = $user->id;

        return [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'preferred_language' => $user->preferred_language,
                'avatar' => $user->avatar,
                'birth_date' => optional($user->birth_date)->toDateString(),
                'nationality' => $user->nationality,
                'status' => $user->status,
                'email_verified_at' => optional($user->email_verified_at)->toIso8601String(),
                'created_at' => optional($user->created_at)->toIso8601String(),
                'updated_at' => optional($user->updated_at)->toIso8601String(),
            ],
            'bookings' => $this->table('orders', ['user_id' => $userId]),
            'vouchers' => $this->table('vouchers', ['user_id' => $userId]),
            'reviews' => $this->table('reviews', ['user_id' => $userId]),
            'favorites' => $this->table('user_favorites', ['user_id' => $userId]),
            'saved_items' => $this->table('saved_items', ['user_id' => $userId]),
            'notifications' => $this->table('notifications', ['user_id' => $userId]),
            'loyalty' => [
                'transactions' => $this->table('loyalty_transactions', ['user_id' => $userId]),
                'tier_history' => $this->table('loyalty_tier_history', ['user_id' => $userId]),
            ],
            'login_history' => $this->table('login_history', ['user_id' => $userId]),
            'support_tickets' => $this->table('support_tickets', ['user_id' => $userId]),
            'payment_methods' => $this->table('payment_methods', ['user_id' => $userId]),
            'company_memberships' => $this->table('user_company', ['user_id' => $userId]),
            'commissions' => $this->table('commission_records', ['user_id' => $userId]),
            'contracts' => $this->table('contracts', ['signed_by_user_id' => $userId]),
        ];
    }

    /**
     * Defensive table read — silently returns an empty array if the table
     * or column doesn't exist (e.g. an optional B2B-only table). Keeps the
     * export endpoint working across schema drift.
     *
     * @param  array<string, mixed>  $where
     * @return array<int, array<string, mixed>>
     */
    private function table(string $tableName, array $where): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable($tableName)) {
                return [];
            }
            foreach (array_keys($where) as $col) {
                if (! DB::getSchemaBuilder()->hasColumn($tableName, $col)) {
                    return [];
                }
            }

            return DB::table($tableName)->where($where)->get()->map(fn ($row) => (array) $row)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function jsonPretty(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildReadme(User $user): string
    {
        $now = now()->toIso8601String();

        return <<<TXT
ZULU — Your personal data export
================================

Generated: {$now}
For: {$user->email}

This archive contains every record we hold that is associated with your
ZULU account. Each JSON file maps 1:1 to a backend table:

  profile.json             — your profile row
  bookings.json            — every order you placed
  vouchers.json            — vouchers issued to you
  reviews.json             — reviews you posted
  favorites.json           — hearted offers
  saved_items.json         — saved listings
  notifications.json       — in-app notifications
  loyalty.json             — loyalty points + tier history
  login_history.json       — when you signed in and from where
  support_tickets.json     — support tickets you opened
  payment_methods.json     — card / wallet metadata (no full PAN stored)
  company_memberships.json — companies you belong to (agent / operator)
  commissions.json         — commission records (B2B)
  contracts.json           — contracts you signed (B2B)

If you have questions, reply to your usual ZULU support contact and
quote this filename.
TXT;
    }
}
