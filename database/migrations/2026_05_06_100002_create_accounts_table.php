<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Progravity\Auth\PublicId\PublicId;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', PublicId::maxLength())->unique();
            $table->string('name');
            $table->foreignId('owner_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
