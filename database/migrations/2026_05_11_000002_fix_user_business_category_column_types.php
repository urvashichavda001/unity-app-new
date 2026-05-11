<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            foreach (['main_business_category_id', 'business_category_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $this->dropForeignIfExists('users', $column);
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('main_business_category_id')->nullable();
            $table->unsignedBigInteger('business_category_id')->nullable();
        });

        $this->addForeignKeyIfSafe('users', 'main_business_category_id', 'circle_categories', 'id');
        $this->addForeignKeyIfSafe('users', 'business_category_id', $this->level4CategoriesTable(), 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            foreach (['main_business_category_id', 'business_category_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $this->dropForeignIfExists('users', $column);
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function level4CategoriesTable(): string
    {
        foreach (['level4_categories', 'circle_category_level4'] as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return 'level4_categories';
    }

    private function addForeignKeyIfSafe(string $table, string $column, string $referencedTable, string $referencedColumn): void
    {
        if (! Schema::hasTable($referencedTable) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (! $this->integerTypesCompatible($this->columnDataType($table, $column), $this->columnDataType($referencedTable, $referencedColumn))) {
            return;
        }

        $constraint = $table . '_' . $column . '_foreign';
        if ($this->foreignKeyExists($constraint)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $referencedColumn): void {
            $blueprint->foreign($column)
                ->references($referencedColumn)
                ->on($referencedTable)
                ->nullOnDelete();
        });
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        $constraint = $table . '_' . $column . '_foreign';
        if (! $this->foreignKeyExists($constraint)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropForeign([$column]);
        });
    }

    private function columnDataType(string $table, string $column): ?string
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return null;
        }

        $schema = config('database.connections.' . config('database.default') . '.schema', 'public');

        $row = DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->select(['data_type'])
            ->first();

        return $row?->data_type;
    }

    private function integerTypesCompatible(?string $left, ?string $right): bool
    {
        $integerTypes = ['bigint', 'bigserial', 'integer', 'serial'];

        return in_array($left, $integerTypes, true) && in_array($right, $integerTypes, true);
    }

    private function foreignKeyExists(string $constraint): bool
    {
        $schema = config('database.connections.' . config('database.default') . '.schema', 'public');

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $schema)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
