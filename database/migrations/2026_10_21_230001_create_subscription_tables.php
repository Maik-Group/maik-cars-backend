<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('billing_account_uuid')->nullable()->unique();
            $table->char('billing_account_hash', 64)->nullable()->unique();
        });

        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('tier_key');
            $table->unsignedTinyInteger('duration_months');
            $table->unsignedInteger('service_level')->default(1);
            $table->unsignedInteger('daily_allowance')->nullable();
            $table->json('features');
            $table->json('descriptions')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_visible')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('plan_store_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('platform', 12);
            $table->string('environment', 16);
            $table->string('product_id', 180);
            $table->string('base_plan_id', 80)->default('');
            $table->unsignedInteger('plan_revision');
            $table->string('validation_state', 24)->default('unverified');
            $table->json('expected_metadata')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('mismatch_reason')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'environment', 'product_id', 'base_plan_id'], 'store_product_identity');
        });

        Schema::create('billing_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_store_product_id')->constrained()->restrictOnDelete();
            $table->string('provider', 12);
            $table->string('environment', 16);
            $table->char('identity_fingerprint', 64);
            $table->char('reference_fingerprint', 64);
            $table->text('purchase_reference');
            $table->string('provider_state', 24)->default('pending');
            $table->boolean('auto_renews')->default(false);
            $table->boolean('acknowledgement_pending')->default(false);
            $table->unsignedInteger('daily_allowance')->nullable();
            $table->json('features');
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamp('suppressed_at')->nullable();
            $table->string('suppression_reason')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'environment', 'identity_fingerprint'], 'billing_agreement_identity');
            $table->unique(['provider', 'environment', 'reference_fingerprint'], 'billing_agreement_reference');
        });

        Schema::create('purchase_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_agreement_id')->constrained()->restrictOnDelete();
            $table->string('provider', 12);
            $table->string('environment', 16);
            $table->char('transaction_fingerprint', 64);
            $table->char('purchase_fingerprint', 64)->index();
            $table->text('transaction_reference');
            $table->string('product_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->decimal('amount', 18, 6)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['provider', 'environment', 'transaction_fingerprint'], 'purchase_transaction_identity');
        });

        Schema::create('subscription_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_agreement_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 16);
            $table->string('environment', 16);
            $table->unsignedInteger('daily_allowance')->nullable();
            $table->json('features');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['user_id', 'environment', 'ends_at'], 'entitlement_access_lookup');
        });

        Schema::create('subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('billing_agreement_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider', 12);
            $table->string('environment', 16);
            $table->char('deduplication_key', 64)->unique();
            $table->uuid('correlation_id')->unique();
            $table->longText('payload');
            $table->string('state', 16)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('duplicate_deliveries')->default(0);
            $table->string('error_code')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('reason');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('daily_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->date('usage_date');
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'environment', 'usage_date'], 'daily_usage_bucket');
        });

        Schema::create('daily_usage_reveals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_usage_id')->constrained('daily_usage')->restrictOnDelete();
            $table->char('subject_fingerprint', 64);
            $table->uuid('item_id');
            $table->timestamp('created_at');
            $table->unique(['daily_usage_id', 'subject_fingerprint'], 'daily_usage_subject');
        });
    }

    public function down(): void
    {
        foreach (['daily_usage_reveals', 'daily_usage', 'subscription_audit_logs', 'subscription_events', 'subscription_entitlements', 'purchase_transactions', 'billing_agreements', 'plan_store_products', 'subscription_plans'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['billing_account_uuid']);
            $table->dropUnique(['billing_account_hash']);
            $table->dropColumn(['billing_account_uuid', 'billing_account_hash']);
        });
    }
};
