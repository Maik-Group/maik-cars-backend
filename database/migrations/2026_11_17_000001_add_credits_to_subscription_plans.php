<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->unsignedInteger('credits')->nullable();
        });

        // Existing Stripe offers are the source of truth for the initial plan amounts.
        DB::table('subscription_plans')->orderBy('id')->chunkById(100, function ($plans): void {
            foreach ($plans as $plan) {
                $credits = DB::table('billing_plan_prices')
                    ->where('subscription_plan_id', $plan->id)
                    ->where('kind', 'subscription')
                    ->whereNull('retired_at')
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->value('credits');

                if ($credits !== null) {
                    DB::table('subscription_plans')->where('id', $plan->id)->update(['credits' => (int) $credits]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('credits');
        });
    }
};
