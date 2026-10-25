<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * CoMail (shared mailcow): packages, per-site mail domains and the CMS push outcome.
 * See docs/modules/comail.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_servers', function (Blueprint $table) {
            $table->string('base_url', 255)->nullable()->after('api_token');
            $table->string('mail_hostname', 255)->nullable()->after('base_url');
        });

        Schema::create('mail_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->string('description', 1000)->nullable();
            $table->unsignedInteger('mailbox_limit');
            $table->unsignedInteger('storage_mb');
            $table->unsignedInteger('mailbox_max_quota_mb');
            $table->unsignedInteger('alias_limit');
            $table->unsignedInteger('domain_limit')->nullable();
            $table->decimal('price_monthly', 10, 2)->nullable();
            $table->string('currency', 3)->default('TRY');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            // Null = the default package.
            $table->foreignUlid('mail_package_id')->nullable()->after('mail_configure_message')
                ->constrained('mail_packages')->nullOnDelete();
            $table->timestamp('comail_configured_at')->nullable()->after('mail_package_id');
            $table->timestamp('comail_configure_failed_at')->nullable()->after('comail_configured_at');
            $table->string('comail_configure_error', 64)->nullable()->after('comail_configure_failed_at');
            $table->string('comail_configure_message', 500)->nullable()->after('comail_configure_error');
        });

        Schema::create('site_comail_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('domain', 255)->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 16)->default('pending');
            $table->timestamp('mailcow_synced_at')->nullable();
            $table->string('dkim_selector', 64)->nullable();
            $table->text('dkim_txt')->nullable();
            $table->timestamp('mx_active_at')->nullable();
            $table->timestamp('dns_checked_at')->nullable();
            $table->json('dns_check')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['site_id', 'is_primary'], 'site_comail_domains_site_primary_idx');
        });

        $now = now();
        $packages = [
            ['name' => 'Ücretsiz', 'slug' => 'free', 'mailbox_limit' => 1, 'storage_mb' => 1024, 'mailbox_max_quota_mb' => 1024, 'alias_limit' => 10, 'is_default' => true, 'is_active' => true, 'sort' => 0],
            ['name' => 'Başlangıç', 'slug' => 'starter', 'mailbox_limit' => 5, 'storage_mb' => 10240, 'mailbox_max_quota_mb' => 5120, 'alias_limit' => 50, 'is_default' => false, 'is_active' => false, 'sort' => 10],
            ['name' => 'İş', 'slug' => 'business', 'mailbox_limit' => 25, 'storage_mb' => 51200, 'mailbox_max_quota_mb' => 10240, 'alias_limit' => 200, 'is_default' => false, 'is_active' => false, 'sort' => 20],
        ];

        foreach ($packages as $package) {
            if (DB::table('mail_packages')->where('slug', $package['slug'])->exists()) {
                continue;
            }

            DB::table('mail_packages')->insert($package + [
                'id' => (string) Str::ulid(),
                'description' => null,
                'domain_limit' => null,
                'price_monthly' => null,
                'currency' => 'TRY',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_comail_domains');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_package_id');
            $table->dropColumn([
                'comail_configured_at',
                'comail_configure_failed_at',
                'comail_configure_error',
                'comail_configure_message',
            ]);
        });

        Schema::dropIfExists('mail_packages');

        Schema::table('mail_servers', function (Blueprint $table) {
            $table->dropColumn(['base_url', 'mail_hostname']);
        });
    }
};
