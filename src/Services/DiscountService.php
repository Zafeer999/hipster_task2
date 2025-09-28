<?php

namespace Zafeer\Discounts\Services;

use Zafeer\Discounts\Models\Discount;
use Zafeer\Discounts\Models\UserDiscount;

class DiscountService
{
    protected DiscountManager $manager;

    public function __construct(DiscountManager $manager)
    {
        $this->manager = $manager;
    }

    public function assign($user, Discount $discount): UserDiscount
    {
        return $this->manager->assign($user, $discount);
    }

    public function revoke($user, Discount $discount): bool
    {
        return $this->manager->revoke($user, $discount);
    }

    /**
     * If only $user provided, return a collection of eligible discounts for that user.
     * If $discount provided, return boolean eligible for that specific discount.
     */
    public function eligibleFor($user, ?Discount $discount = null)
    {
        if ($discount === null) {
            return $this->manager->eligibleForUser($user);
        }

        return $this->manager->eligibleFor($user, $discount);
    }

    /**
     * Apply discounts. Tests expect the *final numeric amount* from this service.
     * Return float final amount for compatibility with tests.
     */
    public function apply($user, float $amount, array $opts = []): float
    {
        $res = $this->manager->apply($user, $amount, $opts);

        // Manager returns an array with 'final' key; return numeric final for test compatibility.
        if (is_array($res) && isset($res['final'])) {
            return (float) $res['final'];
        }

        // Fallback: if manager returned float already, cast and return
        return (float) $res;
    }
}
