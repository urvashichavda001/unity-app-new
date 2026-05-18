<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS admin_campaigns (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title VARCHAR(255) NOT NULL,
    campaign_type VARCHAR(50) NOT NULL,
    subject VARCHAR(255) NULL,
    email_body TEXT NULL,
    notification_title VARCHAR(255) NULL,
    notification_message TEXT NULL,
    audience_type VARCHAR(100) NOT NULL,
    filters JSONB NULL,
    total_recipients INTEGER DEFAULT 0,
    total_email_sent INTEGER DEFAULT 0,
    total_notification_sent INTEGER DEFAULT 0,
    total_failed INTEGER DEFAULT 0,
    status VARCHAR(50) DEFAULT 'draft',
    sent_at TIMESTAMP NULL,
    created_by UUID NULL,
    updated_by UUID NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS admin_campaign_recipients (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    campaign_id UUID NOT NULL REFERENCES admin_campaigns(id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email VARCHAR(255) NULL,
    email_status VARCHAR(50) DEFAULT 'pending',
    notification_status VARCHAR(50) DEFAULT 'pending',
    email_sent BOOLEAN DEFAULT FALSE,
    notification_sent BOOLEAN DEFAULT FALSE,
    error_message TEXT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(campaign_id, user_id)
)
SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS idx_admin_campaigns_status ON admin_campaigns(status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_admin_campaigns_campaign_type ON admin_campaigns(campaign_type)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_admin_campaign_recipients_campaign_id ON admin_campaign_recipients(campaign_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_admin_campaign_recipients_user_id ON admin_campaign_recipients(user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_admin_campaign_filters ON admin_campaigns USING GIN(filters)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_admin_campaign_filters');
        DB::statement('DROP INDEX IF EXISTS idx_admin_campaign_recipients_user_id');
        DB::statement('DROP INDEX IF EXISTS idx_admin_campaign_recipients_campaign_id');
        DB::statement('DROP INDEX IF EXISTS idx_admin_campaigns_campaign_type');
        DB::statement('DROP INDEX IF EXISTS idx_admin_campaigns_status');
        DB::statement('DROP TABLE IF EXISTS admin_campaign_recipients');
        DB::statement('DROP TABLE IF EXISTS admin_campaigns');
    }
};
