<?php

namespace Zafeer\Discounts\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class DiscountApplied
{
    use Dispatchable, SerializesModels;

    public function __construct(public $user, public array $applied, public float $original, public float $final) {}
}
