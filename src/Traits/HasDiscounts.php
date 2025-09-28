<?php

namespace Zafeer\Discounts\Traits;

use Zafeer\Discounts\Models\UserDiscount;
use Zafeer\Discounts\Models\Discount;

trait HasDiscounts
{
    public function discounts()
    {
        return $this->hasMany(UserDiscount::class, 'user_id');
    }

    public function assignDiscount(Discount $discount)
    {
        return app(\Zafeer\Discounts\Services\DiscountManager::class)->assign($this, $discount);
    }
}
