<?php

namespace App\Services\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DedLocationService
{
    private const INVALID_LOCATION_VALUES = [
        '',
        '-',
        '--',
        'n/a',
        'na',
        'none',
        'null',
        'no city',
        'unknown',
        'not available',
        'not applicable',
    ];

    public function getAvailableStates(): Collection
    {
        $states = collect();

        if (Schema::hasTable('states') && Schema::hasColumn('states', 'name')) {
            $states = DB::table('states')
                ->when(Schema::hasColumn('states', 'status'), fn (Builder $query) => $query->where('status', 'active'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (object $state): object => (object) [
                    'id' => (string) $state->id,
                    'name' => $this->displayName($state->name),
                ]);
        }

        $discovered = $this->collectStateNamesFromApplicationData();
        if ($discovered->isEmpty()) {
            return $states->values();
        }

        $knownByName = $states->keyBy(fn (object $state): string => $this->normalizedKey($state->name));

        foreach ($discovered as $stateName) {
            $key = $this->normalizedKey($stateName);

            if ($key === '' || $knownByName->has($key)) {
                continue;
            }

            $knownByName->put($key, (object) [
                'id' => $stateName,
                'name' => $stateName,
            ]);
        }

        return $knownByName
            ->values()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function getAvailableDistrictsByState(?string $stateId): Collection
    {
        $stateName = $this->resolveStateName($stateId);
        $districts = $this->collectDistrictNamesFromApplicationData($stateName);

        if ($districts->isEmpty() && $stateName) {
            $districts = $this->collectDistrictNamesFromApplicationData(null);
        }

        $tableDistricts = $this->collectDistrictsTableRows($stateId, $stateName);
        $byName = collect();

        foreach ($districts as $districtName) {
            $key = $this->normalizedKey($districtName);
            if ($key === '') {
                continue;
            }

            $byName->put($key, (object) [
                'id' => $districtName,
                'name' => $districtName,
                'district_name' => $districtName,
                'district_id' => null,
            ]);
        }

        foreach ($tableDistricts as $district) {
            $name = $this->displayName($district->name ?? '');
            $key = $this->normalizedKey($name);

            if ($key === '') {
                continue;
            }

            $existing = $byName->get($key);
            $byName->put($key, (object) [
                'id' => $existing->id ?? $name,
                'name' => $name,
                'district_name' => $name,
                'district_id' => (string) ($district->id ?? ''),
            ]);
        }

        return $byName
            ->values()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function normalizeDistrictName(?string $value): ?string
    {
        $name = $this->displayName($value);

        return $this->isUsableLocationName($name) ? $name : null;
    }

    public function getAssignedDedDistrict(string $adminUserId): ?object
    {
        if (! Schema::hasTable('admin_ded_districts')) {
            return null;
        }

        $query = DB::table('admin_ded_districts')
            ->where('admin_ded_districts.admin_user_id', $adminUserId);

        if (Schema::hasTable('districts') && Schema::hasColumn('admin_ded_districts', 'district_id')) {
            $query->leftJoin('districts', 'districts.id', '=', 'admin_ded_districts.district_id');
        }

        if (Schema::hasTable('states') && Schema::hasColumn('admin_ded_districts', 'state_id')) {
            $query->leftJoin('states', 'states.id', '=', 'admin_ded_districts.state_id');
        }

        $selects = ['admin_ded_districts.admin_user_id'];
        foreach (['state_id', 'district_id', 'state_name', 'district_name'] as $column) {
            if (Schema::hasColumn('admin_ded_districts', $column)) {
                $selects[] = 'admin_ded_districts.' . $column;
            }
        }

        if (Schema::hasTable('districts') && Schema::hasColumn('admin_ded_districts', 'district_id')) {
            $selects[] = 'districts.name as districts_table_name';
        }

        if (Schema::hasTable('states') && Schema::hasColumn('admin_ded_districts', 'state_id')) {
            $selects[] = 'states.name as states_table_name';
        }

        $assignment = $query->select($selects)->first();

        if (! $assignment) {
            return null;
        }

        $districtName = $this->normalizeDistrictName($assignment->district_name ?? null)
            ?: $this->normalizeDistrictName($assignment->districts_table_name ?? null);
        $stateName = $this->normalizeDistrictName($assignment->state_name ?? null)
            ?: $this->normalizeDistrictName($assignment->states_table_name ?? null);

        return (object) [
            'state_id' => $assignment->state_id ?? null,
            'state_name' => $stateName,
            'district_id' => $assignment->district_id ?? null,
            'district_name' => $districtName,
        ];
    }

    public function applyDedDistrictScope($query, ?string $districtName, string $userColumn = 'users.city'): void
    {
        $districtName = $this->normalizeDistrictName($districtName);

        if (! $districtName) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereRaw('LOWER(NULLIF(TRIM(' . $userColumn . "), '')) = ?", [Str::lower($districtName)]);
    }

    public function resolveDistrictId(?string $districtName, ?string $stateId = null): ?string
    {
        $districtName = $this->normalizeDistrictName($districtName);

        if (! $districtName || ! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'name')) {
            return null;
        }

        $query = DB::table('districts')
            ->whereRaw("LOWER(NULLIF(TRIM(name), '')) = ?", [Str::lower($districtName)]);

        if ($stateId && Schema::hasColumn('districts', 'state_id')) {
            $query->where('state_id', $stateId);
        }

        if (Schema::hasColumn('districts', 'status')) {
            $query->where('status', 'active');
        }

        return $query->value('id') ?: null;
    }

    public function resolveStateName(?string $stateId): ?string
    {
        if (! $stateId || $stateId === 'all') {
            return null;
        }

        if (Schema::hasTable('states') && Schema::hasColumn('states', 'name')) {
            $state = DB::table('states')
                ->where('id', $stateId)
                ->value('name');

            if ($state) {
                return $this->displayName($state);
            }
        }

        return $this->normalizeDistrictName($stateId);
    }

    private function collectStateNamesFromApplicationData(): Collection
    {
        $values = collect();

        $values = $values->merge($this->pluckDistinctText('users', 'state'));
        $values = $values->merge($this->pluckDistinctText('users', 'business_state'));
        $values = $values->merge($this->pluckDistinctText('circles', 'state'));
        $values = $values->merge($this->pluckDistinctText('events', 'state'));

        if (Schema::hasTable('cities')) {
            $values = $values->merge($this->pluckDistinctText('cities', 'state'));

            if (Schema::hasTable('users') && Schema::hasColumn('users', 'city_id')) {
                $values = $values->merge($this->pluckDistinctJoinedCityText('users', 'state'));
            }

            if (Schema::hasTable('circles') && Schema::hasColumn('circles', 'city_id')) {
                $values = $values->merge($this->pluckDistinctJoinedCityText('circles', 'state'));
            }
        }

        return $this->uniqueUsableNames($values);
    }

    private function collectDistrictNamesFromApplicationData(?string $stateName): Collection
    {
        $values = collect();

        foreach ([['circles', 'district'], ['circles', 'city'], ['users', 'district'], ['users', 'city'], ['users', 'business_city']] as [$table, $column]) {
            $values = $values->merge($this->pluckDistinctText($table, $column, $stateName));
        }

        if (Schema::hasTable('cities')) {
            $values = $values->merge($this->pluckDistinctText('cities', 'district', $stateName));
            $values = $values->merge($this->pluckDistinctText('cities', 'name', $stateName));

            if (Schema::hasTable('circles') && Schema::hasColumn('circles', 'city_id')) {
                $values = $values->merge($this->pluckDistinctJoinedCityText('circles', 'district', $stateName));
                $values = $values->merge($this->pluckDistinctJoinedCityText('circles', 'name', $stateName));
            }

            if (Schema::hasTable('users') && Schema::hasColumn('users', 'city_id')) {
                $values = $values->merge($this->pluckDistinctJoinedCityText('users', 'district', $stateName));
                $values = $values->merge($this->pluckDistinctJoinedCityText('users', 'name', $stateName));
            }
        }

        foreach ([['requirements', 'city'], ['requirements', 'city_name'], ['requirements', 'district'], ['events', 'city'], ['events', 'district']] as [$table, $column]) {
            $values = $values->merge($this->pluckDistinctText($table, $column, $stateName));
        }

        return $this->uniqueUsableNames($values);
    }

    private function collectDistrictsTableRows(?string $stateId, ?string $stateName): Collection
    {
        if (! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'name')) {
            return collect();
        }

        $query = DB::table('districts')
            ->when(Schema::hasColumn('districts', 'status'), fn (Builder $builder) => $builder->where('status', 'active'));

        if ($stateId && $stateId !== 'all' && Schema::hasColumn('districts', 'state_id')) {
            $query->where('state_id', $stateId);
        } elseif ($stateName && Schema::hasColumn('districts', 'state_id') && Schema::hasTable('states')) {
            $query->whereExists(function (Builder $subQuery) use ($stateName): void {
                $subQuery->selectRaw(1)
                    ->from('states')
                    ->whereColumn('states.id', 'districts.state_id')
                    ->whereRaw("LOWER(NULLIF(TRIM(states.name), '')) = ?", [Str::lower($stateName)]);
            });
        }

        return $query->orderBy('name')->get(['id', 'name']);
    }

    private function pluckDistinctText(string $table, string $column, ?string $stateName = null): Collection
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return collect();
        }

