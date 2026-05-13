<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collaboration_posts')) {
            return;
        }

        DB::statement('ALTER TABLE collaboration_posts ADD COLUMN IF NOT EXISTS accepted_by_user_id UUID NULL');
        DB::statement('ALTER TABLE collaboration_posts ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMPTZ NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS collaboration_posts_accepted_by_user_id_index ON collaboration_posts (accepted_by_user_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('collaboration_posts')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS collaboration_posts_accepted_by_user_id_index');
        DB::statement('ALTER TABLE collaboration_posts DROP COLUMN IF EXISTS accepted_at');
        DB::statement('ALTER TABLE collaboration_posts DROP COLUMN IF EXISTS accepted_by_user_id');
    }
};
