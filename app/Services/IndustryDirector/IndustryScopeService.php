<?php

namespace App\Services\IndustryDirector;

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IndustryScopeService
{
    public function assignedIndustryIdForAdmin($adminUserId): ?string
    {
        if (! $adminUserId || ! Schema::hasTable('industry_director_assignments')) {
            return null;
        }

        $industryId = DB::table('industry_director_assignments')
            ->where('admin_user_id', (string) $adminUserId)
            ->where('is_active', true)
            ->value('industry_id');

        return $industryId ? (string) $industryId : null;
    }

    public function industryIdsForAdmin($adminUserId): array
    {
        $assignedIndustryId = $this->assignedIndustryIdForAdmin($adminUserId);

        if (! $assignedIndustryId || ! Schema::hasTable('industries')) {
            return [];
        }

        $industryIds = collect([$assignedIndustryId]);
        $frontier = collect([$assignedIndustryId]);

        while ($frontier->isNotEmpty() && Schema::hasColumn('industries', 'parent_id')) {
            $children = DB::table('industries')
                ->whereIn('parent_id', $frontier->all())
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->reject(fn (string $id) => $industryIds->contains($id))
                ->values();

            if ($children->isEmpty()) {
                break;
            }

            $industryIds = $industryIds->merge($children)->unique()->values();
            $frontier = $children;
        }

        return $industryIds->all();
    }

    public function memberIdsForIndustry($adminUserId): array
    {
        $industryIds = $this->industryIdsForAdmin($adminUserId);
        $memberIds = $this->memberIdsForIndustryIds($industryIds);

        Log::info('IDE scope debug', [
            'admin_user_id' => (string) $adminUserId,
            'assigned_industry_id' => $this->assignedIndustryIdForAdmin($adminUserId),
            'industry_ids' => $industryIds,
            'scoped_member_count' => count($memberIds),
        ]);

        return $memberIds;
    }

    public function applyPeersScope($query, $adminUserId)
    {
        $industryIds = $this->industryIdsForAdmin($adminUserId);

        return $this->applyUsersIndustryColumns($query, $industryIds, 'users');
    }

    public function applyPostsScope($query, $adminUserId)
    {
        return $this->applyUserIdScope($query, $this->memberIdsForIndustry($adminUserId), ['posts.user_id']);
    }

    public function applyActivitiesScope($query, $adminUserId)
    {
        return $this->applyUserIdScope($query, $this->memberIdsForIndustry($adminUserId), ['user_id']);
    }

    public function applyCoinsScope($query, $adminUserId)
    {
        return $this->applyUserIdScope($query, $this->memberIdsForIndustry($adminUserId), ['coins_ledger.user_id', 'user_id']);
    }

    public function applyLifeImpactScope($query, $adminUserId)
    {
        return $this->applyUserIdScope($query, $this->memberIdsForIndustry($adminUserId), ['life_impact_histories.user_id', 'user_id']);
    }

    public function isIndustryDirector(?AdminUser $adminUser): bool
    {
        if (! $adminUser) {
            return false;
        }

        $adminUser->loadMissing('roles:id,key');

        return $adminUser->roles->pluck('key')->contains('industry_director')
            && $this->assignedIndustryIdForAdmin($adminUser->id) !== null;
    }

    public function memberIdsForAdmin(?AdminUser $adminUser): array
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return [];
        }

        return $this->memberIdsForIndustry($adminUser->id);
    }

    public function circleIdsForAdmin(?AdminUser $adminUser): array
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return [];
        }

        return $this->circleIdsForIndustryIds($this->industryIdsForAdmin($adminUser->id));
    }

    public function circleIdsForIndustryIds(array $industryIds): array
    {
        $industryIds = $this->cleanIds($industryIds);

        if ($industryIds === [] || ! Schema::hasTable('circles')) {
            return [];
        }

        $query = DB::table('circles')->select('circles.id')->distinct();

        $scopeValues = $this->industryScopeValues($industryIds);

        $query->where(function ($scope) use ($industryIds, $scopeValues): void {
            $hasCondition = false;

            $hasCondition = $this->orWhereColumnIn($scope, 'circles', 'industry_id', $industryIds) || $hasCondition;
            $hasCondition = $this->orWhereJsonContainsAny($scope, 'circles', 'industry_tags', $scopeValues) || $hasCondition;

            if (Schema::hasTable('circle_category_mappings')) {
                $categoryIds = $this->idsForColumn('circle_category_mappings', 'category_id', $industryIds);

                if ($categoryIds !== []) {
                    $scope->orWhereExists(function ($subQuery) use ($categoryIds): void {
                        $subQuery->selectRaw('1')
                            ->from('circle_category_mappings as ide_ccm')
                            ->whereColumn('ide_ccm.circle_id', 'circles.id')
                            ->whereIn('ide_ccm.category_id', $categoryIds);
                    });
                    $hasCondition = true;
                }
            }

            if (! $hasCondition) {
                $scope->whereRaw('1 = 0');
            }
        });

        if (Schema::hasColumn('circles', 'deleted_at')) {
            $query->whereNull('circles.deleted_at');
        }

        return $query->pluck('circles.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public function applyToUsersQuery($query, ?AdminUser $adminUser, string $userColumn = 'users.id'): void
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return;
        }

        if ($userColumn === 'users.id') {
            $this->applyPeersScope($query, $adminUser->id);
            return;
        }

        $memberIds = $this->memberIdsForIndustry($adminUser->id);
        $this->applyUserIdScope($query, $memberIds, [$userColumn]);
    }

    public function applyToActivityQuery($query, ?AdminUser $adminUser, array $userColumns): void
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return;
        }

        $this->applyUserIdScope($query, $this->memberIdsForIndustry($adminUser->id), $userColumns);
    }

    public function userInScope(?AdminUser $adminUser, string $userId): bool
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return true;
        }

        return in_array((string) $userId, $this->memberIdsForIndustry($adminUser->id), true);
    }

    public function circleInScope(?AdminUser $adminUser, string $circleId): bool
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return true;
        }

        return in_array((string) $circleId, $this->circleIdsForAdmin($adminUser), true);
    }

    private function memberIdsForIndustryIds(array $industryIds): array
    {
        $industryIds = $this->cleanIds($industryIds);

        if ($industryIds === [] || ! Schema::hasTable('users')) {
            return [];
        }

        $query = DB::table('users')->select('users.id')->distinct();
        $this->applyUsersIndustryColumns($query, $industryIds, 'users');

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('users.deleted_at');
        }

        return $query->pluck('users.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function applyUsersIndustryColumns($query, array $industryIds, string $table)
    {
        $industryIds = $this->cleanIds($industryIds);

        if ($industryIds === []) {
            $query->whereRaw('1 = 0');
            return $query;
        }

        $scopeValues = $this->industryScopeValues($industryIds);
        $circleIds = $this->circleIdsForIndustryIds($industryIds);

        $query->where(function ($scope) use ($table, $industryIds, $scopeValues, $circleIds): void {
            $hasCondition = false;

            foreach ([
                'industry_id',
                'business_category_main_id',
                'business_category_sub_id',
                'visitor_business_category_main_id',
                'visitor_business_category_sub_id',
                'main_business_category_id',
                'business_category_id',
            ] as $column) {
                $hasCondition = $this->orWhereColumnIn($scope, $table, $column, $industryIds) || $hasCondition;
            }

            $hasCondition = $this->orWhereJsonContainsAny($scope, $table, 'industry_tags', $scopeValues) || $hasCondition;
            $hasCondition = $this->orWhereJsonContainsAny($scope, $table, 'industries_of_interest', $scopeValues) || $hasCondition;

            if ($circleIds !== []) {
                $hasCondition = $this->orWhereColumnIn($scope, $table, 'circle_id', $circleIds) || $hasCondition;

                if ($table === 'users' && Schema::hasTable('circle_members') && Schema::hasColumn('circle_members', 'user_id') && Schema::hasColumn('circle_members', 'circle_id')) {
                    $scope->orWhereExists(function ($subQuery) use ($circleIds): void {
                        $subQuery->selectRaw('1')
                            ->from('circle_members as ide_cm')
                            ->whereColumn('ide_cm.user_id', 'users.id')
                            ->whereIn('ide_cm.circle_id', $circleIds);

                        if (Schema::hasColumn('circle_members', 'status')) {
                            $subQuery->where('ide_cm.status', config('circle.member_joined_status', 'approved'));
                        }

                        if (Schema::hasColumn('circle_members', 'left_at')) {
                            $subQuery->whereNull('ide_cm.left_at');
                        }

                        if (Schema::hasColumn('circle_members', 'deleted_at')) {
                            $subQuery->whereNull('ide_cm.deleted_at');
                        }
                    });

                    $hasCondition = true;
                }
            }

            if (! $hasCondition) {
                $scope->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    private function applyUserIdScope($query, array $memberIds, array $userColumns)
    {
        $memberIds = $this->cleanIds($memberIds);

        if ($memberIds === []) {
            $query->whereRaw('1 = 0');
            return $query;
        }

        $query->where(function ($scope) use ($userColumns, $memberIds): void {
            $hasCondition = false;

            foreach ($userColumns as $userColumn) {
                $userColumn = trim((string) $userColumn);

                if ($userColumn === '') {
                    continue;
                }

                $scope->orWhereIn($userColumn, $memberIds);
                $hasCondition = true;
            }

            if (! $hasCondition) {
                $scope->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    private function orWhereColumnIn($query, string $table, string $column, array $ids): bool
    {
        $compatibleIds = $this->idsForColumn($table, $column, $ids);

        if ($compatibleIds === []) {
            return false;
        }

        $query->orWhereIn("{$table}.{$column}", $compatibleIds);

        return true;
    }


    private function industryScopeValues(array $industryIds): array
    {
        $industryIds = $this->cleanIds($industryIds);
        $values = collect($industryIds);

        if ($industryIds !== [] && Schema::hasTable('industries')) {
            $columns = ['id'];

            foreach (['name', 'slug'] as $column) {
                if (Schema::hasColumn('industries', $column)) {
                    $columns[] = $column;
                }
            }

            DB::table('industries')
                ->select($columns)
                ->whereIn('id', $industryIds)
                ->get()
                ->each(function ($industry) use ($columns, $values): void {
                    foreach ($columns as $column) {
                        $value = trim((string) ($industry->{$column} ?? ''));

                        if ($value !== '') {
                            $values->push($value);
                        }
                    }
                });
        }

        return $values
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function orWhereJsonContainsAny($query, string $table, string $column, array $ids): bool
    {
        if (! Schema::hasColumn($table, $column)) {
            return false;
        }

        $hasCondition = false;

        foreach ($this->cleanIds($ids) as $id) {
            $query->orWhereJsonContains("{$table}.{$column}", $id);
            $hasCondition = true;
        }

        return $hasCondition;
    }

    private function idsForColumn(string $table, string $column, array $ids): array
    {
        if (! Schema::hasColumn($table, $column)) {
            return [];
        }

        $columnType = $this->columnType($table, $column);
        $ids = $this->cleanIds($ids);

        if (str_contains($columnType, 'int')) {
            return array_values(array_filter($ids, fn (string $id) => ctype_digit($id)));
        }

        if (str_contains($columnType, 'uuid')) {
            return array_values(array_filter($ids, fn (string $id) => Str::isUuid($id)));
        }

        return $ids;
    }

    private function columnType(string $table, string $column): string
    {
        try {
            return strtolower((string) DB::table('information_schema.columns')
                ->where('table_schema', $this->schemaName())
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('data_type'));
        } catch (\Throwable) {
            try {
                return strtolower((string) Schema::getColumnType($table, $column));
            } catch (\Throwable) {
                return '';
            }
        }
    }

    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $ids,
        ), fn (string $id) => $id !== '')));
    }

    private function schemaName(): string
    {
        $schema = (string) config('database.connections.' . config('database.default') . '.search_path', 'public');
        $schema = trim((string) explode(',', $schema)[0], " \t\n\r\0\x0B\"");

        return $schema !== '' ? $schema : 'public';
    }
}
