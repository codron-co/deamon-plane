<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last pushed commit per repo branch (CMS channel branches, theme default refs),
 * plus the last CI verdict GitHub reported for that branch. A green `workflow_run`
 * promotes only when its commit is still the recorded head; the GitHub API is
 * never asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ci_branch_heads', function (Blueprint $table): void {
            $table->id();
            $table->string('repo_full_name', 191);
            $table->string('branch', 120);
            $table->string('head_sha', 64)->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->string('ci_status', 32)->nullable();
            $table->string('ci_sha', 64)->nullable();
            $table->timestamp('ci_at')->nullable();
            $table->timestamps();

            $table->unique(['repo_full_name', 'branch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_branch_heads');
    }
};
