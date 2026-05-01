<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherVerificationLog extends Model
{
    use HasFactory;

    protected $table = 'voucher_verification_logs';

    public const RESULTS = ['valid', 'used', 'void', 'invalid', 'expired'];

    protected $fillable = [
        'voucher_id',
        'scanned_at',
        'ip',
        'user_agent',
        'location',
        'result',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }
}
