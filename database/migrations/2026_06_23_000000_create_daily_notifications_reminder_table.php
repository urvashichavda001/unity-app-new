<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS daily_notifications_reminder (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    feature VARCHAR(255) NOT NULL,
    activity TEXT NOT NULL,
    notification_title VARCHAR(255) NOT NULL,
    notification_body TEXT NOT NULL,
    action_trigger_timing VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS idx_daily_notifications_reminder_feature ON daily_notifications_reminder(feature)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_daily_notifications_reminder_feature');
        DB::statement('DROP TABLE IF EXISTS daily_notifications_reminder');
    }
};
