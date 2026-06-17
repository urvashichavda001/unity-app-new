<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('app_config_settings')) {
            foreach ([
                'logo_url_light' => fn (Blueprint $table) => $table->text('logo_url_light')->nullable(),
                'logo_url_dark' => fn (Blueprint $table) => $table->text('logo_url_dark')->nullable(),
                'logo_url_splash' => fn (Blueprint $table) => $table->text('logo_url_splash')->nullable(),
                'primary_dark_color' => fn (Blueprint $table) => $table->string('primary_dark_color')->nullable(),
                'primary_light_color' => fn (Blueprint $table) => $table->string('primary_light_color')->nullable(),
                'primary_ultra_light_color' => fn (Blueprint $table) => $table->string('primary_ultra_light_color')->nullable(),
                'secondary_light_color' => fn (Blueprint $table) => $table->string('secondary_light_color')->nullable(),
                'background_color' => fn (Blueprint $table) => $table->string('background_color')->nullable(),
                'background_light_color' => fn (Blueprint $table) => $table->string('background_light_color')->nullable(),
                'background_secondary_color' => fn (Blueprint $table) => $table->string('background_secondary_color')->nullable(),
                'background_dark_color' => fn (Blueprint $table) => $table->string('background_dark_color')->nullable(),
                'card_background_color' => fn (Blueprint $table) => $table->string('card_background_color')->nullable(),
                'card_border_color' => fn (Blueprint $table) => $table->string('card_border_color')->nullable(),
                'text_primary_color' => fn (Blueprint $table) => $table->string('text_primary_color')->nullable(),
                'text_secondary_color' => fn (Blueprint $table) => $table->string('text_secondary_color')->nullable(),
            ] as $column => $definition) {
                if (! Schema::hasColumn('app_config_settings', $column)) {
                    Schema::table('app_config_settings', fn (Blueprint $table) => $definition($table));
                }
            }
        }

        if (! Schema::hasTable('app_icon_assets')) {
            Schema::create('app_icon_assets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('app_instance_id');
                $table->string('icon_key', 100);
                $table->string('icon_name')->nullable();
                $table->text('icon_url')->nullable();
                $table->string('fallback_asset')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
                $table->unique(['app_instance_id', 'icon_key']);
            });
        }
    }

    public function down(): void {}
};
