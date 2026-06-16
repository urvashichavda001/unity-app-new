<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('app_social_links')) {
            Schema::create('app_social_links', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('app_instance_id')->nullable()->index();
                $table->string('platform')->nullable();
                $table->string('display_name')->nullable();
                $table->text('url')->nullable();
                $table->string('icon')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
            });

            return;
        }

        $columns = [
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable()->index(),
            'platform' => fn (Blueprint $table) => $table->string('platform')->nullable(),
            'display_name' => fn (Blueprint $table) => $table->string('display_name')->nullable(),
            'url' => fn (Blueprint $table) => $table->text('url')->nullable(),
            'icon' => fn (Blueprint $table) => $table->string('icon')->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('app_social_links', $column)) {
                Schema::table('app_social_links', fn (Blueprint $table) => $definition($table));
            }
        }

        $this->backfillColumn('platform', ['social_key', 'key', 'social_key_name', 'platform_name', 'name']);
        $this->backfillColumn('display_name', ['name', 'title', 'social_name', 'platform_name']);
        $this->backfillColumn('url', ['link', 'social_url', 'website_url']);
    }

    public function down(): void
    {
        // No-op: these columns are compatibility additions for existing databases.
    }

    private function backfillColumn(string $targetColumn, array $sourceColumns): void
    {
        if (! Schema::hasColumn('app_social_links', $targetColumn)) {
            return;
        }

        foreach ($sourceColumns as $sourceColumn) {
            if (! Schema::hasColumn('app_social_links', $sourceColumn) || $sourceColumn === $targetColumn) {
                continue;
            }

            DB::table('app_social_links')
                ->whereNull($targetColumn)
                ->whereNotNull($sourceColumn)
                ->update([$targetColumn => DB::raw($sourceColumn)]);
        }
    }
};
