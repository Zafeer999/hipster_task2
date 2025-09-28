<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Zafeer\Discounts\Models\Discount;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Zafeer\Discounts\Models\Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition()
    {
        // Use 'percentage' or 'fixed' types; value semantics: for percentage (0-100), fixed is currency amount.
        $type = $this->faker->randomElement(['percentage', 'fixed']);

        return [
            'code' => strtoupper($this->faker->lexify('DISC??')),
            'type' => $type,
            'value' => $type === 'percentage' ? $this->faker->numberBetween(5, 30) : $this->faker->randomFloat(2, 1, 50),
            'priority' => $this->faker->numberBetween(0, 10),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(7),
            'active' => true,
            'max_uses_per_user' => $this->faker->optional(0.7)->numberBetween(1, 5),
        ];
    }

    public function expired()
    {
        return $this->state(fn() => [
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(1),
            'active' => true,
        ]);
    }

    public function inactive()
    {
        return $this->state(fn() => [
            'active' => false,
        ]);
    }
}
