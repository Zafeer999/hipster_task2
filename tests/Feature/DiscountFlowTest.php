<?php

namespace Zafeer\Discounts\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Zafeer\Discounts\Events\DiscountAssigned;
use Zafeer\Discounts\Events\DiscountRevoked;
use Zafeer\Discounts\Events\DiscountApplied;
use Zafeer\Discounts\Models\Discount;
use Zafeer\Discounts\Models\UserDiscount;
use Zafeer\Discounts\Models\DiscountAudit;
use Zafeer\Discounts\Tests\TestCase;
use Zafeer\Discounts\Services\DiscountService;

class DiscountFlowTest extends TestCase
{
    use RefreshDatabase;

    protected DiscountService $discountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discountService = app(DiscountService::class);
    }

    /** @test */
    public function it_assigns_and_records_audit()
    {
        Event::fake([DiscountAssigned::class]);

        $discount = Discount::factory()->create([
            'active' => true,
            'expires_at' => now()->addDay(),
            'usage_limit' => 5,
            'percentage' => 10,
        ]);

        $user = $this->createTestUser();

        $this->discountService->assign($user, $discount);

        $this->assertDatabaseHas('user_discounts', [
            'user_id' => $user->id,
            'discount_id' => $discount->id,
        ]);

        Event::assertDispatched(DiscountAssigned::class);

        $this->assertDatabaseHas('discount_audits', [
            'discount_id' => $discount->id,
            'action' => 'assigned',
        ]);
    }

    /** @test */
    public function it_ignores_expired_or_inactive_discounts()
    {
        $user = $this->createTestUser();

        $active = Discount::factory()->create(['active' => true, 'expires_at' => now()->addDay()]);
        $expired = Discount::factory()->create(['active' => true, 'expires_at' => now()->subDay()]);
        $inactive = Discount::factory()->create(['active' => false, 'expires_at' => now()->addDay()]);

        $this->discountService->assign($user, $active);
        $this->discountService->assign($user, $expired);
        $this->discountService->assign($user, $inactive);

        $eligible = $this->discountService->eligibleFor($user);

        $this->assertCount(1, $eligible);
        $this->assertTrue($eligible->first()->is($active));
    }

    /** @test */
    public function it_applies_discounts_deterministically_and_records_audit()
    {
        Event::fake([DiscountApplied::class]);

        config()->set('discounts.max_percentage_cap', 50);
        config()->set('discounts.rounding', 2);
        config()->set('discounts.stacking_order', ['percentage']);

        $user = $this->createTestUser();
        $baseAmount = 100;

        $d1 = Discount::factory()->create(['active' => true, 'percentage' => 10, 'expires_at' => now()->addDay()]);
        $d2 = Discount::factory()->create(['active' => true, 'percentage' => 20, 'expires_at' => now()->addDay()]);

        $this->discountService->assign($user, $d1);
        $this->discountService->assign($user, $d2);

        $final = $this->discountService->apply($user, $baseAmount);

        // 10% + 20% = 30% off = $70
        $this->assertEquals(70.00, $final);

        Event::assertDispatched(DiscountApplied::class);

        $this->assertDatabaseHas('discount_audits', [
            'user_id' => $user->id,
            'action' => 'applied',
        ]);
    }

    /** @test */
    public function it_enforces_usage_limits()
    {
        $user = $this->createTestUser();

        $discount = Discount::factory()->create([
            'active' => true,
            'usage_limit' => 2,
            'percentage' => 10,
            'expires_at' => now()->addDay(),
        ]);

        $this->discountService->assign($user, $discount);

        // Apply twice should work
        $this->discountService->apply($user, 100);
        $this->discountService->apply($user, 100);

        // Third time should skip
        $result = $this->discountService->apply($user, 100);
        $this->assertEquals(100, $result); // no discount applied

        $this->assertEquals(2, UserDiscount::where('discount_id', $discount->id)->first()->usage_count);
    }

    /** @test */
    public function it_does_not_apply_revoked_discounts()
    {
        $user = $this->createTestUser();

        $discount = Discount::factory()->create([
            'active' => true,
            'percentage' => 10,
            'expires_at' => now()->addDay(),
        ]);

        $this->discountService->assign($user, $discount);
        $this->discountService->revoke($user, $discount);

        $result = $this->discountService->apply($user, 100);
        $this->assertEquals(100, $result);

        Event::assertDispatched(DiscountRevoked::class);
    }

    /** @test */
    public function it_is_concurrency_safe_and_idempotent()
    {
        $user = $this->createTestUser();

        $discount = Discount::factory()->create([
            'active' => true,
            'percentage' => 10,
            'usage_limit' => 1,
            'expires_at' => now()->addDay(),
        ]);

        $this->discountService->assign($user, $discount);

        $results = [];

        DB::transaction(function () use ($user, &$results) {
            $results[] = $this->discountService->apply($user, 100);
            $results[] = $this->discountService->apply($user, 100);
        });

        // Only one should apply the discount
        $this->assertTrue(in_array(90.00, $results));
        $this->assertTrue(in_array(100.00, $results));
    }

    protected function createTestUser()
    {
        return new class {
            public $id = 1;
        };
    }
}
