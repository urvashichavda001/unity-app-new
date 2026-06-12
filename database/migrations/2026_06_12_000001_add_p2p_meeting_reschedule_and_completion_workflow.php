<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TYPE p2p_meeting_status_enum ADD VALUE IF NOT EXISTS 'scheduled';
ALTER TYPE p2p_meeting_status_enum ADD VALUE IF NOT EXISTS 'reschedule_requested';
ALTER TYPE p2p_meeting_status_enum ADD VALUE IF NOT EXISTS 'completed';

ALTER TYPE notification_type_enum ADD VALUE IF NOT EXISTS 'p2p_reschedule_requested';
ALTER TYPE notification_type_enum ADD VALUE IF NOT EXISTS 'p2p_reschedule_approved';
ALTER TYPE notification_type_enum ADD VALUE IF NOT EXISTS 'p2p_reschedule_rejected';
ALTER TYPE notification_type_enum ADD VALUE IF NOT EXISTS 'p2p_meeting_completed';
SQL);

        DB::unprepared(<<<'SQL'
ALTER TABLE p2p_meeting_requests
    ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS completed_by_from_user_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS completed_by_to_user_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS completed_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS completion_post_id UUID NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'p2p_meeting_requests_completion_post_id_foreign'
    ) THEN
        ALTER TABLE p2p_meeting_requests
            ADD CONSTRAINT p2p_meeting_requests_completion_post_id_foreign
            FOREIGN KEY (completion_post_id) REFERENCES posts(id) ON DELETE SET NULL;
    END IF;
END
$$;

CREATE TABLE IF NOT EXISTS p2p_meeting_reschedule_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    p2p_meeting_request_id UUID NOT NULL REFERENCES p2p_meeting_requests(id) ON DELETE CASCADE,
    requested_by_user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    requested_to_user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    old_scheduled_at TIMESTAMPTZ NULL,
    new_scheduled_at TIMESTAMPTZ NOT NULL,
    old_place TEXT NULL,
    new_place TEXT NULL,
    reason TEXT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    approved_at TIMESTAMPTZ NULL,
    rejected_at TIMESTAMPTZ NULL,
    responded_by_user_id UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_p2p_meeting_reschedule_requests_to_status
    ON p2p_meeting_reschedule_requests(requested_to_user_id, status);

CREATE INDEX IF NOT EXISTS idx_p2p_meeting_reschedule_requests_by_status
    ON p2p_meeting_reschedule_requests(requested_by_user_id, status);

CREATE INDEX IF NOT EXISTS idx_p2p_meeting_reschedule_requests_meeting_status
    ON p2p_meeting_reschedule_requests(p2p_meeting_request_id, status);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS idx_p2p_meeting_reschedule_requests_meeting_status;
DROP INDEX IF EXISTS idx_p2p_meeting_reschedule_requests_by_status;
DROP INDEX IF EXISTS idx_p2p_meeting_reschedule_requests_to_status;
DROP TABLE IF EXISTS p2p_meeting_reschedule_requests;

ALTER TABLE p2p_meeting_requests DROP CONSTRAINT IF EXISTS p2p_meeting_requests_completion_post_id_foreign;
ALTER TABLE p2p_meeting_requests
    DROP COLUMN IF EXISTS completion_post_id,
    DROP COLUMN IF EXISTS completed_at,
    DROP COLUMN IF EXISTS completed_by_to_user_at,
    DROP COLUMN IF EXISTS completed_by_from_user_at,
    DROP COLUMN IF EXISTS accepted_at;
SQL);
    }
};
