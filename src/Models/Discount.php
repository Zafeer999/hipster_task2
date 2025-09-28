<?php

namespace Zafeer\Discounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\DiscountFactory;

class Discount extends Model
{
    use HasFactory;

    protected $table = 'discounts';

    protected $fillable = [
        'code',
        'type',
        'value',
        'percentage',
        'fixed_amount',
        'priority',
        'starts_at',
        'ends_at',
        'expires_at',
        'active',
        'max_uses_per_user',
        'usage_limit',
    ];

    protected $casts = [
        'value' => 'float',
        'percentage' => 'float',
        'fixed_amount' => 'float',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'expires_at' => 'datetime',
        'active' => 'boolean',
        'max_uses_per_user' => 'integer',
        'usage_limit' => 'integer',
    ];

    public function userAssignments(): HasMany
    {
        return $this->hasMany(UserDiscount::class, 'discount_id', 'id');
    }

    /**
     * Returns expiry preferring expires_at (legacy) when present, otherwise ends_at.
     */
    public function getEffectiveEndsAtAttribute()
    {
        return $this->expires_at ?? $this->ends_at;
    }

    public function isActiveNow(): bool
    {
        if (! $this->active) return false;
        $now = now();
        $starts = $this->starts_at;
        $ends = $this->getEffectiveEndsAtAttribute();
        if ($starts && $now->lt($starts)) return false;
        if ($ends && $now->gt($ends)) return false;
        return true;
    }

    protected static function newFactory()
    {
        return DiscountFactory::new();
    }
}
