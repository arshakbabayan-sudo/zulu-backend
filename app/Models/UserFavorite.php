<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFavorite extends Model
{
    use HasFactory;

    public const ITEM_HOTEL = 'hotel';

    public const ITEM_PACKAGE = 'package';

    public const ITEM_EXCURSION = 'excursion';

    public const ITEM_CAR = 'car';

    public const ITEM_TRANSFER = 'transfer';

    public const ITEM_FLIGHT = 'flight';

    public const ITEM_VISA = 'visa';

    public const ITEM_OFFER = 'offer';

    /** @var list<string> */
    public const ITEM_TYPES = [
        self::ITEM_HOTEL,
        self::ITEM_PACKAGE,
        self::ITEM_EXCURSION,
        self::ITEM_CAR,
        self::ITEM_TRANSFER,
        self::ITEM_FLIGHT,
        self::ITEM_VISA,
        self::ITEM_OFFER,
    ];

    protected $fillable = [
        'user_id',
        'item_type',
        'item_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
