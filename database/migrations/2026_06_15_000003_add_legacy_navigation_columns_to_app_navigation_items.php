<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('app_navigation_items')) {
            return;
        }

        $columns = [
            'nav_key' => fn (Blueprint $table) => $table->string('nav_key')->nullable(),
            'nav_label' => fn (Blueprint $table) => $table->string('nav_label')->nullable(),
            'v_key' => fn (Blueprint $table) => $table->string('v_key')->nullable(),
            'v_label' => fn (Blueprint $table) => $table->string('v_label')->nullable(),
            'position' => fn (Blueprint $table) => $table->integer('position')->default(0),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('app_navigation_items', $column)) {
                Schema::table('app_navigation_items', fn (Blueprint $table) => $definition($table));
            }
        }
    }

    public function down(): void
    {
        // Intentionally no-op. These compatibility columns may already exist in
        // deployed databases and should not be dropped by rollback.
    }
};
