-- PostgreSQL manual repair for environments where registration category columns were created as UUID.
ALTER TABLE users DROP COLUMN IF EXISTS main_business_category_id;
ALTER TABLE users DROP COLUMN IF EXISTS business_category_id;

ALTER TABLE users ADD COLUMN main_business_category_id BIGINT NULL;
ALTER TABLE users ADD COLUMN business_category_id BIGINT NULL;
