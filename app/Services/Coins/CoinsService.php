<?php

namespace App\Services\Coins;

use App\Models\CoinsLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CoinsService
{
    public function rewardForActivity(
        User $user,
        string $activityType,
        $activityId = null,
        ?string $reference = null,
        ?string $createdBy = null,
        ?string $sourceType = null,
        mixed $sourceId = null
    ): ?CoinsLedger {
        $amount = config('coins.activity_rewards')[$activityType] ?? 0;

        if ($amount === 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $activityType, $activityId, $reference, $createdBy, $sourceType, $sourceId, $amount) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            if ($sourceType !== null && $sourceId !== null && $this->hasSourceColumns()) {
                $existingLedger = CoinsLedger::query()
                    ->where('user_id', $user->id)
                    ->where('source_type', $sourceType)
                    ->where('source_id', (string) $sourceId)
                    ->first();

                if ($existingLedger) {
                    return $existingLedger;
                }
            }

            $newBalance = $user->coins_balance + $amount;

            $user->update([
                'coins_balance' => $newBalance,
            ]);

            $ledgerData = [
                'transaction_id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference' => $reference ?? ucfirst(str_replace('_', ' ', $activityType)) . ' reward',
                'created_by' => $createdBy ?? $user->id,
                'created_at' => now(),
            ];

            if ($sourceType !== null && $sourceId !== null && $this->hasSourceColumns()) {
                $ledgerData['source_type'] = $sourceType;
                $ledgerData['source_id'] = (string) $sourceId;
            }

            return CoinsLedger::create($ledgerData);
        });
    }

    public function reward(User $user, int $amount, string $reference, array|string|null $meta = null, ?string $createdBy = null): ?CoinsLedger
    {
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $amount, $reference, $meta, $createdBy) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $newBalance = $user->coins_balance + $amount;

            $user->update([
                'coins_balance' => $newBalance,
            ]);

            $resolvedCreatedBy = $createdBy;
            $remark = null;

            if (is_string($meta) && $resolvedCreatedBy === null) {
                // Backward compatibility for old call sites passing createdBy as 4th argument.
                $resolvedCreatedBy = $meta;
            } elseif (is_array($meta) && ! empty($meta)) {
                $encodedMeta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $remark = $encodedMeta === false ? null : $encodedMeta;
            }

            $ledgerData = [
                'transaction_id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference' => $reference,
                'remark' => $remark,
                'created_by' => $resolvedCreatedBy ?? $user->id,
                'created_at' => now(),
            ];

            return CoinsLedger::create($ledgerData);
        });
    }

    private function hasSourceColumns(): bool
    {
        return Schema::hasColumn('coins_ledger', 'source_type')
            && Schema::hasColumn('coins_ledger', 'source_id');
    }
}

