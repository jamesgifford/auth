<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\PublicId\PublicId;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_id', PublicId::maxLength())
                ->unique()
                ->after('id');

            // current_account_id FK added in a separate migration after
            // the accounts table exists.
        });
    }

    public function down(): void
    {
        // Idempotent teardown: only drop what still exists. On MySQL, dropping
        // a missing column or index throws, which would abort a partial-state
        // rollback (e.g. uninstall after the schema was already torn down) and
        // strand whatever was meant to roll back afterwards.
        if (! Schema::hasColumn('users', 'public_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if ($this->uniqueIndexExists('users', 'public_id')) {
                $table->dropUnique(['public_id']);
            }

            $table->dropColumn('public_id');
        });
    }

    /**
     * Whether a single-column unique index on the given column currently
     * exists, so teardown never tries to drop one a prior partial rollback
     * already removed.
     */
    private function uniqueIndexExists(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === [$column] && ($index['unique'] ?? false)) {
                return true;
            }
        }

        return false;
    }
};
