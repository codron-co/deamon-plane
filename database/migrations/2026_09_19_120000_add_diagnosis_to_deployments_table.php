<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            // Classified failure (code, evidence, container log tail, auto-fix outcome).
            // Written by DiagnoseDeploymentJob; null until a deployment fails.
            $table->json('diagnosis')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('diagnosis');
        });
    }
};
