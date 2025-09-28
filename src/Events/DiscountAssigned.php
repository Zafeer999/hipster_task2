<?php

namespace Zafeer\Discounts\Events;

use Zafeer\Discounts\Models\Discount;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class DiscountAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(public $user, public Discount $discount) {}
}
