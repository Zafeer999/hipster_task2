<?php

namespace Zafeer\Discounts\Services;

use Zafeer\Discounts\Events\DiscountRevoked;
use Zafeer\Discounts\Services\DiscountManager;
use Zafeer\Discounts\Models\Discount;
use Zafeer\Discounts\Models\DiscountAudit;
use Zafeer\Discounts\Models\UserDiscount;

class DiscountService
{
    public function __construct(protected DiscountManager $manager) {}

    public function assign($user, Discount $discount): UserDiscount
    {
        return $this->manager->assign($user, $discount);
    }

    public function revoke($user, Discount $discount): bool
    {
        $ud = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        if (! $ud) return false;
        $ud->update(['revoked_at' => now()]);
        $this->events->dispatch(new DiscountRevoked($user, $discount));

        DiscountAudit::create([
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => 'revoked',
            'applied' => null,
            'original_amount' => null,
            'final_amount' => null,
            'meta' => ['user_discount_id' => $ud->id],
        ]);

        return true;
    }

    public function eligibleFor($user, Discount $discount): bool
    {
        return $this->manager->eligibleFor($user, $discount);
    }

    public function apply($user, float $amount, array $opts = []): float
    {
        $res = $this->manager->apply($user, $amount, $opts);

        if (is_array($res) && array_key_exists('final', $res)) {
            return (float) $res['final'];
        }

        return (float) $res;
    }
}
