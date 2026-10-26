<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoMail v3 (mail security): DMARC policy, MTA-STS policy id and the external
 * DMARC report authorization per mail domain; outbound rate limit per package.
 * See docs/modules/comail.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_comail_domains', function (Blueprint $table) {
            $table->string('dmarc_policy', 16)->default('none')->after('dkim_txt');
            // Plane's own YmdHis of the last MTA-STS policy change (the _mta-sts TXT id).
            $table->string('mta_sts_id', 14)->nullable()->after('dmarc_policy');
            $table->timestamp('report_auth_at')->nullable()->after('mta_sts_id');
        });

        Schema::table('mail_packages', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_per_hour')->default(300)->after('domain_limit');
        });
    }

    public function down(): void
    {
        Schema::table('mail_packages', function (Blueprint $table) {
            $table->dropColumn('rate_limit_per_hour');
        });

        Schema::table('site_comail_domains', function (Blueprint $table) {
            $table->dropColumn(['dmarc_policy', 'mta_sts_id', 'report_auth_at']);
        });
    }
};
