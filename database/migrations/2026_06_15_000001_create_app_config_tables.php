<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->createOrUpdateAppConfigSettings();
        $this->createOrUpdateAppLabels();
        $this->createOrUpdateAppFeatures();
        $this->createOrUpdateAppNavigationItems();
        $this->createOrUpdateAppDashboardWidgets();
        $this->createOrUpdateAppSocialLinks();
        $this->createOrUpdateAppMembershipLabels();
    }

    public function down(): void
    {
        // Do not drop existing Greenpreneur config tables because several deployments
        // already have app_config_settings/app_features/app_labels in production.
    }

    private function createOrUpdateAppConfigSettings(): void
    {
        if (! Schema::hasTable('app_config_settings')) {
            Schema::create('app_config_settings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('app_key')->unique()->default('greenpreneur');
                $table->string('app_name')->default('Greenpreneur');
                $table->text('app_logo_url')->nullable();
                $table->text('splash_logo_url')->nullable();
                $table->string('primary_color')->default('#2E7D32');
                $table->string('secondary_color')->default('#81C784');
                $table->string('accent_color')->default('#FFC107');
                $table->string('splash_bg_color')->default('#FFFFFF');
                $table->string('button_color')->default('#2E7D32');
                $table->string('text_color')->default('#212121');
                $table->text('playstore_url')->nullable();
                $table->text('appstore_url')->nullable();
                $table->text('website_url')->nullable()->default('https://greenpreneur.in');
                $table->string('support_email')->nullable();
                $table->string('support_phone')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
            });

            return;
        }

        $this->addMissingColumns('app_config_settings', [
            'app_key' => fn (Blueprint $table) => $table->string('app_key')->default('greenpreneur'),
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
    }

    private function createOrUpdateAppLabels(): void
    {
        if (! Schema::hasTable('app_labels')) {
            Schema::create('app_labels', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('label_key')->unique();
                $table->text('label_value');
                $table->string('group_name')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
            });

            return;
        }

        $this->addMissingColumns('app_labels', [
            'label_key' => fn (Blueprint $table) => $table->string('label_key'),
            'label_value' => fn (Blueprint $table) => $table->text('label_value')->nullable(),
            'group_name' => fn (Blueprint $table) => $table->string('group_name')->nullable(),
            'description' => fn (Blueprint $table) => $table->text('description')->nullable(),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);
    }

    private function createOrUpdateAppFeatures(): void
    {
        if (! Schema::hasTable('app_features')) {
            Schema::create('app_features', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('feature_key')->unique();
                $table->string('feature_name');
                $table->text('description')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
            });

            return;
        }

        $this->addMissingColumns('app_features', [
            'feature_key' => fn (Blueprint $table) => $table->string('feature_key'),
            'feature_name' => fn (Blueprint $table) => $table->string('feature_name')->nullable(),
            'description' => fn (Blueprint $table) => $table->text('description')->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);
    }

    private function createOrUpdateAppNavigationItems(): void
    {
        if (! Schema::hasTable('app_navigation_items')) {
            Schema::create('app_navigation_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('menu_type');
                $table->string('item_key');
                $table->string('label_key')->nullable();
                $table->string('display_label');
                $table->string('icon')->nullable();
                $table->string('route_name')->nullable();
                $table->string('feature_key')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
            });

            return;
        }

        $this->addMissingColumns('app_navigation_items', [
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
    }

    private function createOrUpdateAppDashboardWidgets(): void
    {
        if (! Schema::hasTable('app_dashboard_widgets')) {
            Schema::create('app_dashboard_widgets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('widget_key')->unique();
                $table->string('widget_name');
                $table->string('label_key')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
            });

            return;
        }

        $this->addMissingColumns('app_dashboard_widgets', [
            'widget_key' => fn (Blueprint $table) => $table->string('widget_key')->nullable(),
            'widget_name' => fn (Blueprint $table) => $table->string('widget_name')->nullable(),
            'label_key' => fn (Blueprint $table) => $table->string('label_key')->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);
    }

    private function createOrUpdateAppSocialLinks(): void
    {
        if (! Schema::hasTable('app_social_links')) {
            Schema::create('app_social_links', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('platform')->unique();
                $table->string('display_name');
                $table->text('url')->nullable();
                $table->string('icon')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
            });

            return;
        }

        $this->addMissingColumns('app_social_links', [
            'platform' => fn (Blueprint $table) => $table->string('platform')->nullable(),
            'display_name' => fn (Blueprint $table) => $table->string('display_name')->nullable(),
            'url' => fn (Blueprint $table) => $table->text('url')->nullable(),
            'icon' => fn (Blueprint $table) => $table->string('icon')->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ]);
    }

    private function createOrUpdateAppMembershipLabels(): void
    {
        if (Schema::hasTable('app_membership_labels')) {
            return;
        }

        Schema::create('app_membership_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('membership_key')->unique();
            $table->string('display_label');
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
        });
    }

    private function addMissingColumns(string $tableName, array $columns): void
    {
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, fn (Blueprint $table) => $definition($table));
            }
        }
    }
};
