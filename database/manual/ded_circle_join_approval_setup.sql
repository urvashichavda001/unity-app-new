-- Optional manual PostgreSQL tracking columns for DED circle-join approvals.
-- Run manually if you want first-class columns in addition to the JSON notes audit trail.
-- Do not run Laravel migrations for this change.

ALTER TABLE circle_join_requests
    ADD COLUMN IF NOT EXISTS ded_approved_by UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS ded_approved_admin_user_id UUID NULL REFERENCES admin_users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS ded_approved_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS ded_approval_status VARCHAR(30) NULL;

CREATE INDEX IF NOT EXISTS idx_circle_join_requests_ded_approved_by
    ON circle_join_requests(ded_approved_by);

CREATE INDEX IF NOT EXISTS idx_circle_join_requests_ded_approved_admin_user_id
    ON circle_join_requests(ded_approved_admin_user_id);

CREATE INDEX IF NOT EXISTS idx_circle_join_requests_ded_approved_at
    ON circle_join_requests(ded_approved_at);
