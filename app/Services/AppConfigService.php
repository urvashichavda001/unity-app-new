<?php

namespace App\Services;

use App\Models\AppInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class AppConfigService
{
    public const GREENPRENEUR_SLUG = 'greenpreneur';

    public function getGreenpreneurAppInstance(): AppInstance
    {
        if (! Schema::hasTable('app_instances')) {
            throw new RuntimeException('The app_instances table is not available.');
        }

        $row = DB::table('app_instances')
            ->where('slug', self::GREENPRENEUR_SLUG)
            ->first();

        if ($row) {
            $this->activateExistingInstance((string) $row->id, $row);

            $row = DB::table('app_instances')
                ->where('id', $row->id)
                ->first();

            return AppInstance::query()->newFromBuilder((array) $row);
        }

        $id = (string) Str::uuid();
        DB::table('app_instances')->insert($this->instancePayload($id));

        $row = DB::table('app_instances')->where('id', $id)->first();

        return AppInstance::query()->newFromBuilder((array) $row);
    }

    private function activateExistingInstance(string $id, object $row): void
    {
        $updates = [];

        if (Schema::hasColumn('app_instances', 'name') && empty($row->name)) {
            $updates['name'] = 'Greenpreneur';
        }

        if (Schema::hasColumn('app_instances', 'display_name') && empty($row->display_name)) {
            $updates['display_name'] = 'Greenpreneur';
        }

        if (Schema::hasColumn('app_instances', 'is_active')) {
            $updates['is_active'] = true;
        }

        if (Schema::hasColumn('app_instances', 'updated_at')) {
            $updates['updated_at'] = now();
        }

        if ($updates !== []) {
            DB::table('app_instances')->where('id', $id)->update($updates);
        }
    }

    private function instancePayload(string $id): array
    {
        $payload = ['id' => $id];

        foreach ([
            'name' => 'Greenpreneur',
            'slug' => self::GREENPRENEUR_SLUG,
            'display_name' => 'Greenpreneur',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ] as $column => $value) {
            if (Schema::hasColumn('app_instances', $column)) {
                $payload[$column] = $value;
            }
        }

        return $payload;
    }
}
