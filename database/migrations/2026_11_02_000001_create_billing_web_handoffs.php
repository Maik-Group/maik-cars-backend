<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_web_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('environment', 16);
            $table->string('kind', 16);
            $table->foreignId('billing_plan_price_id')->constrained('billing_plan_prices');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'environment', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_web_handoffs');
    }
};
