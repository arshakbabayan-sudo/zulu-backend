<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceConnection extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const SOURCE_TYPES = ['flight', 'hotel', 'transfer', 'excursion'];

    /** @var list<string> */
    public const TARGET_TYPES = ['flight', 'hotel', 'transfer', 'excursion'];

    /** @var list<string> */
    public const STATUSES = ['pending', 'accepted', 'rejected', 'canceled'];

    /** @var list<string> */
    public const CLIENT_TARGETING = ['all', 'selected'];

    /** @var list<string> */
    public const CITY_MATCH_MODES = ['any', 'exact'];

    /** @var list<string> */
    protected $fillable = [
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'connection_type',
        'status',
        'client_targeting',
        'selected_client_ids',
        'city_rules',
        'status_history',
        'company_id',
        'notes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'selected_client_ids' => 'array',
        'city_rules' => 'array',
        'status_history' => 'array',
    ];

    /** @var list<string> */
    protected $appends = ['targeting'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Canonical targeting envelope for API consumers.
     *
     * @return array{mode: string, client_ids?: list<int>}
     */
    public function getTargetingAttribute(): array
    {
        $mode = is_string($this->client_targeting) ? $this->client_targeting : 'all';
        $mode = in_array($mode, self::CLIENT_TARGETING, true) ? $mode : 'all';

        $clientIds = [];
        if ($mode === 'selected') {
            $raw = is_array($this->selected_client_ids) ? $this->selected_client_ids : [];
            $clientIds = collect($raw)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return $mode === 'selected'
            ? ['mode' => $mode, 'client_ids' => $clientIds]
            : ['mode' => $mode];
    }
}
