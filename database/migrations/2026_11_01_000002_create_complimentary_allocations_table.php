<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complimentary_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('credits');
            $table->unsignedTinyInteger('months');
            $table->string('issuance_mode', 16)->default('per_period');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['starts_at', 'ends_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complimentary_allocations');
    }
};
