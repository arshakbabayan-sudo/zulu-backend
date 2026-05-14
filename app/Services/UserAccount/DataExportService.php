<?php

namespace App\Services\UserAccount;

use App\Mail\DataExportReady;
use App\Models\DataExportRequest;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Generates the GDPR data-export ZIP. Each section becomes a standalone
 * PDF the user can open in any reader — JSON dumps were correct from a
 * compliance standpoint but useless for non-technical users.
 *
 * Section PDFs are rendered from `resources/views/pdf/data-export/section.blade.php`
 * via dompdf. README.txt explains the archive layout in plain English.
 */
class DataExportService
{
    private const STORAGE_DIR = 'data-exports';

    public function generate(User $user): DataExportRequest
    {
        $request = DataExportRequest::query()->create([
            'user_id' => $user->id,
            'status' => DataExportRequest::STATUS_GENERATING,
        ]);

        try {
            $filename = sprintf('zulu-data-%d-%s.zip', $user->id, now()->format('Ymd-His'));
            $relativePath = self::STORAGE_DIR.'/'.$filename;
            $absolutePath = Storage::disk('local')->path($relativePath);
            @mkdir(dirname($absolutePath), 0775, true);

            $zip = new ZipArchive;
            if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open ZIP for writing');
            }

            $generatedAt = now()->toDateTimeString();
            $userName = $user->name ?: '';
            $userEmail = $user->email ?: '';

            foreach ($this->sections($user) as $file => $section) {
                $pdf = Pdf::loadView('pdf.data-export.section', [
                    'sectionTitle' => $section['title'],
                    'sectionSubtitle' => $section['subtitle'],
                    'rows' => $section['rows'],
                    'columns' => $section['columns'] ?? [],
                    'isKeyValue' => (bool) ($section['key_value'] ?? false),
                    'generatedAt' => $generatedAt,
                    'userName' => $userName,
                    'userEmail' => $userEmail,
                ])->setPaper('a4', 'portrait');

                $zip->addFromString($file, $pdf->output());
            }

            $zip->addFromString('README.txt', $this->buildReadme($user));
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
     * Define every section that lands in the ZIP. Each entry returns the
     * PDF filename plus the rendering payload (title / subtitle / rows /
     * columns). Adding a new section is one entry here + nothing else.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sections(User $user): array
    {
        return [
            'profile.pdf' => $this->profileSection($user),
            'bookings.pdf' => $this->bookingsSection($user),
            'vouchers.pdf' => $this->vouchersSection($user),
            'reviews.pdf' => $this->reviewsSection($user),
            'favorites.pdf' => $this->favoritesSection($user),
            'saved-items.pdf' => $this->savedItemsSection($user),
            'notifications.pdf' => $this->notificationsSection($user),
            'loyalty.pdf' => $this->loyaltySection($user),
            'login-history.pdf' => $this->loginHistorySection($user),
            'support-tickets.pdf' => $this->supportTicketsSection($user),
            'payment-methods.pdf' => $this->paymentMethodsSection($user),
            'company-memberships.pdf' => $this->companyMembershipsSection($user),
            'commissions.pdf' => $this->commissionsSection($user),
            'contracts.pdf' => $this->contractsSection($user),
        ];
    }

    /** @return array<string, mixed> */
    private function profileSection(User $user): array
    {
        return [
            'title' => 'My profile',
            'subtitle' => 'Your account details on ZULU.',
            'key_value' => true,
            'rows' => [
                'User ID' => $user->id,
                'Name' => $user->name,
                'Email' => $user->email,
                'Phone' => $user->phone,
                'Preferred language' => $user->preferred_language,
                'Birth date' => optional($user->birth_date)->toDateString(),
                'Nationality' => $user->nationality,
                'Account status' => $user->status,
                'Email verified' => $user->email_verified_at ? $user->email_verified_at->toDateTimeString() : 'Not verified',
                'Registered' => optional($user->created_at)->toDateTimeString(),
                'Last updated' => optional($user->updated_at)->toDateTimeString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bookingsSection(User $user): array
    {
        $rows = $this->table('orders', ['user_id' => $user->id]);
        $columns = [
            'id' => 'Booking #',
            'created_at' => 'Date',
            'status' => 'Status',
            'currency' => 'Currency',
            'total' => 'Total',
        ];
        $formatted = array_map(fn ($r) => [
            'id' => $r['id'] ?? null,
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'status' => $r['status'] ?? null,
            'currency' => $r['currency'] ?? null,
            'total' => $r['final_total_snapshot'] ?? $r['total'] ?? null,
        ], $rows);

        return [
            'title' => 'My bookings',
            'subtitle' => 'Every order you placed on ZULU.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function vouchersSection(User $user): array
    {
        $rows = $this->table('vouchers', ['user_id' => $user->id]);
        $columns = [
            'voucher_code' => 'Voucher code',
            'created_at' => 'Issued',
            'service_type' => 'Service',
            'status' => 'Status',
        ];
        $formatted = array_map(fn ($r) => [
            'voucher_code' => $r['voucher_code'] ?? $r['code'] ?? $r['id'] ?? null,
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'service_type' => $r['service_type'] ?? $r['module'] ?? null,
            'status' => $r['status'] ?? null,
        ], $rows);

        return [
            'title' => 'My vouchers',
            'subtitle' => 'Booking confirmation vouchers issued to you.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function reviewsSection(User $user): array
    {
        $rows = $this->table('reviews', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'Date',
            'rating' => 'Rating',
            'subject_type' => 'For',
            'comment' => 'Review',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'rating' => $r['rating'] ?? null,
            'subject_type' => $r['subject_type'] ?? $r['module'] ?? null,
            'comment' => $this->trim($r['comment'] ?? $r['text'] ?? '', 200),
        ], $rows);

        return [
            'title' => 'My reviews',
            'subtitle' => 'Reviews you have posted on ZULU.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function favoritesSection(User $user): array
    {
        $rows = $this->table('user_favorites', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'Saved',
            'item_type' => 'Type',
            'item_id' => 'Item ID',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'item_type' => $r['item_type'] ?? null,
            'item_id' => $r['item_id'] ?? null,
        ], $rows);

        return [
            'title' => 'My favorites',
            'subtitle' => 'Hotels / flights / packages you marked as favorites.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function savedItemsSection(User $user): array
    {
        $rows = $this->table('saved_items', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'Saved',
            'module_type' => 'Type',
            'offer_id' => 'Offer ID',
            'status' => 'Status',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'module_type' => $r['module_type'] ?? null,
            'offer_id' => $r['offer_id'] ?? null,
            'status' => $r['status'] ?? null,
        ], $rows);

        return [
            'title' => 'Saved items',
            'subtitle' => 'Offers you saved to view later.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function notificationsSection(User $user): array
    {
        $rows = $this->table('notifications', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'Date',
            'title' => 'Title',
            'message' => 'Message',
            'event' => 'Event',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'title' => $this->trim($r['title'] ?? '', 80),
            'message' => $this->trim($r['message'] ?? $r['body'] ?? '', 160),
            'event' => $r['event'] ?? $r['type'] ?? null,
        ], $rows);

        return [
            'title' => 'Notifications',
            'subtitle' => 'In-app notifications we delivered to you.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function loyaltySection(User $user): array
    {
        $tx = $this->table('loyalty_transactions', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'Date',
            'change' => 'Points',
            'reason' => 'Reason',
            'balance_after' => 'Balance',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'change' => $r['change'] ?? $r['amount'] ?? null,
            'reason' => $r['reason'] ?? $r['type'] ?? null,
            'balance_after' => $r['balance_after'] ?? null,
        ], $tx);

        return [
            'title' => 'Loyalty points',
            'subtitle' => 'Every points earn / redeem transaction.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function loginHistorySection(User $user): array
    {
        $rows = $this->table('login_history', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'When',
            'ip_address' => 'IP address',
            'user_agent' => 'Device / browser',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'ip_address' => $r['ip_address'] ?? $r['ip'] ?? null,
            'user_agent' => $this->trim($r['user_agent'] ?? '', 120),
        ], $rows);

        return [
            'title' => 'Login history',
            'subtitle' => 'Successful sign-ins to your account.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function supportTicketsSection(User $user): array
    {
        $rows = $this->table('support_tickets', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'Opened',
            'subject' => 'Subject',
            'status' => 'Status',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'subject' => $this->trim($r['subject'] ?? $r['title'] ?? '', 120),
            'status' => $r['status'] ?? null,
        ], $rows);

        return [
            'title' => 'Support tickets',
            'subtitle' => 'Support requests you submitted.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function paymentMethodsSection(User $user): array
    {
        $rows = $this->table('payment_methods', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'Added',
            'brand' => 'Card brand',
            'last4' => 'Last 4',
            'exp_month' => 'Expiry month',
            'exp_year' => 'Expiry year',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'brand' => $r['brand'] ?? null,
            'last4' => $r['last4'] ?? null,
            'exp_month' => $r['exp_month'] ?? null,
            'exp_year' => $r['exp_year'] ?? null,
        ], $rows);

        return [
            'title' => 'Payment methods',
            'subtitle' => 'Card / wallet metadata you have stored. We never store full card numbers.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function companyMembershipsSection(User $user): array
    {
        $rows = $this->table('user_company', ['user_id' => $user->id]);
        $columns = [
            'company_id' => 'Company ID',
            'role_id' => 'Role',
            'created_at' => 'Joined',
        ];
        $formatted = array_map(fn ($r) => [
            'company_id' => $r['company_id'] ?? null,
            'role_id' => $r['role_id'] ?? null,
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
        ], $rows);

        return [
            'title' => 'Company memberships',
            'subtitle' => 'Operator / agent companies you belong to.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function commissionsSection(User $user): array
    {
        $rows = $this->table('commission_records', ['user_id' => $user->id]);
        $columns = [
            'created_at' => 'Date',
            'amount' => 'Amount',
            'currency' => 'Currency',
            'status' => 'Status',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'amount' => $r['amount'] ?? null,
            'currency' => $r['currency'] ?? null,
            'status' => $r['status'] ?? null,
        ], $rows);

        return [
            'title' => 'Commissions',
            'subtitle' => 'Commission records associated with your B2B activity.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /** @return array<string, mixed> */
    private function contractsSection(User $user): array
    {
        $rows = $this->table('contracts', ['signed_by_user_id' => $user->id]);
        $columns = [
            'created_at' => 'Date',
            'title' => 'Contract',
            'status' => 'Status',
        ];
        $formatted = array_map(fn ($r) => [
            'created_at' => $this->fmtDate($r['created_at'] ?? null),
            'title' => $r['title'] ?? $r['contract_title'] ?? null,
            'status' => $r['status'] ?? null,
        ], $rows);

        return [
            'title' => 'Contracts',
            'subtitle' => 'Contracts you signed as part of your B2B relationship.',
            'columns' => $columns,
            'rows' => $formatted,
        ];
    }

    /**
     * Defensive table read — returns [] if the table or column doesn't
     * exist so an optional B2B-only model never breaks the export.
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

            return DB::table($tableName)
                ->where($where)
                ->orderByDesc('created_at')
                ->limit(500)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function fmtDate(mixed $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        try {
            return Carbon::parse((string) $raw)->toDateTimeString();
        } catch (\Throwable $e) {
            return (string) $raw;
        }
    }

    private function trim(?string $value, int $max): string
    {
        $value = (string) ($value ?? '');
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }

    private function buildReadme(User $user): string
    {
        $now = now()->toIso8601String();
        $email = $user->email ?: '';

        return <<<TXT
ZULU — Your personal data export
================================

Generated: {$now}
For: {$email}

This ZIP contains a separate PDF for every kind of information we hold
about your ZULU account. Open any of them in your usual PDF reader.

  profile.pdf              — your name, email, phone, language, etc.
  bookings.pdf             — every order you placed
  vouchers.pdf             — vouchers issued to you
  reviews.pdf              — reviews you posted
  favorites.pdf            — hearted hotels / flights / packages
  saved-items.pdf          — saved listings
  notifications.pdf        — in-app notifications
  loyalty.pdf              — loyalty points + tier history
  login-history.pdf        — when you signed in and from where
  support-tickets.pdf      — support tickets you opened
  payment-methods.pdf      — card / wallet metadata (no full card numbers)
  company-memberships.pdf  — companies you belong to (B2B)
  commissions.pdf          — commission records (B2B)
  contracts.pdf            — contracts you signed (B2B)

A few sections may be empty if you never used that feature — that's
expected. If you have questions, reply to your usual ZULU support
contact and quote this archive's filename.
TXT;
    }
}
