<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\Circle;
use App\Models\CircleMember;
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

    public static function resolveCircleId(?AdminUser $admin): ?string
    {
        return self::allowedCircleIds($admin)[0] ?? null;
    }

    public static function allowedCircleIds(?AdminUser $admin): array
    {
        if (! $admin || ! AdminAccess::isCircleScoped($admin)) {
            return [];
        }

        $user = AdminAccess::resolveAppUser($admin);
        if (! $user) {
            return [];
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

        return $query->orderByRaw("case circle_members.role::text {$orderCases} else 999 end")
            ->orderBy('circle_members.created_at')
            ->pluck('circle_members.circle_id')
            ->unique()
            ->values()
            ->all();
    }

    public static function circleOptions(?AdminUser $admin)
    {
        $query = Circle::query()->select(['id', 'name'])->orderBy('name');

        if (AdminAccess::isDed($admin)) {
            $district = AdminAccess::assignedDedDistrict($admin);

            if (! $district) {
                return collect();
            }

            return $query->whereExists(function ($subQuery) use ($district): void {
                $subQuery->selectRaw('1')
                    ->from('cities')
                    ->whereColumn('cities.id', 'circles.city_id');

                self::applyDistrictCriteria($subQuery, 'cities', $district);
            })->get();
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return $query->get();
        }

        $circleIds = self::allowedCircleIds($admin);

        if ($circleIds === []) {
            return collect();
        }

        return $query->whereIn('id', $circleIds)->get();
    }

    public static function circleUserIdsSubquery(string|array $circleIds): Builder
    {
        $circleIds = is_array($circleIds) ? $circleIds : [$circleIds];

        return CircleMember::query()
            ->select('user_id')
            ->whereIn('circle_id', $circleIds)
            ->where('status', 'approved')
            ->whereNull('deleted_at');
    }

    public static function applyToActivityQuery($query, ?AdminUser $admin, string $primaryColumn, ?string $peerColumn): void
    {
        if (AdminAccess::isDed($admin)) {
            self::applyDistrictUserScope($query, $admin, $primaryColumn);
            return;
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return;
        }

        $circleIds = self::allowedCircleIds($admin);

        if ($circleIds === []) {
            $query->whereRaw('1=0');
            return;
        }

        $circleUserIds = self::circleUserIdsSubquery($circleIds);

        $query->whereIn($primaryColumn, $circleUserIds);
    }

    public static function applyToUsersQuery($query, ?AdminUser $admin): void
    {
        if (AdminAccess::isDed($admin)) {
            self::applyDistrictUserScope($query, $admin, 'users.id');
            return;
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return;
        }

        $circleIds = self::allowedCircleIds($admin);

        if ($circleIds === []) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereExists(function ($subQuery) use ($circleIds) {
            $subQuery->selectRaw(1)
                ->from('circle_members as cm')
                ->whereColumn('cm.user_id', 'users.id')
                ->where('cm.status', 'approved')
                ->whereNull('cm.deleted_at')
                ->whereIn('cm.circle_id', $circleIds);
        });
    }


    public static function applyRequestedCircleFilter($query, ?AdminUser $admin, string $userColumn, ?string $circleId): void
    {
        $circleId = trim((string) $circleId);

        if ($circleId === '' || $circleId === 'all') {
            return;
        }

        if (AdminAccess::isDed($admin) && ! self::circleBelongsToDedDistrict($admin, $circleId)) {
            $query->whereRaw('1=0');
            return;
        }

        if (AdminAccess::isCircleScoped($admin) && ! in_array($circleId, self::allowedCircleIds($admin), true)) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereExists(function ($subQuery) use ($userColumn, $circleId): void {
            $subQuery->selectRaw('1')
                ->from('circle_members as cm_filter')
                ->whereColumn('cm_filter.user_id', $userColumn)
                ->where('cm_filter.status', 'approved')
                ->whereNull('cm_filter.deleted_at')
                ->where('cm_filter.circle_id', $circleId);
        });
    }

    public static function userInScope(?AdminUser $admin, string $userId): bool
    {
        if (AdminAccess::isDed($admin)) {
            $district = AdminAccess::assignedDedDistrict($admin);

            if (! $district) {
                return false;
            }

            $query = DB::table('users')
                ->join('cities', 'cities.id', '=', 'users.city_id')
                ->where('users.id', $userId);

            self::applyDistrictCriteria($query, 'cities', $district);

            return $query->exists();
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return true;
        }

        $circleIds = self::allowedCircleIds($admin);

        if ($circleIds === []) {
            return false;
        }

        return CircleMember::query()
            ->where('user_id', $userId)
            ->whereIn('circle_id', $circleIds)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->exists();
    }

    public static function circleBelongsToDedDistrict(?AdminUser $admin, string $circleId): bool
    {
        $district = AdminAccess::assignedDedDistrict($admin);

        if (! $district) {
            return false;
        }

        return Circle::query()
            ->where('circles.id', $circleId)
            ->whereExists(function ($subQuery) use ($district): void {
                $subQuery->selectRaw('1')
                    ->from('cities')
                    ->whereColumn('cities.id', 'circles.city_id');

                self::applyDistrictCriteria($subQuery, 'cities', $district);
            })
            ->exists();
    }

    private static function applyDistrictCriteria($query, string $cityAlias, array $district): void
    {
        $query->whereRaw("LOWER({$cityAlias}.district) = ?", [mb_strtolower((string) $district['name'])]);

        if (! empty($district['state'])) {
            $query->whereRaw("LOWER(COALESCE({$cityAlias}.state, '')) = ?", [mb_strtolower((string) $district['state'])]);
        }

        if (! empty($district['country'])) {
            $query->whereRaw("LOWER(COALESCE({$cityAlias}.country, '')) = ?", [mb_strtolower((string) $district['country'])]);
        }
    }

    private static function applyDistrictUserScope($query, ?AdminUser $admin, string $userColumn): void
    {
        $district = AdminAccess::assignedDedDistrict($admin);

        if (! $district) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereExists(function ($subQuery) use ($userColumn, $district): void {
            $subQuery->selectRaw('1')
                ->from('users as district_scope_users')
                ->join('cities as district_scope_cities', 'district_scope_cities.id', '=', 'district_scope_users.city_id')
                ->whereColumn('district_scope_users.id', $userColumn);

            self::applyDistrictCriteria($subQuery, 'district_scope_cities', $district);
        });
    }
}
