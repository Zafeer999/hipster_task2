<?php

namespace Zafeer\Discounts\Models;

use Illuminate\Database\Eloquent\Model;

class UserDiscount extends Model
{
    protected $table = 'user_discounts';

    protected $guarded = [];

    protected $casts = [
        'usage_count' => 'integer',
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function isRevoked(): bool
    {
        return ! is_null($this->revoked_at);
    }
}
