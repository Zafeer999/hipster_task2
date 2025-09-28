<?php

namespace Zafeer\Discounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDiscount extends Model
{
    protected $table = 'user_discounts';

    protected $fillable = [
        'user_id',
        'discount_id',
        'assigned_at',
        'revoked_at',
        'usage_count'
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
        'usage_count' => 'integer',
    ];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function isRevoked(): bool
    {
        return (bool) $this->revoked_at;
    }
}
