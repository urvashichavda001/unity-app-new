<?php

namespace App\Support;

use App\Models\AdminDedDistrict;
use App\Models\AdminUser;
use App\Models\CircleMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAccess
{
    private const CACHE_TTL = 300;

    private const SUPER_ROLE_KEYS = [
        'global_admin',
        'industry_director',
    ];

    private const CIRCLE_SCOPED_KEYS = [
        'circle_leader',
        'chair',
        'vice_chair',
        'secretary',
        'founder',
        'director',
        'member',
    ];

    private const CIRCLE_ROLE_PRIORITY = [
        'chair' => 1,
        'vice_chair' => 2,
        'secretary' => 3,
        'founder' => 4,
        'director' => 5,
        'committee_leader' => 6,
        'member' => 7,
    ];

    private const CIRCLE_ROLE_LABELS = [
        'chair' => 'Chair',
        'vice_chair' => 'Vice Chair',
        'secretary' => 'Secretary',
        'founder' => 'Founder',
        'director' => 'Director',
        'committee_leader' => 'Committee Leader',
        'member' => 'Member',
    ];

    public static function resolveAppUser(?AdminUser $admin): ?User
    {
        if (! $admin) {
            return null;
        }

        $cacheKey = 'admin-access:user:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $email = trim(strtolower((string) $admin->email));
            if ($email === '') {
                return null;
            }

            return User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        });
    }

    public static function adminRoleKeys(?AdminUser $admin): array
    {
        if (! $admin) {
            return [];
        }

        $cacheKey = 'admin-access:roles:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            return Role::query()
                ->join('admin_user_roles', 'admin_user_roles.role_id', '=', 'roles.id')
                ->where('admin_user_roles.user_id', $admin->id)
                ->pluck('roles.key')
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function isSuper(?AdminUser $admin): bool
    {
        $roleKeys = self::adminRoleKeys($admin);

        return (bool) array_intersect(self::SUPER_ROLE_KEYS, $roleKeys);
    }

    public static function isGlobalAdmin(?AdminUser $admin): bool
    {
        if (! $admin) {
            return false;
        }

        return in_array('global_admin', self::adminRoleKeys($admin), true);
    }

    public static function isDed(?AdminUser $admin): bool
    {
        if (! $admin || self::isSuper($admin)) {
            return false;
        }

        return in_array('ded', self::adminRoleKeys($admin), true);
    }

    public static function assignedDedDistrictId(?AdminUser $admin): ?string
    {
        if (! $admin || ! Schema::hasTable('admin_ded_districts')) {
            return null;
        }

        $cacheKey = 'admin-access:ded-district:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            return AdminDedDistrict::query()
                ->where('admin_user_id', $admin->id)
                ->value('district_id');
        });
    }

    public static function assignedDedDistrict(?AdminUser $admin): ?array
    {
        if (! $admin || ! Schema::hasTable('admin_ded_districts') || ! Schema::hasTable('districts')) {
            return null;
        }

        $cacheKey = 'admin-access:ded-district-details:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $district = AdminDedDistrict::query()
                ->join('districts', 'districts.id', '=', 'admin_ded_districts.district_id')
                ->where('admin_ded_districts.admin_user_id', $admin->id)
                ->first(['districts.id', 'districts.name', 'districts.state', 'districts.country']);

            return $district ? [
                'id' => (string) $district->id,
                'name' => (string) $district->name,
                'state' => $district->state ? (string) $district->state : null,
                'country' => $district->country ? (string) $district->country : null,
            ] : null;
        });
    }

    public static function assignedDedDistrictName(?AdminUser $admin): ?string
    {
        return self::assignedDedDistrict($admin)['name'] ?? null;
    }

    public static function isCircleScoped(?AdminUser $admin): bool
    {
        if (! $admin || self::isSuper($admin)) {
            return false;
        }

        $roleKeys = self::adminRoleKeys($admin);
        $hasCircleRoleKey = (bool) array_intersect(self::CIRCLE_SCOPED_KEYS, $roleKeys);

        if ($hasCircleRoleKey) {
            return true;
        }

        $user = self::resolveAppUser($admin);

        if (! $user) {
            return false;
        }

        $allowedRoles = array_keys(self::CIRCLE_ROLE_PRIORITY);

        return CircleMember::query()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->whereIn(DB::raw('circle_members.role::text'), $allowedRoles)
            ->exists();
    }

    public static function allowedCircleIds(?AdminUser $admin): array
    {
        if (! $admin) {
            return [];
        }

        $cacheKey = 'admin-access:circles:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $user = self::resolveAppUser($admin);
            if (! $user) {
                return [];
            }

            return CircleMember::query()
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->pluck('circle_id')
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function allowedUserIds(?AdminUser $admin): array
    {
        if (! $admin) {
            return [];
        }

        $cacheKey = 'admin-access:allowed-users:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $allowedCircleIds = self::allowedCircleIds($admin);
            if ($allowedCircleIds === []) {
                return [];
            }

            return CircleMember::query()
                ->whereIn('circle_id', $allowedCircleIds)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function primaryCircleRoleKey(?AdminUser $admin): ?string
    {
        if (! $admin) {
            return null;
        }

        $cacheKey = 'admin-access:primary-role:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $user = self::resolveAppUser($admin);
            if (! $user) {
                return null;
            }

            $allowedCircleIds = self::allowedCircleIds($admin);
            if ($allowedCircleIds === []) {
                return null;
            }

            $roles = array_keys(self::CIRCLE_ROLE_PRIORITY);
            $orderCases = collect(self::CIRCLE_ROLE_PRIORITY)
                ->map(fn ($priority, $role) => "when '{$role}' then {$priority}")
                ->implode(' ');

            return CircleMember::query()
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->whereIn('circle_id', $allowedCircleIds)
                ->whereIn(DB::raw('circle_members.role::text'), $roles)
                ->orderByRaw("case circle_members.role::text {$orderCases} else 999 end")
                ->limit(1)
                ->value(DB::raw('circle_members.role::text'));
        });
    }

    public static function primaryCircleRoleLabel(?AdminUser $admin): string
    {
        $roleKey = self::primaryCircleRoleKey($admin);

        if (! $roleKey) {
            return 'Circle Leader';
        }

        return self::CIRCLE_ROLE_LABELS[$roleKey] ?? 'Circle Leader';
    }

    public static function clearCache(?AdminUser $admin): void
    {
        if (! $admin) {
            return;
        }

        foreach ([
            'admin-access:user:' . $admin->id,
            'admin-access:roles:' . $admin->id,
            'admin-access:ded-district:' . $admin->id,
            'admin-access:ded-district-details:' . $admin->id,
            'admin-access:ded-district-name:' . $admin->id,
            'admin-access:circles:' . $admin->id,
            'admin-access:allowed-users:' . $admin->id,
            'admin-access:primary-role:' . $admin->id,
        ] as $key) {
            Cache::forget($key);
        }
    }

    public static function canEditUsers(?AdminUser $admin): bool
    {
        if (! $admin) {
            return false;
        }

        return in_array('global_admin', self::adminRoleKeys($admin), true);
    }
}
