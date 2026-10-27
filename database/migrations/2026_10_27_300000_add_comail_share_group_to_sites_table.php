<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared CoMail group: sites with the same non-null group (one company, several
 * sites) see and manage each other's mailboxes. Domain management, limits and
 * billing stay with the site that owns the domain. See docs/modules/comail.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('comail_share_group', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['comail_share_group']);
            $table->dropColumn('comail_share_group');
        });
    }
};
