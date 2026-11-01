<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('offer_key', 100);
            $table->unsignedInteger('revision');
            $table->string('environment', 16);
            $table->string('kind', 16);
            $table->string('name');
            $table->unsignedTinyInteger('duration_months')->nullable();
            $table->unsignedBigInteger('credits');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('terms_version', 40);
            $table->json('offer_snapshot');
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'offer_key', 'revision'], 'billing_offer_revision_unique');
            $table->unique(['environment', 'stripe_price_id'], 'billing_stripe_price_unique');
        });

        Schema::create('stripe_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('stripe_customer_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'environment'], 'stripe_customer_user_environment');
            $table->unique(['environment', 'stripe_customer_id'], 'stripe_customer_provider_identity');
        });

        Schema::create('billing_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_plan_price_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('kind', 16);
            $table->string('purchase_context_id', 100);
            $table->char('idempotency_hash', 64);
            $table->char('request_hash', 64);
            $table->json('offer_snapshot');
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('status', 32)->default('reserved');
            $table->string('payment_status', 24)->default('unpaid');
            $table->string('fulfillment_status', 24)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'environment', 'idempotency_hash'], 'billing_order_idempotency');
            $table->unique(['environment', 'stripe_checkout_session_id'], 'billing_order_session_identity');
            $table->index(['user_id', 'environment', 'kind', 'status'], 'billing_order_reservation_lookup');
        });

        Schema::create('billing_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_plan_price_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('stripe_subscription_id');
            $table->string('stripe_customer_id');
            $table->string('stripe_schedule_id')->nullable();
            $table->foreignId('pending_price_id')->nullable()->constrained('billing_plan_prices')->restrictOnDelete();
            $table->string('status', 32);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();
            $table->unique(['environment', 'stripe_subscription_id'], 'billing_subscription_provider_id');
            $table->index(['user_id', 'environment', 'status'], 'billing_subscription_user_state');
        });

        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_plan_price_id')->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('stripe_invoice_id');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_charge_id')->nullable();
            $table->string('billing_reason', 64);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_paid_minor');
            $table->string('status', 24);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'stripe_invoice_id'], 'billing_invoice_provider_id');
        });

        Schema::create('billing_invoice_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('billing_subscription_id')->constrained()->restrictOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('source_key', 190)->unique();
            $table->unsignedBigInteger('credits');
            $table->timestamp('created_at');
            $table->unique(['billing_subscription_id', 'period_start', 'period_end'], 'billing_one_grant_per_period');
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 16);
            $table->string('stripe_event_id');
            $table->string('type', 100);
            $table->longText('encrypted_payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'stripe_event_id'], 'stripe_webhook_event_identity');
            $table->index(['status', 'next_attempt_at'], 'stripe_webhook_recovery_queue');
        });

        Schema::create('billing_refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 16);
            $table->string('stripe_refund_id');
            $table->foreignId('billing_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('billing_order_id')->nullable()->constrained('billing_orders')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'stripe_refund_id'], 'billing_refund_identity');
        });

        Schema::create('credit_recovery_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('environment', 16);
            $table->string('source_type', 24);
            $table->string('source_key', 190);
            $table->unsignedBigInteger('credits_affected');
            $table->string('reason', 80);
            $table->string('status', 24)->default('needs_review');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'source_type', 'source_key', 'reason'], 'credit_recovery_case_identity');
        });
    }

    public function down(): void
    {
        foreach (['credit_recovery_cases', 'billing_refunds', 'stripe_webhook_events', 'billing_invoice_grants', 'billing_invoices', 'billing_subscriptions', 'billing_orders', 'stripe_customers', 'billing_plan_prices'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
