<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('app_dashboard_widgets')) {
            Schema::create('app_dashboard_widgets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('app_instance_id')->nullable()->index();
                $table->string('widget_key', 150);
                $table->string('widget_name', 255);
                $table->string('label_key', 150)->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
            });

            return;
        }

        $columns = [
            'app_instance_id' => fn (Blueprint $table) => $table->uuid('app_instance_id')->nullable()->index(),
            'widget_key' => fn (Blueprint $table) => $table->string('widget_key', 150)->nullable(),
            'widget_name' => fn (Blueprint $table) => $table->string('widget_name', 255)->nullable(),
            'label_key' => fn (Blueprint $table) => $table->string('label_key', 150)->nullable(),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'sort_order' => fn (Blueprint $table) => $table->integer('sort_order')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestampTz('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestampTz('updated_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('app_dashboard_widgets', $column)) {
                Schema::table('app_dashboard_widgets', fn (Blueprint $table) => $definition($table));
            }
        }

        if (Schema::hasColumn('app_dashboard_widgets', 'is_enable') && Schema::hasColumn('app_dashboard_widgets', 'is_enabled')) {
            DB::table('app_dashboard_widgets')
                ->whereNotNull('is_enable')
                ->update(['is_enabled' => DB::raw('is_enable')]);
        }
    }

    public function down(): void
    {
        // No-op: these columns are compatibility additions for existing databases.
    }
};
