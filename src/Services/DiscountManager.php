<?php

namespace Zafeer\Discounts\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Zafeer\Discounts\Models\Discount;
use Zafeer\Discounts\Models\UserDiscount;
use Zafeer\Discounts\Models\DiscountAudit;
use Zafeer\Discounts\Events\DiscountAssigned;
use Zafeer\Discounts\Events\DiscountRevoked;
use Zafeer\Discounts\Events\DiscountApplied;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DiscountManager
{
    protected ConnectionInterface $db;
    protected Dispatcher $events;
    protected array $config = [];

    /**
     * Accept either a ConnectionInterface *or* DatabaseManager (will use default connection).
     *
     * @param ConnectionInterface|DatabaseManager $db
     * @param Dispatcher $events
     * @param array $config
     */
    public function __construct($db, Dispatcher $events, array $config = [])
    {
        // Normalize $db to a ConnectionInterface
        if ($db instanceof DatabaseManager) {
            $this->db = $db->connection();
        } elseif ($db instanceof ConnectionInterface) {
            $this->db = $db;
        } else {
            throw new InvalidArgumentException('First constructor argument must be ConnectionInterface or DatabaseManager.');
        }

        $this->events = $events;
        $this->config = $config;
    }

    /**
     * Assign a discount to a user (create user_discounts row).
     */
    public function assign($user, Discount $discount): UserDiscount
    {
        $ud = UserDiscount::firstOrCreate(
            ['user_id' => $user->id, 'discount_id' => $discount->id],
            ['assigned_at' => now()]
        );

        $this->events->dispatch(new DiscountAssigned($user, $discount));

        DiscountAudit::create([
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => 'assigned',
            'applied' => null,
            'original_amount' => null,
            'final_amount' => null,
            'meta' => ['user_discount_id' => $ud->id],
        ]);

        return $ud;
    }

    /**
     * Revoke assignment for a user.
     */
    public function revoke($user, Discount $discount): bool
    {
        $ud = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        if (! $ud) return false;
        $ud->update(['revoked_at' => now()]);
        $this->events->dispatch(new DiscountRevoked($user, $discount));
        return true;
    }

    /**
     * Check if a discount is eligible for a user right now.
     */
    public function eligibleFor($user, Discount $discount): bool
    {
        if (! $discount->isActiveNow()) return false;

        $ud = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        if (! $ud || $ud->isRevoked()) {
            return false;
        }

        $cap = $discount->max_uses_per_user ?? ($this->config['defaults']['max_uses_per_user'] ?? null);

        if ($cap !== null && $ud->usage_count >= $cap) {
            return false;
        }

        return true;
    }

    /**
     * Apply applicable discounts deterministically.
     *
     * Options:
     *  - idempotency_key (string) to make apply idempotent per user
     *  - context (array) any extra data
     *
     * Returns array: ['original' => float, 'final' => float, 'applied' => array]
     */
    public function apply($user, float $amount, array $opts = []): array
    {
        $idempotency = $opts['idempotency_key'] ?? null;

        // If idempotency key provided and audit exists, return it -> idempotent.
        if ($idempotency) {
            $existing = DiscountAudit::where('idempotency_key', $idempotency)
                ->where('user_id', $user->id)
                ->first();
            if ($existing) {
                return [
                    'original' => (float)$existing->original_amount,
                    'final' => (float)$existing->final_amount,
                    'applied' => (array)$existing->applied,
                    'already_executed' => true,
                ];
            }
        }

        // Fetch assigned, non-revoked discounts for user joined with discounts that are active now.
        $userDiscounts = UserDiscount::with('discount')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get()
            ->filter(function (UserDiscount $ud) {
                return $ud->discount && $ud->discount->isActiveNow();
            });

        if ($userDiscounts->isEmpty()) {
            // record audit even if none applied (optional)
            $audit = DiscountAudit::create([
                'idempotency_key' => $idempotency ? $idempotency : Str::uuid(),
                'user_id' => $user->id,
                'applied' => [],
                'original_amount' => $amount,
                'final_amount' => $amount,
            ]);
            return ['original' => $amount, 'final' => $amount, 'applied' => []];
        }

        // Deterministic ordering: by type according to config order,
        // then by priority desc, then id asc.
        $order = $this->config['stacking']['order'] ?? ['percentage', 'fixed'];
        $grouped = $userDiscounts->groupBy(fn($ud) => $ud->discount->type);

        $sorted = collect();
        foreach ($order as $type) {
            if (isset($grouped[$type])) {
                $sorted = $sorted->concat(
                    $grouped[$type]->sortByDesc(fn($ud) => $ud->discount->priority)
                        ->sortBy('discount_id') // tie-breaker deterministic
                );
            }
        }

        $original = $amount;
        $running = $amount;
        $applied = [];

        // Begin transaction for concurrency safety & idempotency
        return $this->db->transaction(function () use (
            $user,
            $sorted,
            $running,
            $original,
            $idempotency,
            &$applied
        ) {
            // Lock relevant user_discount rows for update to prevent race.
            $ids = $sorted->pluck('id')->all();
            if (count($ids)) {
                UserDiscount::whereIn('id', $ids)->lockForUpdate()->get();
            }

            // Build percentage pool first to enforce percentage cap.
            $percentTotal = 0.0;
            $maxPercent = $this->config['stacking']['max_total_percentage'] ?? 100.0;

            // iterate sorted discounts, attempt to apply each deterministically
            foreach ($sorted as $ud) {

                /** @var UserDiscount $ud */
                $discount = $ud->discount;

                // Double-check still eligible (cap might have changed)
                $cap = $discount->max_uses_per_user ?? ($this->config['defaults']['max_uses_per_user'] ?? null);

                if ($cap !== null) {
                    // atomic check+increment: update usage_count only if still below cap
                    $affected = $this->db->table('user_discounts')
                        ->where('id', $ud->id)
                        ->where('usage_count', '<', $cap)
                        ->update(['usage_count' => $this->db->raw('usage_count + 1')]);

                    if ($affected === 0) {
                        // usage cap reached—skip this discount
                        continue;
                    }
                } else {
                    // if no cap set, increment usage_count for tracking (non-atomic is okay because lockForUpdate)
                    $this->db->table('user_discounts')->where('id', $ud->id)
                        ->update(['usage_count' => $this->db->raw('usage_count + 1')]);
                }

                // Apply discount
                if ($discount->type === 'percentage') {
                    // respect max_total_percentage cap
                    $remainingPercentAllowed = max(0, $maxPercent - $percentTotal);
                    $applyPercent = min($discount->value, $remainingPercentAllowed);
                    if ($applyPercent <= 0) {
                        // can't apply more percentage
                        // Note: we already incremented usage — this keeps audit of attempted use.
                        continue;
                    }
                    $discountAmount = ($applyPercent / 100.0) * $running;
                    $percentTotal += $applyPercent;
                } else { // fixed
                    $discountAmount = $discount->value;
                }

                $running -= $discountAmount;

                // Rounding
                $running = $this->roundAmount($running);

                $applied[] = [
                    'discount_id' => $discount->id,
                    'type' => $discount->type,
                    'value' => (float)$discount->value,
                    'amount' => (float)$discountAmount,
                    'after' => (float)$running,
                ];
            }

            $key = $idempotency ? $idempotency : (string) Str::uuid();

            $audit = DiscountAudit::create([
                'idempotency_key' => $key,
                'user_id' => $user->id,
                'discount_id' => null,
                'action' => 'applied',
                'applied' => $applied,
                'original_amount' => $original,
                'final_amount' => $running,
                'meta' => [
                    'stacking' => $this->config['stacking']['order'] ?? null,
                ],
            ]);

            $this->events->dispatch(new DiscountApplied($user, $applied, $original, $running));

            return [
                'original' => (float)$original,
                'final' => (float)$running,
                'applied' => $applied,
                'audit_id' => $audit->id,
            ];
        }, 5);
    }

    protected function roundAmount(float $value): float
    {
        $mode = $this->config['rounding']['mode'] ?? 'round';
        $precision = $this->config['rounding']['precision'] ?? 2;
        $factor = pow(10, $precision);

        return match ($mode) {
            'ceil' => ceil($value * $factor) / $factor,
            'floor' => floor($value * $factor) / $factor,
            default => round($value, $precision),
        };
    }
}
