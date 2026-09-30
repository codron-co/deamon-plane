<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Plane's copy of the MySQL passwords the site volume was initialised with. A
            // Coolify env that comes back blank is restored from here, never regenerated.
            $table->text('db_password_encrypted')->nullable()->after('agent_secret_encrypted');
            $table->text('mysql_root_password_encrypted')->nullable()->after('db_password_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['db_password_encrypted', 'mysql_root_password_encrypted']);
        });
    }
};
