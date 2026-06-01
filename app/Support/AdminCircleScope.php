<?php

namespace App\Support;

use App\Models\CircleMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminCircleScope
{
    private const ROLE_PRIORITY = [
        'circle_leader' => 0,
        'chair' => 1,
        'vice_chair' => 2,
        'secretary' => 3,
        'founder' => 4,
        'director' => 5,
        'committee_leader' => 6,
        'member' => 7,
    ];

    public static function resolveCircleId(?User $admin): ?string
    {
        if (! $admin || ! AdminAccess::isCircleScoped($admin)) {
            return null;
        }

        $user = AdminAccess::resolveAppUser($admin);
        if (! $user) {
            return null;
        }

        $roles = array_keys(self::ROLE_PRIORITY);
        $orderCases = collect(self::ROLE_PRIORITY)
            ->map(fn ($priority, $role) => "when '{$role}' then {$priority}")
            ->implode(' ');

        $query = CircleMember::query()
            ->select('circle_members.circle_id')
            ->where('circle_members.user_id', $user->id)
            ->where('circle_members.status', 'approved')
            ->whereNull('circle_members.deleted_at')
            ->whereIn(DB::raw('circle_members.role::text'), $roles);

        if (Schema::hasColumn('circles', 'status')) {
            $query->leftJoin('circles', 'circles.id', '=', 'circle_members.circle_id')
                ->orderByRaw("case when circles.status = 'active' then 0 else 1 end");
        }

        $query->orderByRaw("case circle_members.role::text {$orderCases} else 999 end")
            ->orderBy('circle_members.created_at');

        return $query->value('circle_members.circle_id');
    }

    public static function circleUserIdsSubquery(string $circleId): Builder
    {
        return CircleMember::query()
            ->select('user_id')
            ->where('circle_id', $circleId)
            ->where('status', 'approved')
            ->whereNull('deleted_at');
    }

    public static function applyToActivityQuery($query, ?User $admin, string $primaryColumn, ?string $peerColumn): void
    {
        if (! AdminAccess::isCircleScoped($admin)) {
            return;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            $query->whereRaw('1=0');
            return;
        }

        $circleUserIds = self::circleUserIdsSubquery($circleId);

        $query->whereIn($primaryColumn, $circleUserIds);
    }

    public static function applyToUsersQuery($query, ?User $admin): void
    {
        if (! AdminAccess::isCircleScoped($admin)) {
            return;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereExists(function ($subQuery) use ($circleId) {
            $subQuery->selectRaw(1)
                ->from('circle_members as cm')
                ->whereColumn('cm.user_id', 'users.id')
                ->where('cm.status', 'approved')
                ->whereNull('cm.deleted_at')
                ->where('cm.circle_id', $circleId);
        });
    }

    public static function userInScope(?User $admin, string $userId): bool
    {
        if (! AdminAccess::isCircleScoped($admin)) {
            return true;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            return false;
        }

        return CircleMember::query()
            ->where('user_id', $userId)
            ->where('circle_id', $circleId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->exists();
    }
}
