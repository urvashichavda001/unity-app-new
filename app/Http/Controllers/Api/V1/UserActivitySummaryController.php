<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserActivitySummaryController extends BaseApiController
{
    public function summary(string $user_id): JsonResponse
    {
        if (! Str::isUuid($user_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid user id.',
            ], 422);
        }

        $user = DB::table('users')
            ->select(['id', 'created_at'])
            ->where('id', $user_id)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $fromDate = Carbon::parse($user->created_at);
        $toDate = Carbon::now();

        $activities = [
            [
                'key' => 'find_build_collaborations',
                'label' => 'Find & Build Collaborations',
                'count' => $this->countFindBuildCollaborations($user_id, $fromDate, $toDate),
            ],
            [
                'key' => 'business_deals',
                'label' => 'Business Deals',
                'count' => $this->countUndeletedTableRows('business_deals', 'from_user_id', $user_id, $fromDate, $toDate),
            ],
            [
                'key' => 'p2p_meetings',
                'label' => 'P2P Meetings',
                'count' => $this->countUndeletedTableRows('p2p_meetings', 'initiator_user_id', $user_id, $fromDate, $toDate),
            ],
            [
                'key' => 'requirements',
                'label' => 'Requirements',
                'count' => $this->countRequirements($user_id, $fromDate, $toDate),
            ],
            [
                'key' => 'referrals',
                'label' => 'Referrals',
                'count' => $this->countUndeletedTableRows('referrals', 'from_user_id', $user_id, $fromDate, $toDate),
            ],
            [
                'key' => 'testimonials',
                'label' => 'Testimonials',
                'count' => $this->countUndeletedTableRows('testimonials', 'from_user_id', $user_id, $fromDate, $toDate),
            ],
            [
                'key' => 'claim_coins',
                'label' => 'Claimed Coins',
                'count' => $this->countClaimedCoins($user_id, $fromDate, $toDate),
            ],
            [
                'key' => 'visitor_registrations',
                'label' => 'Visitor Registrations',
                'count' => $this->countTableRows('visitor_registrations', 'user_id', $user_id, $fromDate, $toDate),
            ],
        ];

        return $this->success([
            'user_id' => (string) $user->id,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'total_activity_count' => array_sum(array_column($activities, 'count')),
            'activities' => $activities,
        ], 'User activity summary fetched successfully.');
    }

    private function countFindBuildCollaborations(string $userId, Carbon $fromDate, Carbon $toDate): int
    {
        $types = $this->findBuildActivityTypes();

        if ($types === []) {
            return 0;
        }

        return (int) DB::table('activities')
            ->where('user_id', $userId)
            ->whereIn('type', $types)
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->count();
    }

    private function findBuildActivityTypes(): array
    {
        if (! Schema::hasTable('activities')) {
            return [];
        }

        $configuredTypes = $this->postgresEnumValues('activity_type_enum');
        $knownFindBuildTypes = [
            'find_build_collaboration',
            'find_build_collaborations',
            'find_and_build_collaboration',
            'find_and_build_collaborations',
            'need_help_growing',
        ];

        if ($configuredTypes !== []) {
            return array_values(array_intersect($knownFindBuildTypes, $configuredTypes));
        }

        return [];
    }

    private function postgresEnumValues(string $enumName): array
    {
        try {
            return DB::table('pg_enum')
                ->join('pg_type', 'pg_type.oid', '=', 'pg_enum.enumtypid')
                ->where('pg_type.typname', $enumName)
                ->orderBy('pg_enum.enumsortorder')
                ->pluck('pg_enum.enumlabel')
                ->map(fn ($value) => (string) $value)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function countRequirements(string $userId, Carbon $fromDate, Carbon $toDate): int
    {
        $query = $this->baseCountQuery('requirements', 'user_id', $userId, $fromDate, $toDate);

        if (Schema::hasColumn('requirements', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function countClaimedCoins(string $userId, Carbon $fromDate, Carbon $toDate): int
    {
        return (int) $this->baseCountQuery('coins_ledger', 'user_id', $userId, $fromDate, $toDate)
            ->where('amount', '>', 0)
            ->count();
    }

    private function countUndeletedTableRows(string $table, string $userColumn, string $userId, Carbon $fromDate, Carbon $toDate): int
    {
        $query = $this->baseCountQuery($table, $userColumn, $userId, $fromDate, $toDate);

        if (Schema::hasColumn($table, 'is_deleted')) {
            $query->where('is_deleted', false);
        }

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function countTableRows(string $table, string $userColumn, string $userId, Carbon $fromDate, Carbon $toDate): int
    {
        return (int) $this->baseCountQuery($table, $userColumn, $userId, $fromDate, $toDate)->count();
    }

    private function baseCountQuery(string $table, string $userColumn, string $userId, Carbon $fromDate, Carbon $toDate)
    {
        return DB::table($table)
            ->where($userColumn, $userId)
            ->whereBetween('created_at', [$fromDate, $toDate]);
    }
}
