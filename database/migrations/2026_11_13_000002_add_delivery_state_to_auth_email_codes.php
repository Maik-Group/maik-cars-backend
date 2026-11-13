<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_email_codes', function (Blueprint $table): void {
            $table->uuid('delivery_id')->nullable();
            $table->timestamp('delivery_lease_until')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('delivery_failed_at')->nullable();
            $table->string('provider_message_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_email_codes', function (Blueprint $table): void {
            $table->dropColumn(['delivery_id', 'delivery_lease_until', 'delivered_at', 'delivery_failed_at', 'provider_message_id']);
        });
    }
};
