-- UnityEventScan production-safe SQL fallback for PostgreSQL.
-- Prefer running Laravel migrations. Use this only if migrations cannot be run.

CREATE TABLE IF NOT EXISTS event_scanner_authorizations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    scanner_user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    assigned_by_user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    revoked_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_event_scanner_authorizations_event_scanner UNIQUE (event_id, scanner_user_id),
    CONSTRAINT chk_event_scanner_authorizations_status CHECK (status IN ('active', 'revoked'))
);

CREATE INDEX IF NOT EXISTS idx_event_scanner_authorizations_scanner_status ON event_scanner_authorizations(scanner_user_id, status);
CREATE INDEX IF NOT EXISTS idx_event_scanner_authorizations_event_status ON event_scanner_authorizations(event_id, status);

CREATE TABLE IF NOT EXISTS event_attendances (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    attendee_user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    event_registration_id UUID REFERENCES event_registrations(id) ON DELETE SET NULL,
    qr_token VARCHAR(512),
    checked_in_by_user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    checked_in_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    status VARCHAR(30) NOT NULL DEFAULT 'checked_in',
    scan_meta JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_event_attendances_status CHECK (status IN ('checked_in'))
);

CREATE INDEX IF NOT EXISTS idx_event_attendances_event_status ON event_attendances(event_id, status);
CREATE INDEX IF NOT EXISTS idx_event_attendances_scanner_time ON event_attendances(checked_in_by_user_id, checked_in_at);
CREATE INDEX IF NOT EXISTS idx_event_attendances_qr_token ON event_attendances(qr_token);
CREATE UNIQUE INDEX IF NOT EXISTS uq_event_attendances_event_registration ON event_attendances(event_id, event_registration_id) WHERE event_registration_id IS NOT NULL;

ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS qr_token VARCHAR(512);
CREATE UNIQUE INDEX IF NOT EXISTS uq_event_registrations_qr_token_not_null ON event_registrations(qr_token) WHERE qr_token IS NOT NULL;

-- Backfill missing QR tokens using pgcrypto if available.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
UPDATE event_registrations
SET qr_token = encode(gen_random_bytes(32), 'hex') || encode(gen_random_bytes(32), 'hex')
WHERE qr_token IS NULL;
