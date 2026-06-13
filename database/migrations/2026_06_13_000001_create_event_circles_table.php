<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS event_circles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    circle_id UUID NOT NULL REFERENCES circles(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ NULL,
    CONSTRAINT event_circles_event_id_circle_id_unique UNIQUE (event_id, circle_id)
)
SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS event_circles_event_id_index ON event_circles(event_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS event_circles_circle_id_index ON event_circles(circle_id)');
        DB::statement('ALTER TABLE events ADD COLUMN IF NOT EXISTS state_name VARCHAR(255) NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_circles');
        DB::statement('ALTER TABLE events DROP COLUMN IF EXISTS state_name');
    }
};
