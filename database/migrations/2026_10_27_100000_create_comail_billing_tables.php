<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoMail v2: yearly prices, subscriptions, orders (iyzico / PayTR / EFT),
 * billing settings and the webmail SSO secret. See docs/modules/comail.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_packages', function (Blueprint $table) {
            $table->decimal('price_yearly', 10, 2)->nullable()->after('price_monthly');
        });

        Schema::table('mail_servers', function (Blueprint $table) {
            // Encrypted; the webmail server signs SSO redeem calls with it.
            $table->text('sso_secret')->nullable()->after('mail_hostname');
        });

        Schema::create('mail_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('mail_package_id')->constrained('mail_packages')->restrictOnDelete();
            $table->string('period', 8);
            $table->string('status', 16)->default('active');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_period_end'], 'mail_subscriptions_status_end_idx');
        });

        Schema::create('mail_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('mail_package_id')->constrained('mail_packages')->restrictOnDelete();
            $table->string('period', 8);
            $table->string('kind', 8);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('TRY');
            $table->string('method', 8);
            $table->string('status', 24)->default('pending');
            $table->string('reference', 16)->unique();
            $table->json('billing')->nullable();
            $table->string('provider_token', 255)->nullable();
            $table->string('provider_payment_id', 64)->nullable();
            $table->json('provider_payload')->nullable();
            $table->string('return_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('transfer_reported_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at'], 'mail_orders_site_created_idx');
            $table->index(['status', 'expires_at'], 'mail_orders_status_expires_idx');
            $table->index('provider_token', 'mail_orders_provider_token_idx');
        });

        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('iyzico_enabled')->default(false);
            $table->text('iyzico_api_key')->nullable();
            $table->text('iyzico_secret')->nullable();
            $table->boolean('iyzico_sandbox')->default(true);
            $table->boolean('paytr_enabled')->default(false);
            $table->string('paytr_merchant_id', 32)->nullable();
            $table->text('paytr_merchant_key')->nullable();
            $table->text('paytr_merchant_salt')->nullable();
            $table->boolean('paytr_test_mode')->default(true);
            $table->boolean('eft_enabled')->default(false);
            $table->string('bank_name', 120)->nullable();
            $table->string('account_holder', 160)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('eft_note', 500)->nullable();
            $table->boolean('vat_included')->default(true);
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->string('distance_sales_url', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
        Schema::dropIfExists('mail_orders');
        Schema::dropIfExists('mail_subscriptions');

        Schema::table('mail_servers', function (Blueprint $table) {
            $table->dropColumn('sso_secret');
        });

        Schema::table('mail_packages', function (Blueprint $table) {
            $table->dropColumn('price_yearly');
        });
    }
};
