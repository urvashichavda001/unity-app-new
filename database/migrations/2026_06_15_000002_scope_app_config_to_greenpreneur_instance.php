<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    private const APP_INSTANCE_TABLE = 'app_instances';
    private const GREENPRENEUR_SLUG = 'greenpreneur';

    public function up(): void
    {
        $this->ensureAppInstancesTable();
        $appInstanceId = $this->ensureGreenpreneurAppInstance();

        $this->ensureConfigTables($appInstanceId);
    }

    public function down(): void
    {
        // Intentionally no-op: this migration only makes existing app config data
        // safer by adding nullable scope columns and backfilling Greenpreneur rows.
    }

    private function ensureAppInstancesTable(): void
    {
        if (! Schema::hasTable(self::APP_INSTANCE_TABLE)) {
            Schema::create(self::APP_INSTANCE_TABLE, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('display_name')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
            });

            return;
        }

        $this->addMissingColumns(self::APP_INSTANCE_TABLE, [
            'name' => fn (Blueprint $table) => $table->string('name')->nullable(),
            'slug' => fn (Blueprint $table) => $table->string('slug')->nullable(),
            'display_name' => fn (Blueprint $table) => $table->string('display_name')->nullable(),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);
    }

    private function ensureGreenpreneurAppInstance(): string
    {
        $existing = DB::table(self::APP_INSTANCE_TABLE)
            ->where('slug', self::GREENPRENEUR_SLUG)
            ->first();

        if ($existing) {
            DB::table(self::APP_INSTANCE_TABLE)
                ->where('id', $existing->id)
                ->update([
                    'name' => $existing->name ?: 'Greenpreneur',
                    'display_name' => $existing->display_name ?: 'Greenpreneur',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            return (string) $existing->id;
        }

        $id = (string) Str::uuid();

        DB::table(self::APP_INSTANCE_TABLE)->insert([
            'id' => $id,
            'name' => 'Greenpreneur',
            'slug' => self::GREENPRENEUR_SLUG,
            'display_name' => 'Greenpreneur',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function ensureConfigTables(string $appInstanceId): void
    {
        $this->ensureAppConfigSettings($appInstanceId);
        $this->ensureAppLabels($appInstanceId);
        $this->ensureAppFeatures($appInstanceId);
        $this->ensureAppNavigationItems($appInstanceId);
        $this->ensureAppDashboardWidgets($appInstanceId);
        $this->ensureAppSocialLinks($appInstanceId);
    }

    private function ensureAppConfigSettings(string $appInstanceId): void
    {
        $this->ensureTable('app_config_settings', [
            'id' => fn (Blueprint $table) => $table->uuid('id')->primary(),
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable()->index(),
            'app_key' => fn (Blueprint $table) => $table->string('app_key')->default(self::GREENPRENEUR_SLUG),
            'app_name' => fn (Blueprint $table) => $table->string('app_name')->default('Greenpreneur'),
            'app_logo_url' => fn (Blueprint $table) => $table->text('app_logo_url')->nullable(),
            'splash_logo_url' => fn (Blueprint $table) => $table->text('splash_logo_url')->nullable(),
            'primary_color' => fn (Blueprint $table) => $table->string('primary_color')->default('#2E7D32'),
            'secondary_color' => fn (Blueprint $table) => $table->string('secondary_color')->default('#81C784'),
            'accent_color' => fn (Blueprint $table) => $table->string('accent_color')->default('#FFC107'),
            'splash_bg_color' => fn (Blueprint $table) => $table->string('splash_bg_color')->default('#FFFFFF'),
            'button_color' => fn (Blueprint $table) => $table->string('button_color')->default('#2E7D32'),
            'text_color' => fn (Blueprint $table) => $table->string('text_color')->default('#212121'),
            'playstore_url' => fn (Blueprint $table) => $table->text('playstore_url')->nullable(),
            'appstore_url' => fn (Blueprint $table) => $table->text('appstore_url')->nullable(),
            'website_url' => fn (Blueprint $table) => $table->text('website_url')->nullable()->default('https://greenpreneur.in'),
            'support_email' => fn (Blueprint $table) => $table->string('support_email')->nullable(),
            'support_phone' => fn (Blueprint $table) => $table->string('support_phone')->nullable(),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);

        $this->backfillAppInstanceId('app_config_settings', $appInstanceId);
    }

    private function ensureAppLabels(string $appInstanceId): void
    {
        $this->ensureTable('app_labels', [
            'id' => fn (Blueprint $table) => $table->uuid('id')->primary(),
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable()->index(),
            'label_key' => fn (Blueprint $table) => $table->string('label_key'),
            'label_value' => fn (Blueprint $table) => $table->text('label_value')->nullable(),
            'group_name' => fn (Blueprint $table) => $table->string('group_name')->nullable(),
            'description' => fn (Blueprint $table) => $table->text('description')->nullable(),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);

        $this->backfillAppInstanceId('app_labels', $appInstanceId);
    }

    private function ensureAppFeatures(string $appInstanceId): void
    {
        $this->ensureTable('app_features', [
            'id' => fn (Blueprint $table) => $table->uuid('id')->primary(),
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable()->index(),
            'feature_key' => fn (Blueprint $table) => $table->string('feature_key'),
            'feature_name' => fn (Blueprint $table) => $table->string('feature_name')->nullable(),
            'description' => fn (Blueprint $table) => $table->text('description')->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);

        $this->backfillAppInstanceId('app_features', $appInstanceId);
    }

    private function ensureAppNavigationItems(string $appInstanceId): void
    {
        $this->ensureTable('app_navigation_items', [
            'id' => fn (Blueprint $table) => $table->uuid('id')->primary(),
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable()->index(),
            'menu_type' => fn (Blueprint $table) => $table->string('menu_type')->nullable(),
            'item_key' => fn (Blueprint $table) => $table->string('item_key')->nullable(),
            'label_key' => fn (Blueprint $table) => $table->string('label_key')->nullable(),
            'display_label' => fn (Blueprint $table) => $table->string('display_label')->nullable(),
            'icon' => fn (Blueprint $table) => $table->string('icon')->nullable(),
            'route_name' => fn (Blueprint $table) => $table->string('route_name')->nullable(),
            'feature_key' => fn (Blueprint $table) => $table->string('feature_key')->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);

        $this->backfillAppInstanceId('app_navigation_items', $appInstanceId);
    }

    private function ensureAppDashboardWidgets(string $appInstanceId): void
    {
        $this->ensureTable('app_dashboard_widgets', [
            'id' => fn (Blueprint $table) => $table->uuid('id')->primary(),
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable()->index(),
            'widget_key' => fn (Blueprint $table) => $table->string('widget_key')->nullable(),
            'widget_name' => fn (Blueprint $table) => $table->string('widget_name')->nullable(),
            'label_key' => fn (Blueprint $table) => $table->string('label_key')->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);

        $this->backfillAppInstanceId('app_dashboard_widgets', $appInstanceId);
    }

    private function ensureAppSocialLinks(string $appInstanceId): void
    {
        $this->ensureTable('app_social_links', [
            'id' => fn (Blueprint $table) => $table->uuid('id')->primary(),
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable()->index(),
            'platform' => fn (Blueprint $table) => $table->string('platform')->nullable(),
            'display_name' => fn (Blueprint $table) => $table->string('display_name')->nullable(),
            'url' => fn (Blueprint $table) => $table->text('url')->nullable(),
            'icon' => fn (Blueprint $table) => $table->string('icon')->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);

        $this->backfillAppInstanceId('app_social_links', $appInstanceId);
    }

    private function ensureTable(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $definition) {
                    $definition($table);
                }
            });

            return;
        }

        $this->addMissingColumns($tableName, $columns);
    }

    private function addMissingColumns(string $tableName, array $columns): void
    {
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, fn (Blueprint $table) => $definition($table));
            }
        }
    }

    private function backfillAppInstanceId(string $tableName, string $appInstanceId): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'app_instance_id')) {
            return;
        }

        DB::table($tableName)
            ->whereNull('app_instance_id')
            ->update(['app_instance_id' => $appInstanceId]);
    }
};
