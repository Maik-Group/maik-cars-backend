<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_search_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->char('idempotency_hash', 64);
            $table->string('search_text', 255);
            $table->boolean('charged')->default(false);
            $table->foreignId('credit_consumption_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'environment', 'idempotency_hash'], 'paid_search_request_unique');
            $table->index(['user_id', 'environment', 'expires_at'], 'paid_search_access_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_search_sessions');
    }
};
