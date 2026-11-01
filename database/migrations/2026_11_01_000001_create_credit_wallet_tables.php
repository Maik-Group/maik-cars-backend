<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 32);
            $table->string('source_key', 191);
            $table->string('environment', 16);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('remaining');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_key', 'environment'], 'credit_source_identity');
            $table->index(['user_id', 'environment', 'expires_at'], 'credit_wallet_lookup');
        });
        Schema::create('credit_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_grant_id')->constrained()->restrictOnDelete();
            $table->date('usage_date');
            $table->string('serial_fingerprint', 64);
            $table->uuid('item_id');
            $table->string('environment', 16);
            $table->timestamps();
            $table->unique(['user_id', 'environment', 'usage_date', 'serial_fingerprint'], 'credit_family_daily_identity');
        });
        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_grant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('credit_consumption_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('entry_type', 24);
            $table->integer('amount');
            $table->string('reference', 191)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
        Schema::dropIfExists('credit_consumptions');
        Schema::dropIfExists('credit_grants');
    }
};
