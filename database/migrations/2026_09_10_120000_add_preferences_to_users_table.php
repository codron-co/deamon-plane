<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->default('en')->after('password');
            $table->string('appearance', 16)->default('dark')->after('locale');
            $table->string('avatar_path')->nullable()->after('appearance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'appearance', 'avatar_path']);
        });
    }
};
