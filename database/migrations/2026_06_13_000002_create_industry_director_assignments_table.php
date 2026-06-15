<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        if (! Schema::hasTable('industry_director_assignments')) {
            Schema::create('industry_director_assignments', function (Blueprint $table): void {
                $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
                $table->uuid('admin_user_id');
                $table->uuid('industry_id')->nullable();
                $table->string('industry_name')->nullable();
                $table->string('state_name')->nullable();
                $table->string('district_name')->nullable();
                $table->uuid('assigned_by')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('admin_user_id')->references('id')->on('admin_users')->cascadeOnDelete();
                $table->foreign('assigned_by')->references('id')->on('admin_users')->nullOnDelete();
                $table->index(['admin_user_id', 'is_active'], 'industry_director_assignments_admin_active_index');
            });
        } else {
            Schema::table('industry_director_assignments', function (Blueprint $table): void {
                if (! Schema::hasColumn('industry_director_assignments', 'industry_id')) {
                    $table->uuid('industry_id')->nullable();
                }
                if (! Schema::hasColumn('industry_director_assignments', 'industry_name')) {
                    $table->string('industry_name')->nullable();
                }
                if (! Schema::hasColumn('industry_director_assignments', 'state_name')) {
                    $table->string('state_name')->nullable();
                }
                if (! Schema::hasColumn('industry_director_assignments', 'district_name')) {
                    $table->string('district_name')->nullable();
                }
                if (! Schema::hasColumn('industry_director_assignments', 'assigned_by')) {
                    $table->uuid('assigned_by')->nullable();
                }
            });
        }

        if (Schema::hasColumn('industry_director_assignments', 'industry_id')
            && $this->industriesTableUsesUuidId()
            && ! $this->foreignKeyExists('industry_director_assignments', 'industry_director_assignments_industry_id_foreign')) {
            Schema::table('industry_director_assignments', function (Blueprint $table): void {
                $table->foreign('industry_id')->references('id')->on('industries')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_director_assignments');
    }

    private function industriesTableUsesUuidId(): bool
    {
        if (! Schema::hasTable('industries') || ! Schema::hasColumn('industries', 'id')) {
            return false;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return true;
        }

        $result = DB::selectOne(<<<'SQL'
SELECT data_type
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'industries'
  AND column_name = 'id'
SQL);

        return ($result->data_type ?? null) === 'uuid';
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        $result = DB::selectOne(<<<'SQL'
SELECT 1 AS exists
FROM information_schema.table_constraints
WHERE table_schema = current_schema()
  AND table_name = ?
  AND constraint_name = ?
  AND constraint_type = 'FOREIGN KEY'
SQL, [$table, $constraint]);

        return $result !== null;
    }
};
