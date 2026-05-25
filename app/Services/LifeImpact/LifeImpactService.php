<?php

namespace App\Services\LifeImpact;

use App\Models\Impact;
use App\Models\ImpactAction;
use App\Models\LifeImpactHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class LifeImpactService
{
    public function addLifeImpact(
        string $userId,
        ?string $triggeredByUserId,
        string $activityType,
        ?string $activityId = null,
        int $impactValue = 0,
        string $title = '',
        ?string $description = null,
        array $meta = [],
    ): int {
        $impactValue = (int) $impactValue;
        $activityId = (is_string($activityId) && Str::isUuid($activityId))
            ? $activityId
            : null;

        if ($impactValue <= 0) {
            return $this->getCurrentTotal($userId);
        }

        return (int) DB::transaction(function () use ($userId, $impactValue, $activityType, $title, $triggeredByUserId, $activityId, $description, $meta) {
            $historyTable = $this->lifeImpactHistoriesTable();

            if ($activityId !== null) {
                $existing = DB::table($historyTable)
                    ->where('user_id', $userId)
                    ->where('activity_type', $activityType)
                    ->where('activity_id', $activityId)
                    ->first();

                if ($existing) {
                    return $this->getCurrentTotal($userId);
                }
            }

            $normalizedMeta = null;
            if (! empty($meta)) {
                $encodedMeta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $normalizedMeta = $encodedMeta === false ? null : $encodedMeta;
            }

            $actionKey = Str::of($activityType)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
            $actionLabel = Str::of($activityType)->replace('_', ' ')->title()->value();

            $payload = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'triggered_by_user_id' => $triggeredByUserId,
                'activity_type' => $activityType,
                'activity_id' => $activityId,
                'impact_value' => $impactValue,
                'title' => $title,
                'description' => $description,
                'meta' => $normalizedMeta,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn($historyTable, 'life_impacted')) {
                $payload['life_impacted'] = $impactValue;
            }

            if (Schema::hasColumn($historyTable, 'counted_in_total')) {
                $payload['counted_in_total'] = true;
            }

            if (Schema::hasColumn($historyTable, 'impact_category')) {
                $payload['impact_category'] = $activityType;
            }

            if (Schema::hasColumn($historyTable, 'action_key')) {
                $payload['action_key'] = $actionKey !== '' ? $actionKey : 'referral_registration';
            }

            if (Schema::hasColumn($historyTable, 'action_label')) {
                $payload['action_label'] = $actionLabel !== '' ? $actionLabel : 'Referral Registration';
            }

            if (Schema::hasColumn($historyTable, 'remarks')) {
                $payload['remarks'] = $description
                    ?? ($title !== '' ? $title : 'Referral registration impact awarded.');
            }

            if (Schema::hasColumn($historyTable, 'status')) {
                $payload['status'] = 'approved';
            }

            try {
                DB::table($historyTable)->insert($payload);
            } catch (Throwable $exception) {
                if ($activityId !== null) {
                    $duplicate = DB::table($historyTable)
                        ->where('user_id', $userId)
                        ->where('activity_type', $activityType)
                        ->where('activity_id', $activityId)
                        ->exists();

                    if ($duplicate) {
                        Log::warning('Life impact duplicate insert avoided', [
                            'user_id' => $userId,
                            'activity_type' => $activityType,
                            'activity_id' => $activityId,
                            'error' => $exception->getMessage(),
                        ]);

                        return $this->getCurrentTotal($userId);
                    }
                }

                throw $exception;
            }

            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'life_impacted_count' => DB::raw('COALESCE(life_impacted_count, 0) + ' . $impactValue),
                    'updated_at' => now(),
                ]);

            return $this->getCurrentTotal($userId);
        });
    }

    public function incrementAndLog(
        string $userId,
        int $points,
        string $activityType,
        string $title,
        ?string $triggeredByUserId = null,
        ?string $activityId = null,
        ?string $description = null,
        ?array $meta = null,
    ): int {
        return $this->addLifeImpact(
            $userId,
            $triggeredByUserId,
            $activityType,
            $activityId,
            (int) $points,
            $title,
            $description,
            $meta ?? []
        );
    }

    public function getCurrentTotal(string $userId): int
    {
        return (int) (DB::table('users')->where('id', $userId)->value('life_impacted_count') ?? 0);
    }

    public function recordApprovedImpactHistory(Impact $impact, ?string $approvedByAdminId = null): array
    {
        $impactId = (string) $impact->id;
        $userId = (string) $impact->user_id;
        $triggeredByUserId = (string) $impact->user_id;
        $impactValue = (int) ($impact->life_impacted ?? 0);

        if ($impactValue <= 0) {
            $impactValue = $this->resolveImpactScore($impact);
        }

        $impactValue = max(1, $impactValue);
        $actionLabel = trim((string) ($impact->action ?? 'Impact Approved'));
        $actionKey = Str::of($actionLabel)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
        $remarks = $impact->additional_remarks ?: $impact->review_remarks;

        return DB::transaction(function () use (
            $impact,
            $impactId,
            $userId,
            $triggeredByUserId,
            $impactValue,
            $actionLabel,
            $actionKey,
            $remarks,
            $approvedByAdminId
        ): array {
            Log::info('impact.approval.started', [
                'impact_id' => $impactId,
                'user_id' => $userId,
                'triggered_by_user_id' => $triggeredByUserId,
                'action' => $actionKey,
            ]);

            $existing = LifeImpactHistory::query()
                ->where('activity_type', 'impact')
                ->where('activity_id', $impactId)
                ->where('user_id', $userId)
                ->first();

            if ($existing) {
                Log::info('impact.approval.history_exists', [
                    'impact_id' => $impactId,
                    'user_id' => $userId,
                    'history_id' => (string) $existing->id,
                ]);

                $total = $this->recomputeTotalFromHistory($userId);

                Log::info('impact.approval.total_recomputed', [
                    'impact_id' => $impactId,
                    'user_id' => $userId,
                    'total_life_impacted' => $total,
                ]);

                return [
                    'created' => false,
                    'history_id' => (string) $existing->id,
                    'total_life_impacted' => $total,
                ];
            }

            $metaPayload = array_filter([
                'impact_id' => $impactId,
                'impact_date' => optional($impact->impact_date)?->toDateString(),
                'action' => $this->normalizeNullableString($impact->action),
                'action_key' => $actionKey,
                'action_label' => $actionLabel,
                'impact_value' => $impactValue,
                'impacted_peer_id' => $impact->impacted_peer_id ? (string) $impact->impacted_peer_id : null,
                'affected_user_id' => $impact->impacted_peer_id ? (string) $impact->impacted_peer_id : null,
                'story_to_share' => $this->normalizeNullableString($impact->story_to_share),
                'additional_remarks' => $this->normalizeNullableString($impact->additional_remarks),
                'review_remarks' => $this->normalizeNullableString($impact->review_remarks),
                'approved_by' => $approvedByAdminId,
                'approved_at' => optional($impact->approved_at)->toISOString(),
            ], fn ($value) => $value !== null && $value !== '');

            $title = $this->normalizeNullableString($actionLabel) ?? 'Impact Approved';
            $description = $this->normalizeNullableString('Impact approved: '.($actionLabel !== '' ? $actionLabel : 'Impact action'));
            $normalizedRemarks = $this->normalizeNullableString($remarks);
            $meta = null;
            if (! empty($metaPayload)) {
                $encodedMeta = json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $meta = $encodedMeta === false ? null : $encodedMeta;
            }

            Log::info('impact.approval.payload_types', [
                'impact_id' => $impactId,
                'title_type' => gettype($title),
                'description_type' => gettype($description),
                'remarks_type' => gettype($normalizedRemarks),
                'meta_payload_type' => gettype($metaPayload),
            ]);

            $payload = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'triggered_by_user_id' => $triggeredByUserId !== '' ? $triggeredByUserId : null,
                'activity_type' => 'impact',
                'activity_id' => $impactId,
                'impact_value' => $impactValue,
                'title' => $title,
                'description' => $description,
                'meta' => $meta,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn($this->lifeImpactHistoriesTable(), 'life_impacted')) {
                $payload['life_impacted'] = $impactValue;
            }

            if (Schema::hasColumn($this->lifeImpactHistoriesTable(), 'counted_in_total')) {
                $payload['counted_in_total'] = true;
            }

            if (Schema::hasColumn($this->lifeImpactHistoriesTable(), 'impact_category')) {
                $payload['impact_category'] = null;
            }

            if (Schema::hasColumn($this->lifeImpactHistoriesTable(), 'action_key')) {
                $payload['action_key'] = $actionKey !== '' ? $actionKey : null;
            }

            if (Schema::hasColumn($this->lifeImpactHistoriesTable(), 'action_label')) {
                $payload['action_label'] = $actionLabel !== '' ? $actionLabel : null;
            }

            if (Schema::hasColumn($this->lifeImpactHistoriesTable(), 'remarks')) {
                $payload['remarks'] = $normalizedRemarks;
            }

            DB::table($this->lifeImpactHistoriesTable())->insert($payload);

            $historyId = (string) $payload['id'];
            $total = $this->recomputeTotalFromHistory($userId);

            Log::info('impact.approval.history_created', [
                'impact_id' => $impactId,
                'user_id' => $userId,
                'history_id' => $historyId,
                'impact_value' => $impactValue,
            ]);

            Log::info('impact.approval.total_recomputed', [
                'impact_id' => $impactId,
                'user_id' => $userId,
                'total_life_impacted' => $total,
            ]);

            return [
                'created' => true,
                'history_id' => $historyId,
                'total_life_impacted' => $total,
            ];
        });
    }

    public function recomputeTotalFromHistory(string $userId): int
    {
        $query = DB::table($this->lifeImpactHistoriesTable())->where('user_id', $userId);

        if (Schema::hasColumn($this->lifeImpactHistoriesTable(), 'counted_in_total')) {
            $query->where(function ($subQuery): void {
                $subQuery->where('counted_in_total', true)
                    ->orWhereNull('counted_in_total');
            });
        }

        $sumExpression = Schema::hasColumn($this->lifeImpactHistoriesTable(), 'life_impacted')
            ? 'COALESCE(life_impacted, impact_value, 0)'
            : 'COALESCE(impact_value, 0)';

        $sum = (int) $query->sum(DB::raw($sumExpression));

        DB::table('users')
            ->where('id', $userId)
            ->update([
                'life_impacted_count' => $sum,
                'updated_at' => now(),
            ]);

        return $sum;
    }

    private function resolveImpactScore(Impact $impact): int
    {
        $legacyLifeImpacted = (int) ($impact->life_impacted ?? 0);

        if (! Schema::hasTable('impact_actions')) {
            return max(1, $legacyLifeImpacted ?: 1);
        }

        $actionName = trim((string) ($impact->action ?? ''));

        if ($actionName === '') {
            return max(1, $legacyLifeImpacted ?: 1);
        }

        $impactAction = ImpactAction::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($actionName)])
            ->first(['impact_score']);

        $dynamicScore = (int) ($impactAction?->impact_score ?? 0);

        if ($dynamicScore > 0) {
            return $dynamicScore;
        }

        return max(1, $legacyLifeImpacted ?: 1);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (is_array($value) || is_object($value)) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function lifeImpactHistoriesTable(): string
    {
        $searchPath = (string) config('database.connections.' . config('database.default') . '.search_path', 'public');
        $schema = trim((string) explode(',', $searchPath)[0], " \t\n\r\0\x0B\"");

        return ($schema !== '' ? $schema . '.' : '') . 'life_impact_histories';
    }
}
