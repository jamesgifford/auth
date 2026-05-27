<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('account_role_id')
                ->constrained('account_roles')
                ->restrictOnDelete();
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['account_id', 'user_id']);
            $table->index('user_id');
            $table->index('account_role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_user');
    }
};
