-- Manual PostgreSQL SQL for DED approval tracking on circle joining requests.
-- Run this manually; no Laravel migration is provided intentionally.

ALTER TABLE circle_join_requests
    ADD COLUMN IF NOT EXISTS ded_approval_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS ded_approved_by UUID NULL,
    ADD COLUMN IF NOT EXISTS ded_approved_at TIMESTAMPTZ NULL;

UPDATE circle_join_requests
SET ded_approval_status = 'pending'
WHERE ded_approval_status IS NULL;

ALTER TABLE circle_join_requests
    ALTER COLUMN ded_approval_status SET DEFAULT 'pending',
    ALTER COLUMN ded_approval_status SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'circle_join_requests_ded_approved_by_foreign'
    ) THEN
        ALTER TABLE circle_join_requests
            ADD CONSTRAINT circle_join_requests_ded_approved_by_foreign
            FOREIGN KEY (ded_approved_by) REFERENCES users(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_circle_join_requests_ded_approval_status
    ON circle_join_requests (ded_approval_status);

CREATE INDEX IF NOT EXISTS idx_circle_join_requests_ded_approved_by
    ON circle_join_requests (ded_approved_by);

CREATE INDEX IF NOT EXISTS idx_circle_join_requests_ded_approved_at
    ON circle_join_requests (ded_approved_at);
