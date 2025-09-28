<?php

namespace Zafeer\Discounts\Examples\Bogo;

use Zafeer\Discounts\Models\Discount;
use App\Models\User;

class BogoDiscountHandler
{
    /**
     * Example: discount->value holds "buy:free" as "1:1"
     *
     * $cartItems = [
     *   ['product_id' => 1, 'quantity' => 3, 'unit_price' => 10.00],
     *   ...
     * ]
     *
     * Returns ['applied' => bool, 'amount' => float, 'details' => array]
     */
    public function apply(User $user, array $cartItems, Discount $discount): array
    {
        [$buy, $free] = explode(':', (string)$discount->value);
        $buy = (int)$buy;
        $free = (int)$free;

        foreach ($cartItems as $item) {
            if ($item['quantity'] >= ($buy + $free)) {
                $amount = min($free, $item['quantity']) * $item['unit_price'];
                return ['applied' => true, 'amount' => $amount, 'details' => ['product_id' => $item['product_id']]];
            }
        }

        return ['applied' => false, 'amount' => 0.0, 'details' => []];
    }
}
