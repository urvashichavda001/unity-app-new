<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coins_ledger')) {
            return;
        }

        Schema::table('coins_ledger', function (Blueprint $table): void {
            if (! Schema::hasColumn('coins_ledger', 'source_type')) {
                $table->string('source_type', 100)->nullable()->after('reference');
            }

            if (! Schema::hasColumn('coins_ledger', 'source_id')) {
                $table->uuid('source_id')->nullable()->after('source_type');
            }
        });

        if ($this->hasDuplicateSourceRows()) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS coins_ledger_user_source_unique '
                . 'ON coins_ledger (user_id, source_type, source_id) '
                . 'WHERE source_type IS NOT NULL AND source_id IS NOT NULL'
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS coins_ledger_user_source_unique '
                . 'ON coins_ledger (user_id, source_type, source_id) '
                . 'WHERE source_type IS NOT NULL AND source_id IS NOT NULL'
            );

            return;
        }

        Schema::table('coins_ledger', function (Blueprint $table): void {
            $table->unique(['user_id', 'source_type', 'source_id'], 'coins_ledger_user_source_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('coins_ledger')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS coins_ledger_user_source_unique');
        } else {
            try {
                Schema::table('coins_ledger', function (Blueprint $table): void {
                    $table->dropUnique('coins_ledger_user_source_unique');
                });
            } catch (Throwable) {
                // The index may not exist if duplicate source rows were present when this migration ran.
            }
        }

        Schema::table('coins_ledger', function (Blueprint $table): void {
            if (Schema::hasColumn('coins_ledger', 'source_id')) {
                $table->dropColumn('source_id');
            }

            if (Schema::hasColumn('coins_ledger', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }

    private function hasDuplicateSourceRows(): bool
    {
        return DB::query()
            ->fromSub(
                DB::table('coins_ledger')
                    ->select('user_id', 'source_type', 'source_id')
                    ->whereNotNull('source_type')
                    ->whereNotNull('source_id')
                    ->groupBy('user_id', 'source_type', 'source_id')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_sources'
            )
            ->exists();
    }
};
