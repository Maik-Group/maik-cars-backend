<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_grant_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->string('audience', 16);
            $table->unsignedInteger('credits');
            $table->timestamp('expires_at')->nullable();
            $table->string('snapshot_hash', 64);
            $table->unsignedInteger('recipient_count');
            $table->string('status', 24)->default('preview');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_grant_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('admin_grant_batches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_grant_recipients');
        Schema::dropIfExists('admin_grant_batches');
    }
};
