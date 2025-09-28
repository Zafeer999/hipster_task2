<?php

namespace Zafeer\Discounts\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountAudit extends Model
{
    protected $table = 'discount_audits';

    protected $fillable = [
        'idempotency_key',
        'user_id',
        'discount_id',
        'action',
        'applied',
        'original_amount',
        'final_amount',
        'meta',
    ];

    protected $casts = [
        'applied' => 'array',
        'meta' => 'array',
        'original_amount' => 'float',
        'final_amount' => 'float',
    ];
}
