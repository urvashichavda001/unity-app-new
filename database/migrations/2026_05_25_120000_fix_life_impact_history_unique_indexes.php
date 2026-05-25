<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE life_impact_histories DROP CONSTRAINT IF EXISTS unique_action_key');
        DB::statement('DROP INDEX IF EXISTS unique_action_key');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS unique_life_impact_activity_user ON life_impact_histories (user_id, activity_type, activity_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_life_impact_activity_user');
    }
};
