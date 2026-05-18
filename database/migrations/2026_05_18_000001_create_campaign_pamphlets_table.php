<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS campaign_pamphlets (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title VARCHAR(255) NOT NULL,
    content TEXT NULL,
    short_message TEXT NULL,
    image_file_id UUID NULL,
    image_url TEXT NULL,
    status VARCHAR(50) DEFAULT 'active',
    created_by UUID NULL,
    updated_by UUID NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS idx_campaign_pamphlets_status ON campaign_pamphlets(status)');
        DB::statement('ALTER TABLE admin_campaigns ADD COLUMN IF NOT EXISTS pamphlet_id UUID NULL');
        DB::statement('ALTER TABLE admin_campaigns ADD COLUMN IF NOT EXISTS pamphlet_snapshot JSONB NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_campaign_pamphlets_status');
        DB::statement('ALTER TABLE admin_campaigns DROP COLUMN IF EXISTS pamphlet_snapshot');
        DB::statement('ALTER TABLE admin_campaigns DROP COLUMN IF EXISTS pamphlet_id');
        DB::statement('DROP TABLE IF EXISTS campaign_pamphlets');
    }
};
