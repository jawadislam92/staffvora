<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription billing. Plans are a global catalogue; subscriptions and payments
 * belong to an organization (and are org-scoped at the model layer). Billing is
 * manual for now — a platform admin records a bank transfer as a Payment and
 * extends the period — but the shape is gateway-ready (see PaymentProvider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();              // free | starter | pro | enterprise
            $table->string('name');
            $table->unsignedInteger('price_cents')->default(0); // per seat / month
            $table->string('currency', 3)->default('USD');
            $table->string('interval')->default('month');
            $table->unsignedInteger('max_users')->nullable(); // null = unlimited
            $table->json('features')->nullable();          // { ai: true, monitoring: true, ... }
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status')->default('trialing'); // trialing | active | past_due | canceled
            $table->unsignedInteger('seats')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('method')->default('bank_transfer');
            $table->string('reference')->nullable();
            $table->string('status')->default('confirmed'); // recorded | confirmed
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable(); // platform user id
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
