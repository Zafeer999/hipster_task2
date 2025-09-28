<?php

namespace Zafeer\Discounts\Examples\ProductScoped;

use Zafeer\Discounts\Models\Discount;
use App\Models\User;

class ProductScopedDiscountHandler
{
    /**
     * discount->code should contain comma-separated product ids, e.g. "1,2,3"
     */
    public function apply(User $user, array $cartItems, Discount $discount): array
    {
        $productIds = array_filter(array_map('trim', explode(',', (string)$discount->code)));
        if (empty($productIds)) {
            return ['applied' => false, 'amount' => 0.0, 'details' => []];
        }

        $amount = 0.0;
        $affected = [];

        foreach ($cartItems as $item) {
            if (in_array((string)$item['product_id'], $productIds, true)) {
                if ($discount->type === 'percentage') {
                    $amount += ($discount->value / 100.0) * ($item['unit_price'] * $item['quantity']);
                } else {
                    $amount += $discount->value * $item['quantity'];
                }
                $affected[] = $item['product_id'];
            }
        }

        return ['applied' => $amount > 0, 'amount' => $amount, 'details' => ['affected' => $affected]];
    }
}
