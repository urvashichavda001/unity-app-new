<?php

use App\Support\GreenpreneurIconCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        }

        if (! Schema::hasTable('app_icon_assets')) {
            Schema::create('app_icon_assets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('app_instance_id');
                $table->string('icon_key', 150);
                $table->string('icon_name')->nullable();
                $table->string('icon_group', 100)->nullable();
                $table->string('source_type', 50)->nullable();
                $table->string('icon_library', 100)->nullable();
                $table->string('default_icon')->nullable();
                $table->string('selected_icon')->nullable();
                $table->text('icon_url')->nullable();
                $table->text('selected_icon_url')->nullable();
                $table->string('fallback_asset')->nullable();
                $table->string('feature_key', 100)->nullable();
                $table->string('menu_key', 100)->nullable();
                $table->string('screen_name', 150)->nullable();
                $table->string('usage_location')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
                $table->unique(['app_instance_id', 'icon_key'], 'app_icon_assets_app_instance_icon_key_unique');
            });
        } else {
            foreach ($this->columns() as $column => $definition) {
                if (! Schema::hasColumn('app_icon_assets', $column)) {
                    Schema::table('app_icon_assets', fn (Blueprint $table) => $definition($table));
                }
            }
        }

        $this->ensureUniqueIndex();

        $this->seedGreenpreneurIcons();
    }

    public function down(): void {}

    private function columns(): array
    {
        return [
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable(),
            'icon_key' => fn (Blueprint $table) => $table->string('icon_key', 150)->nullable(),
            'icon_name' => fn (Blueprint $table) => $table->string('icon_name')->nullable(),
            'icon_group' => fn (Blueprint $table) => $table->string('icon_group', 100)->nullable(),
            'source_type' => fn (Blueprint $table) => $table->string('source_type', 50)->nullable(),
            'icon_library' => fn (Blueprint $table) => $table->string('icon_library', 100)->nullable(),
            'default_icon' => fn (Blueprint $table) => $table->string('default_icon')->nullable(),
            'selected_icon' => fn (Blueprint $table) => $table->string('selected_icon')->nullable(),
            'icon_url' => fn (Blueprint $table) => $table->text('icon_url')->nullable(),
            'selected_icon_url' => fn (Blueprint $table) => $table->text('selected_icon_url')->nullable(),
            'fallback_asset' => fn (Blueprint $table) => $table->string('fallback_asset')->nullable(),
            'feature_key' => fn (Blueprint $table) => $table->string('feature_key', 100)->nullable(),
            'menu_key' => fn (Blueprint $table) => $table->string('menu_key', 100)->nullable(),
            'screen_name' => fn (Blueprint $table) => $table->string('screen_name', 150)->nullable(),
            'usage_location' => fn (Blueprint $table) => $table->string('usage_location')->nullable(),
            'description' => fn (Blueprint $table) => $table->text('description')->nullable(),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ];
    }


    private function ensureUniqueIndex(): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS app_icon_assets_app_instance_icon_key_unique ON app_icon_assets (app_instance_id, icon_key)');
                return;
            }

            Schema::table('app_icon_assets', function (Blueprint $table) {
                $table->unique(['app_instance_id', 'icon_key'], 'app_icon_assets_app_instance_icon_key_unique');
            });
        } catch (Throwable) {
            // Existing deployments may already have this unique constraint under a database-generated name.
        }
    }

    private function seedGreenpreneurIcons(): void
    {
        $appInstanceId = DB::table('app_instances')->where('slug', 'greenpreneur')->value('id');
        if (! $appInstanceId) {
            return;
        }

        foreach (GreenpreneurIconCatalog::rows() as $row) {
            DB::table('app_icon_assets')->updateOrInsert(
                ['app_instance_id' => $appInstanceId, 'icon_key' => $row['icon_key']],
                array_merge([
                    'id' => (string) Str::uuid(),
                    'icon_url' => null,
                    'selected_icon_url' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $row, ['updated_at' => now()])
            );
        }
    }
};