        $query = DB::table($table)
            ->whereNotNull($column)
            ->whereRaw("NULLIF(TRIM({$column}::text), '') IS NOT NULL");

        $this->applyOptionalStateFilter($query, $table, $stateName);

        return $query->distinct()->pluck($column);
    }

    private function pluckDistinctJoinedCityText(string $ownerTable, string $cityColumn, ?string $stateName = null): Collection
    {
        if (! Schema::hasTable($ownerTable)
            || ! Schema::hasTable('cities')
            || ! Schema::hasColumn($ownerTable, 'city_id')
            || ! Schema::hasColumn('cities', $cityColumn)) {
            return collect();
        }

        $query = DB::table($ownerTable)
            ->join('cities', 'cities.id', '=', $ownerTable . '.city_id')
            ->whereNotNull('cities.' . $cityColumn)
            ->whereRaw("NULLIF(TRIM(cities.{$cityColumn}::text), '') IS NOT NULL");

        if ($stateName && Schema::hasColumn('cities', 'state')) {
            $query->whereRaw("LOWER(NULLIF(TRIM(cities.state::text), '')) = ?", [Str::lower($stateName)]);
        }

        return $query->distinct()->pluck('cities.' . $cityColumn);
    }

    private function applyOptionalStateFilter(Builder $query, string $table, ?string $stateName): void
    {
        if (! $stateName) {
            return;
        }

        if (Schema::hasColumn($table, 'state')) {
            $query->whereRaw("LOWER(NULLIF(TRIM({$table}.state::text), '')) = ?", [Str::lower($stateName)]);
        }
    }

    private function uniqueUsableNames(Collection $values): Collection
    {
        $byKey = collect();

        foreach ($values as $value) {
            $name = $this->normalizeDistrictName(is_scalar($value) ? (string) $value : null);
            $key = $this->normalizedKey($name);

            if ($key === '' || $byKey->has($key)) {
                continue;
            }

            $byKey->put($key, $name);
        }

        return $byKey
            ->values()
            ->sortBy(fn (string $name): string => $name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function isUsableLocationName(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return ! in_array($this->normalizedKey($value), self::INVALID_LOCATION_VALUES, true);
    }

    private function displayName(?string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));
        $value = trim($value, '"');

        if ($value === '') {
            return '';
        }

        return Str::title(Str::lower($value));
    }

    private function normalizedKey(?string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim((string) $value)));
    }
}
