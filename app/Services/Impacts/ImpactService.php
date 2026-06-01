<?php

namespace App\Services\Impacts;

use App\Models\Impact;
use App\Models\ImpactAction;
use App\Models\LifeImpactHistory;
use App\Models\Notification;
use App\Models\User;
use App\Services\LifeImpact\LifeImpactService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ImpactService
{
    public function __construct(
        private readonly ImpactUserNotificationService $notificationService,
        private readonly ImpactEmailService $emailService,
        private readonly LifeImpactService $lifeImpactService,
    ) {
    }

    public function submitImpact(User $user, array $data): Impact
    {
        $impactScore = $this->resolveSubmittedImpactScore($data);

        $impact = Impact::create([
            'user_id' => $user->id,
            'impacted_peer_id' => $data['impacted_peer_id'],
            'impact_date' => $data['date'] ?? now()->toDateString(),
            'action' => $data['action'],
            'story_to_share' => $data['story_to_share'],
            'life_impacted' => $impactScore,
            'additional_remarks' => $data['additional_remarks'] ?? null,
            'requires_leadership_approval' => (bool) config('impact.requires_leadership_approval', true),
            'status' => 'pending',
        ]);

        Log::info('impact.submitted', [
            'impact_id' => (string) $impact->id,
            'user_id' => (string) $user->id,
            'impacted_peer_id' => (string) $impact->impacted_peer_id,
        ]);

        $impact->loadMissing(['user', 'impactedPeer']);

        $this->notificationService->sendSubmitted($impact);
        $this->emailService->sendSubmitted($impact);

        return $impact;
    }

    public function approveImpact(Impact|string $impactOrId, User|string $adminOrId, ?string $reviewRemarks = null): Impact
    {
        return DB::transaction(function () use ($impactOrId, $adminOrId, $reviewRemarks) {
            $impactId = $impactOrId instanceof Impact ? (string) $impactOrId->getKey() : (string) $impactOrId;
            $adminId = $this->resolveAdminId($adminOrId);
            $actorUserId = $this->resolveValidUserActorId($adminId);

            $impact = Impact::query()->with('user')->lockForUpdate()->findOrFail($impactId);

            Log::info('impact.approve.started', [
                'impact_id' => (string) $impact->id,
                'old_status' => (string) $impact->status,
                'admin_id' => $adminId,
                'actor_user_id' => $actorUserId,
            ]);

            if ($impact->status === 'approved') {
                $this->lifeImpactService->recordApprovedImpactHistory($impact, $actorUserId);

                return $impact->fresh(['user', 'impactedPeer']);
            }

            if ($impact->status !== 'pending') {
                throw new \RuntimeException('Only pending impacts can be approved.');
            }

            $impact->status = 'approved';
            $impact->approved_by = $actorUserId;
            $impact->approved_at = now();
            $impact->timeline_posted_at = now();
            $impact->rejected_by = null;
            $impact->rejected_at = null;
            $impact->review_remarks = $reviewRemarks;
            $impact->save();

            Log::info('impact.approve.saved', [
                'impact_id' => (string) $impact->id,
                'status' => (string) $impact->status,
                'approved_by' => $impact->approved_by ? (string) $impact->approved_by : null,
                'approved_at' => optional($impact->approved_at)->toISOString(),
                'timeline_posted_at' => optional($impact->timeline_posted_at)->toISOString(),
            ]);

            try {
                $historyResult = $this->lifeImpactService->recordApprovedImpactHistory($impact, $actorUserId);
                $recalculatedTotal = (int) ($historyResult['total_life_impacted'] ?? 0);
            } catch (\Throwable $exception) {
                Log::error('impact.approval.failed', [
                    'impact_id' => (string) $impact->id,
                    'user_id' => (string) $impact->user_id,
                    'triggered_by_user_id' => (string) $impact->user_id,
                    'action' => (string) $impact->action,
                    'impact_value' => max(1, (int) ($impact->life_impacted ?? 1)),
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            Log::info('impact.approved', [
                'impact_id' => (string) $impact->id,
                'user_id' => (string) $impact->user_id,
                'approved_by' => $actorUserId,
            ]);

            Log::info('impact.life_impacted_incremented', [
                'impact_id' => (string) $impact->id,
                'user_id' => (string) $impact->user_id,
                'recalculated_total' => $recalculatedTotal,
            ]);

            $impact = $impact->fresh(['user', 'impactedPeer']);

            DB::afterCommit(function () use ($impact): void {
                try {
                    $this->notificationService->sendApproved($impact);
                    $this->emailService->sendApproved($impact);
                } catch (\Throwable $exception) {
                    Log::error('impact.approve.side_effect_failed', [
                        'impact_id' => (string) $impact->id,
                        'user_id' => (string) $impact->user_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

            Log::info('impact.approve.completed', [
                'impact_id' => (string) $impact->id,
                'final_status' => (string) $impact->status,
            ]);

            return $impact;
        });
    }

    public function rejectImpact(Impact|string $impactOrId, User|string $adminOrId, ?string $reviewRemarks = null): Impact
    {
        return DB::transaction(function () use ($impactOrId, $adminOrId, $reviewRemarks) {
            $impactId = $impactOrId instanceof Impact ? (string) $impactOrId->getKey() : (string) $impactOrId;
            $adminId = $this->resolveAdminId($adminOrId);
            $actorUserId = $this->resolveValidUserActorId($adminId);

            $impact = Impact::query()->lockForUpdate()->findOrFail($impactId);

            if ($impact->status === 'rejected') {
                return $impact;
            }

            if ($impact->status === 'approved') {
                throw new \RuntimeException('Approved impact cannot be rejected.');
            }

            $impact->status = 'rejected';
            $impact->rejected_by = $actorUserId;
            $impact->rejected_at = now();
            $impact->approved_by = null;
            $impact->approved_at = null;
            $impact->timeline_posted_at = null;
            $impact->review_remarks = $reviewRemarks;
            $impact->save();

            Log::info('impact.rejected', [
                'impact_id' => (string) $impact->id,
                'user_id' => (string) $impact->user_id,
                'rejected_by' => $actorUserId,
            ]);

            $this->notify((string) $impact->user_id, 'impact_rejected', [
                'impact_id' => (string) $impact->id,
                'title' => 'Impact rejected',
                'body' => 'Your impact was reviewed and rejected.',
                'review_remarks' => $reviewRemarks,
            ]);

            return $impact;
        });
    }


    private function resolveSubmittedImpactScore(array $data): int
    {
        $fallback = max(1, (int) ($data['life_impacted'] ?? 1));

        if (! Schema::hasTable('impact_actions')) {
            return $fallback;
        }

        $query = ImpactAction::query()->where('is_active', true);

        if (! empty($data['impact_action_id'])) {
            $impactAction = $query->where('id', (string) $data['impact_action_id'])->first(['impact_score']);

            if ($impactAction) {
                return max(1, (int) ($impactAction->impact_score ?? 1));
            }
        }

        $actionName = trim((string) ($data['action'] ?? ''));

        if ($actionName === '') {
            return $fallback;
        }

        $impactAction = ImpactAction::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [strtolower($actionName)])
            ->first(['impact_score']);

        if ($impactAction) {
            return max(1, (int) ($impactAction->impact_score ?? 1));
        }

        return 1;
    }

    private function resolveValidUserActorId(?string $actorId): ?string
    {
        if (! is_string($actorId) || trim($actorId) === '') {
            return null;
        }

        return User::query()->whereKey($actorId)->exists() ? $actorId : null;
    }

    private function resolveAdminId(User|string $adminOrId): string
    {
        if ($adminOrId instanceof User) {
            return (string) $adminOrId->getKey();
        }

        return (string) $adminOrId;
    }

    public function recalculateUserLifeImpactedCount(User|string $userOrId): int
    {
        $userId = $userOrId instanceof User ? (string) $userOrId->id : (string) $userOrId;

        $historyTable = (new LifeImpactHistory())->getTable();
        $sumExpression = Schema::hasColumn($historyTable, 'impact_value')
            ? 'COALESCE(impact_value, 0)'
            : (Schema::hasColumn($historyTable, 'life_impacted')
                ? 'COALESCE(life_impacted, 0)'
                : '0');

        $query = DB::table($historyTable)->where('user_id', $userId);

        if (Schema::hasColumn($historyTable, 'status')) {
            $query->where('status', 'approved');
        }

        $sum = (int) $query->sum(DB::raw($sumExpression));

        User::query()
            ->where('id', $userId)
            ->update([
                'life_impacted_count' => $sum,
                'updated_at' => now(),
            ]);

        return $sum;
    }

    private function notify(string $userId, string $type, array $payload): void
    {
        Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'payload' => $payload,
            'is_read' => false,
            'created_at' => now(),
            'read_at' => null,
        ]);
    }
}
