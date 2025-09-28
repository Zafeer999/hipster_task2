<?php

namespace Zafeer\Discounts\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Zafeer\Discounts\Models\Discount;
use Zafeer\Discounts\Models\UserDiscount;
use Zafeer\Discounts\Models\DiscountAudit;
use Zafeer\Discounts\Events\DiscountAssigned;
use Zafeer\Discounts\Events\DiscountRevoked;
use Zafeer\Discounts\Events\DiscountApplied;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DiscountManager
{
    protected ConnectionInterface $db;
    protected ?Dispatcher $events = null;

    public function __construct($db, ?Dispatcher $events = null)
    {
        if ($db instanceof DatabaseManager) {
            $this->db = $db->connection();
        } elseif ($db instanceof ConnectionInterface) {
            $this->db = $db;
        } else {
            throw new InvalidArgumentException('First constructor argument must be ConnectionInterface or DatabaseManager.');
        }

        $this->events = $events;
    }

    public function assign($user, Discount $discount): UserDiscount
    {
        $ud = UserDiscount::firstOrCreate(
            ['user_id' => $user->id, 'discount_id' => $discount->id],
            ['assigned_at' => now()]
        );

        event(new DiscountAssigned($user, $discount));

        DiscountAudit::create([
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => 'assigned',
            'applied' => [],
            'original_amount' => 0,
            'final_amount' => 0,
        ]);

        return $ud;
    }

    public function revoke($user, Discount $discount): bool
    {
        $ud = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        if (! $ud) return false;
        $ud->update(['revoked_at' => now()]);

        event(new DiscountRevoked($user, $discount));

        DiscountAudit::create([
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $user->id,
            'discount_id' => $discount->id,
            'action' => 'revoked',
            'applied' => [],
            'original_amount' => 0,
            'final_amount' => 0,
        ]);

        return true;
    }

    public function eligibleFor($user, Discount $discount): bool
    {
        if (! $discount->isActiveNow()) return false;

        $ud = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        if (! $ud || $ud->isRevoked()) {
            return false;
        }

        $cap = $discount->max_uses_per_user ?? $discount->usage_limit ?? config('discounts.defaults.max_uses_per_user');

        if ($cap !== null && $ud->usage_count >= $cap) {
            return false;
        }

        return true;
    }

    public function eligibleForUser($user)
    {
        $userDiscounts = UserDiscount::with('discount')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get();

        $eligible = $userDiscounts->filter(function (UserDiscount $ud) {
            $d = $ud->discount;
            if (! $d) return false;
            if (! $d->isActiveNow()) return false;
            $cap = $d->max_uses_per_user ?? $d->usage_limit ?? config('discounts.defaults.max_uses_per_user');
            if ($cap !== null && $ud->usage_count >= $cap) return false;
            return true;
        })->map(fn($ud) => $ud->discount);

        return $eligible->values();
    }

    /**
     * Main apply:
     * - If idempotency_key provided, attempt to create a placeholder 'in_progress' audit;
     *   concurrent callers will detect the existing placeholder and poll for the final result.
     * - Use integer cents arithmetic to avoid FP drift.
     * - Only consider discounts applied if the atomic usage_count increment succeeded.
     */
    public function apply($user, float $amount, array $opts = []): array
    {
        $idempotency = $opts['idempotency_key'] ?? null;
        $precreatedAuditId = null;

        // Try placeholder creation if idempotency_key provided
        if ($idempotency) {
            try {
                $now = now()->toDateTimeString();
                DB::table('discount_audits')->insert([
                    'idempotency_key' => $idempotency,
                    'user_id' => $user->id,
                    'action' => 'in_progress',
                    'applied' => json_encode([]),
                    'original_amount' => $amount,
                    'final_amount' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $precreated = DiscountAudit::where('idempotency_key', $idempotency)->first();
                $precreatedAuditId = $precreated ? $precreated->id : null;
            } catch (\Throwable $e) {
                // duplicate insert — another process won. Poll for final result (up to 5s)
                $tries = 0;
                $maxTries = 50; // 50 * 100ms = 5s
                while ($tries < $maxTries) {
                    $existing = DiscountAudit::where('idempotency_key', $idempotency)
                        ->whereNotNull('action')
                        ->where('action', '<>', 'in_progress')
                        ->first();
                    if ($existing) {
                        return [
                            'original' => (float)$existing->original_amount,
                            'final' => (float)$existing->final_amount,
                            'applied' => (array)$existing->applied,
                            'already_executed' => true,
                        ];
                    }
                    usleep(100000);
                    $tries++;
                }
                // timed out — fall through (last resort)
            }
        }

        // Quick final-idempotency check in case finished already
        if ($idempotency) {
            $existingFinal = DiscountAudit::where('idempotency_key', $idempotency)
                ->where('action', '<>', 'in_progress')
                ->first();
            if ($existingFinal) {
                return [
                    'original' => (float)$existingFinal->original_amount,
                    'final' => (float)$existingFinal->final_amount,
                    'applied' => (array)$existingFinal->applied,
                    'already_executed' => true,
                ];
            }
        }

        // Collect eligible user_discounts (with discount relation)
        $userDiscounts = UserDiscount::with('discount')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get()
            ->filter(fn($ud) => $ud->discount && $ud->discount->isActiveNow());

        if ($userDiscounts->isEmpty()) {
            if ($precreatedAuditId) {
                $audit = DiscountAudit::find($precreatedAuditId);
                if ($audit) {
                    $audit->update([
                        'action' => 'applied',
                        'applied' => [],
                        'original_amount' => $amount,
                        'final_amount' => $amount,
                    ]);
                }
            } else {
                DiscountAudit::create([
                    'idempotency_key' => $idempotency ? $idempotency : (string) Str::uuid(),
                    'user_id' => $user->id,
                    'action' => 'applied',
                    'applied' => [],
                    'original_amount' => $amount,
                    'final_amount' => $amount,
                ]);
            }
            return ['original' => $amount, 'final' => $amount, 'applied' => []];
        }

        // Config
        $stackingConfig = config('discounts.stacking', []);
        $order = config('discounts.stacking_order', $stackingConfig['order'] ?? ['percentage', 'fixed']);
        $maxPercent = (float) config('discounts.max_percentage_cap', $stackingConfig['max_total_percentage'] ?? 100.0);
        $roundingConfig = config('discounts.rounding', []);
        $roundPrecision = is_array($roundingConfig) ? ($roundingConfig['precision'] ?? 2) : (int)$roundingConfig;
        $roundMode = is_array($roundingConfig) ? ($roundingConfig['mode'] ?? 'round') : 'round';

        // Ordering deterministic
        $grouped = $userDiscounts->groupBy(fn($ud) => $ud->discount->type ?? 'fixed');
        $sorted = collect();
        foreach ($order as $type) {
            if (isset($grouped[$type])) {
                $sorted = $sorted->concat(
                    $grouped[$type]->sortByDesc(fn($ud) => $ud->discount->priority ?? 0)
                        ->sortBy('discount_id')
                );
            }
        }
        $remainingTypes = $grouped->keys()->diff($order);
        foreach ($remainingTypes as $type) {
            $sorted = $sorted->concat(
                $grouped[$type]->sortByDesc(fn($ud) => $ud->discount->priority ?? 0)
                    ->sortBy('discount_id')
            );
        }

        $percentageUDs = $sorted->filter(fn($ud) => ($ud->discount->type ?? '') === 'percentage')->values();
        $fixedUDs = $sorted->filter(fn($ud) => ($ud->discount->type ?? '') === 'fixed')->values();

        // integer cents
        $factor = (int) pow(10, $roundPrecision);
        $originalCents = (int) round($amount * $factor);
        $runningCents = $originalCents;
        $applied = [];

        // DEBUG dump before selection (optional)
        // $this->debugDumpState('before-select', $user, $percentageUDs, $fixedUDs);

        // Transaction: lock rows, choose eligible discounts (based on current usage_count), then increment them
        $result = $this->db->transaction(function () use (
            $user,
            $percentageUDs,
            $fixedUDs,
            $originalCents,
            &$runningCents,
            $idempotency,
            &$applied,
            $maxPercent,
            $roundPrecision,
            $roundMode,
            $factor,
            $precreatedAuditId
        ) {
            // Lock relevant user_discount rows
            $allIds = collect($percentageUDs)->concat($fixedUDs)->pluck('id')->all();
            if (count($allIds)) {
                UserDiscount::whereIn('id', $allIds)->lockForUpdate()->get();
            }

            // SELECT STAGE: choose discounts that have remaining cap at this moment
            $selectedPerc = [];
            $percentTotal = 0.0;
            foreach ($percentageUDs as $ud) {
                $discount = $ud->discount;
                // Explicit percent lookup: only use percentage column if type === percentage
                $pct = null;
                if (($discount->type ?? '') === 'percentage') {
                    $pct = (float) ($discount->percentage ?? $discount->value ?? 0.0);
                } else {
                    continue;
                }

                $remainingAllowed = max(0.0, $maxPercent - $percentTotal);
                if ($remainingAllowed <= 0.0) continue;
                $wouldApply = min($pct, $remainingAllowed);
                if ($wouldApply <= 0.0) continue;

                $cap = $discount->max_uses_per_user ?? $discount->usage_limit ?? config('discounts.defaults.max_uses_per_user');
                $currentUsage = (int) (DB::table('user_discounts')->where('id', $ud->id)->value('usage_count') ?? 0);
                if ($cap !== null && $currentUsage >= (int)$cap) continue;

                // Tentatively select — will increment below
                $selectedPerc[] = ['ud' => $ud, 'pct' => $wouldApply];
                $percentTotal += $wouldApply;
            }

            // Now increment usage_count for selected percentage discounts — only those that still have capacity.
            $appliedPerc = [];
            foreach ($selectedPerc as $entry) {
                $ud = $entry['ud'];
                $pct = $entry['pct'];
                $discount = $ud->discount;
                $cap = $discount->max_uses_per_user ?? $discount->usage_limit ?? config('discounts.defaults.max_uses_per_user');

                if ($cap !== null) {
                    $affected = DB::update('UPDATE user_discounts SET usage_count = usage_count + 1 WHERE id = ? AND usage_count < ?', [$ud->id, $cap]);
                    if ($affected === 0) {
                        // lost race or cap reached after selection — skip this discount entirely
                        $percentTotal -= $pct;
                        continue;
                    }
                    $ud = UserDiscount::find($ud->id);
                } else {
                    DB::update('UPDATE user_discounts SET usage_count = usage_count + 1 WHERE id = ?', [$ud->id]);
                    $ud = UserDiscount::find($ud->id);
                }

                $appliedPerc[] = ['ud' => $ud, 'pct' => $pct];
            }

            // If after increments percentTotal changed (due to skips), recompute percentAmountCents from appliedPerc
            $percentTotalFinal = array_sum(array_map(fn($p) => $p['pct'], $appliedPerc));

            if ($percentTotalFinal > 0.0) {
                $percentAmountCents = (int) round($originalCents * ($percentTotalFinal / 100.0));
                // distribute per-discount cents exactly
                $perDiscountCents = [];
                $sumRounded = 0;
                $count = count($appliedPerc);
                foreach ($appliedPerc as $i => $entry) {
                    $pct = $entry['pct'];
                    $raw = $originalCents * ($pct / 100.0);
                    if ($i < $count - 1) {
                        $amtCents = (int) round($raw);
                        $perDiscountCents[] = $amtCents;
                        $sumRounded += $amtCents;
                    } else {
                        $amtCents = $percentAmountCents - $sumRounded;
                        $perDiscountCents[] = $amtCents;
                        $sumRounded += $amtCents;
                    }
                }

                // build applied entries
                $cumulative = 0;
                foreach ($appliedPerc as $i => $entry) {
                    $amtCents = $perDiscountCents[$i];
                    $cumulative += $amtCents;
                    $after = $originalCents - $cumulative;
                    $applied[] = [
                        'discount_id' => $entry['ud']->discount_id,
                        'type' => 'percentage',
                        'value' => (float) $entry['pct'],
                        'amount' => (float) round($amtCents / $factor, $roundPrecision),
                        'after' => (float) round($after / $factor, $roundPrecision),
                    ];
                }

                $runningCents -= $percentAmountCents;
            }

            // FIXED discounts: same select->increment->apply pattern
            foreach ($fixedUDs as $ud) {
                $discount = $ud->discount;
                $fixed = (float) ($discount->fixed_amount ?? $discount->value ?? 0.0);
                $fixedCents = (int) round($fixed * $factor);
                if ($fixedCents <= 0) continue;

                $cap = $discount->max_uses_per_user ?? $discount->usage_limit ?? config('discounts.defaults.max_uses_per_user');
                $currentUsage = (int) (DB::table('user_discounts')->where('id', $ud->id)->value('usage_count') ?? 0);
                if ($cap !== null && $currentUsage >= (int)$cap) continue;

                if ($cap !== null) {
                    $affected = DB::update('UPDATE user_discounts SET usage_count = usage_count + 1 WHERE id = ? AND usage_count < ?', [$ud->id, $cap]);
                    if ($affected === 0) continue;
                    $ud = UserDiscount::find($ud->id);
                } else {
                    DB::update('UPDATE user_discounts SET usage_count = usage_count + 1 WHERE id = ?', [$ud->id]);
                    $ud = UserDiscount::find($ud->id);
                }

                $runningCents -= $fixedCents;
                $applied[] = [
                    'discount_id' => $discount->id,
                    'type' => 'fixed',
                    'value' => (float) $fixed,
                    'amount' => (float) round($fixedCents / $factor, $roundPrecision),
                    'after' => (float) round($runningCents / $factor, $roundPrecision),
                ];
            }

            // finalize
            $final = (float) round($runningCents / $factor, $roundPrecision);

            // update placeholder audit or create final audit
            if ($precreatedAuditId) {
                $audit = DiscountAudit::find($precreatedAuditId);
                if ($audit) {
                    $audit->update([
                        'action' => 'applied',
                        'applied' => $applied,
                        'original_amount' => (float) round($originalCents / $factor, $roundPrecision),
                        'final_amount' => $final,
                    ]);
                }
            } else {
                $audit = DiscountAudit::create([
                    'idempotency_key' => $idempotency ? $idempotency : (string) Str::uuid(),
                    'user_id' => $user->id,
                    'action' => 'applied',
                    'applied' => $applied,
                    'original_amount' => (float) round($originalCents / $factor, $roundPrecision),
                    'final_amount' => $final,
                ]);
            }

            event(new DiscountApplied($user, $applied, (float) round($originalCents / $factor, $roundPrecision), $final));

            // DEBUG dump final state
            // $this->debugDumpState('after-apply', $user, collect($percentageUDs), collect($fixedUDs), $applied, [
            //     'original_cents' => $originalCents,
            //     'final_cents' => $runningCents,
            //     'final' => $final,
            //     'selected_percent_total' => array_sum(array_map(fn($p) => $p['value'] ?? $p['pct'] ?? 0.0, $applied)),
            // ]);

            return [
                'original' => (float) round($originalCents / $factor, $roundPrecision),
                'final' => $final,
                'applied' => $applied,
                'audit_id' => $audit->id,
            ];
        }, 5);

        return $result;
    }


    protected function roundAmountWithMode(float $value, int $precision = 2, string $mode = 'round'): float
    {
        $factor = pow(10, $precision);

        return match ($mode) {
            'ceil' => ceil($value * $factor) / $factor,
            'floor' => floor($value * $factor) / $factor,
            default => round($value, $precision),
        };
    }

    protected function debugDumpState($label, $user, $percentageUDs, $fixedUDs, $selected = null, $computed = null)
    {
        if (! env('DISCOUNT_DEBUG', true)) return;

        $rows = [
            'label' => $label,
            'user_id' => $user->id ?? null,
            'percentage_discounts' => array_map(function ($ud) {
                $d = $ud->discount;
                return [
                    'ud_id' => $ud->id,
                    'discount_id' => $d->id ?? null,
                    'type' => $d->type ?? null,
                    'value' => $d->value ?? null,
                    'percentage' => $d->percentage ?? null,
                    'fixed_amount' => $d->fixed_amount ?? null,
                    'priority' => $d->priority ?? null,
                    'usage_count' => $ud->usage_count ?? null,
                    'cap' => $d->max_uses_per_user ?? $d->usage_limit ?? null,
                ];
            }, $percentageUDs->all()),
            'fixed_discounts' => array_map(function ($ud) {
                $d = $ud->discount;
                return [
                    'ud_id' => $ud->id,
                    'discount_id' => $d->id ?? null,
                    'type' => $d->type ?? null,
                    'value' => $d->value ?? null,
                    'fixed_amount' => $d->fixed_amount ?? null,
                    'priority' => $d->priority ?? null,
                    'usage_count' => $ud->usage_count ?? null,
                    'cap' => $d->max_uses_per_user ?? $d->usage_limit ?? null,
                ];
            }, $fixedUDs->all()),
            'selected' => $selected,
            'computed' => $computed,
            'discount_audits' => DiscountAudit::orderBy('id')->get()->map(fn($r) => $r->toArray())->all(),
            'user_discounts_table' => \DB::table('user_discounts')->get()->map(fn($r) => (array)$r)->all(),
            'discounts_table' => \DB::table('discounts')->get()->map(fn($r) => (array)$r)->all(),
        ];

        // Note: prints to stdout so phpunit will show it inline
        echo PHP_EOL . '--- DISCOUNT DEBUG ' . $label . ' ---' . PHP_EOL;
        echo json_encode($rows, JSON_PRETTY_PRINT) . PHP_EOL;
        echo '--- END DISCOUNT DEBUG ---' . PHP_EOL;
    }
}
