<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS media JSONB DEFAULT '[]'::jsonb");
    }

    public function down(): void
    {
        Schema::table('users', function ($table): void {
            if (Schema::hasColumn('users', 'media')) {
                $table->dropColumn('media');
            }
        });
    }
};
