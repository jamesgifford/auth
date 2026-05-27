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
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_account_id']);
            $table->dropIndex(['current_account_id']);
            $table->dropColumn('current_account_id');
        });
    }
};
