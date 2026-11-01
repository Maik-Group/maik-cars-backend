<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_grant_batches', function (Blueprint $table): void {
            $table->string('environment', 16)->default('production')->index();
            $table->string('operation_kind', 20)->default('free');
            $table->unsignedTinyInteger('months')->nullable();
            $table->string('issuance_mode', 16)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->restrictOnDelete();
            $table->json('plan_snapshot')->nullable();
        });
        Schema::table('complimentary_allocations', function (Blueprint $table): void {
            $table->string('environment', 16)->default('production')->index();
            $table->string('batch_recipient_key')->nullable()->unique();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->restrictOnDelete();
            $table->json('plan_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('complimentary_allocations', fn (Blueprint $table) => $table->dropUnique(['batch_recipient_key']));
        Schema::table('complimentary_allocations', function (Blueprint $table): void {
            $table->dropForeign(['subscription_plan_id']);
            $table->dropColumn(['batch_recipient_key', 'subscription_plan_id', 'plan_snapshot', 'environment']);
        });
        Schema::table('admin_grant_batches', function (Blueprint $table): void {
            $table->dropForeign(['subscription_plan_id']);
            $table->dropColumn(['operation_kind', 'months', 'issuance_mode', 'starts_at', 'ends_at', 'subscription_plan_id', 'plan_snapshot', 'environment']);
        });
    }
};
