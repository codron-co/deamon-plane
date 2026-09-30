<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row CI gate mode (App\Enums\CiGateMode). No row = `enforce`, today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ci_gate_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16)->default('enforce');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_gate_settings');
    }
};
