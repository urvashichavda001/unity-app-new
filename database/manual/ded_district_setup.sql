-- Manual PostgreSQL setup for DED district assignments.
-- Run this SQL manually; do not run Laravel migrations for this task.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS districts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(150) NOT NULL,
    state VARCHAR(150),
    country VARCHAR(150),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_districts_unique_name_state_country
    ON districts (LOWER(name), COALESCE(LOWER(state), ''), COALESCE(LOWER(country), ''));

INSERT INTO districts (name, state, country, created_at, updated_at)
SELECT DISTINCT
    trim(cities.district) AS name,
    NULLIF(trim(COALESCE(cities.state, '')), '') AS state,
    NULLIF(trim(COALESCE(cities.country, '')), '') AS country,
    NOW(),
    NOW()
FROM cities
WHERE NULLIF(trim(COALESCE(cities.district, '')), '') IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM districts
      WHERE LOWER(districts.name) = LOWER(trim(cities.district))
        AND COALESCE(LOWER(districts.state), '') = COALESCE(LOWER(NULLIF(trim(COALESCE(cities.state, '')), '')), '')
        AND COALESCE(LOWER(districts.country), '') = COALESCE(LOWER(NULLIF(trim(COALESCE(cities.country, '')), '')), '')
  );

CREATE TABLE IF NOT EXISTS admin_ded_districts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    admin_user_id UUID NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    user_id UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    district_id UUID NOT NULL REFERENCES districts(id) ON DELETE RESTRICT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (admin_user_id)
);

CREATE INDEX IF NOT EXISTS idx_admin_ded_districts_district_id ON admin_ded_districts(district_id);
CREATE INDEX IF NOT EXISTS idx_admin_ded_districts_user_id ON admin_ded_districts(user_id);
