<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\PublicId\PublicId;

return new class extends Migration
{
    public function up(): void
    {
        // Add the column as NULLABLE first. It must be addable to a `users`
        // table that ALREADY has rows — leftover users after an uninstall that
        // preserved user data (uninstall drops this column but NOT the rows), or
        // installing the package into an app that already has users. A NOT NULL
        // unique column cannot be added to a populated table: SQLite rejects
        // "Cannot add a NOT NULL column with default value NULL", and MySQL would
        // backfill every existing row with '' and immediately violate the unique
        // index (users_public_id_unique).
        //
        // Each step below is guarded to be individually re-runnable. Splitting
        // the original single ALTER into add → backfill → constrain sacrifices
        // the atomicity of one DDL statement; on MySQL (non-transactional DDL) a
        // failure partway would otherwise leave the column added but the
        // migration unrecorded, so a re-run must be able to pick up where it left
        // off rather than abort on "Duplicate column name public_id".
        if (! Schema::hasColumn('users', 'public_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('public_id', PublicId::maxLength())
                    ->nullable()
                    ->after('id');

                // current_account_id FK added in a separate migration after
                // the accounts table exists.
            });
        }

        // Backfill every pre-existing row with a valid, unique public_id so none
        // is left null and the unique index below can be enforced.
        $this->backfillPublicIds();

        // Now enforce the intended constraints: every row has a value, so the
        // column can become NOT NULL and gain its unique index.
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_id', PublicId::maxLength())
                ->nullable(false)
                ->change();

            if (! $this->uniqueIndexExists('users', 'public_id')) {
                $table->unique('public_id');
            }
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
     * Give every existing users row that lacks a public_id a freshly generated,
     * valid one. Runs on fresh installs (a no-op — the table is empty) and on
     * re-installs where leftover rows carry null (or, defensively, empty) values.
     *
     * Each row needs its OWN unique id, so this is inherently one UPDATE per row
     * rather than a single set-based statement. The updates are wrapped in one
     * transaction so a large existing users table incurs a single commit instead
     * of one per row (a no-op wrapper on drivers/connections already in a
     * transaction). Backfilling only null/empty rows keeps it re-runnable.
     */
    private function backfillPublicIds(): void
    {
        $prefix = $this->userPrefix();

        $ids = DB::table('users')
            ->where(function ($query) {
                $query->whereNull('public_id')->orWhere('public_id', '');
            })
            ->orderBy('id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($ids, $prefix) {
            foreach ($ids as $id) {
                DB::table('users')
                    ->where('id', $id)
                    ->update(['public_id' => PublicId::generate($prefix)]);
            }
        });
    }

    /**
     * The public_id prefix for the configured user model, resolved the same way
     * the HasPublicId trait resolves it at runtime, so backfilled ids match the
     * prefix new rows will use. Falls back to 'user' when the model isn't
     * registered/resolvable (the migration must never fail over a lookup).
     */
    private function userPrefix(): string
    {
        $userClass = config('jamesgifford.auth.models.user');

        if (is_string($userClass) && class_exists($userClass)) {
            try {
                return app(PrefixRegistry::class)->prefixFor($userClass);
            } catch (Throwable) {
                // fall through to the default
            }
        }

        return 'user';
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
