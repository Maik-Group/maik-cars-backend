<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_orders', function (Blueprint $table): void {
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->index(['user_id', 'environment', 'kind', 'period_end'], 'billing_prepaid_term_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('billing_orders', function (Blueprint $table): void {
            $table->dropIndex('billing_prepaid_term_lookup');
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
