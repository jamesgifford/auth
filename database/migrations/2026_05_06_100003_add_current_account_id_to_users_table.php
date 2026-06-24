<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_account_id')
                ->nullable()
                ->after('public_id')
                ->constrained('accounts')
                ->nullOnDelete();

            $table->index('current_account_id');
        });
    }

    public function down(): void
    {
        // Idempotent teardown: only drop what still exists. On MySQL, dropping a
        // missing column, foreign key, or index throws, which would abort a
        // partial-state rollback (e.g. uninstall after the accounts table was
        // already dropped) and strand earlier columns like users.public_id.
        if (! Schema::hasColumn('users', 'current_account_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if ($this->foreignKeyExists('users', 'current_account_id')) {
                $table->dropForeign(['current_account_id']);
            }

            if ($this->indexExists('users', 'current_account_id')) {
                $table->dropIndex(['current_account_id']);
            }

            $table->dropColumn('current_account_id');
        });
    }

    /**
     * Whether a foreign key on the given column currently exists.
     */
    private function foreignKeyExists(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a single-column index on the given column currently exists.
     */
    private function indexExists(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }
};
