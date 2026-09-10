<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_mail_bindings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('hostinger_order_id');
            $table->string('mail_domain');
            $table->timestamps();

            $table->unique(['site_id', 'hostinger_order_id']);
            $table->unique(['site_id', 'mail_domain']);
        });

        Schema::create('site_mailbox_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('local_part');
            $table->string('domain');
            $table->string('note', 500)->nullable();
            $table->string('requester_kind', 32)->default('admin');
            $table->string('requester_ref', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('fulfilled_mailbox_id', 128)->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });

        $sites = DB::table('sites')
            ->whereNotNull('hostinger_order_id')
            ->whereNotNull('mail_domain')
            ->get(['id', 'hostinger_order_id', 'mail_domain']);

        $now = now();
        foreach ($sites as $site) {
            $orderId = trim((string) $site->hostinger_order_id);
            $domain = strtolower(trim((string) $site->mail_domain));
            if ($orderId === '' || $domain === '') {
                continue;
            }

            DB::table('site_mail_bindings')->insert([
                'id' => (string) Str::ulid(),
                'site_id' => $site->id,
                'hostinger_order_id' => $orderId,
                'mail_domain' => $domain,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_mailbox_requests');
        Schema::dropIfExists('site_mail_bindings');
    }
};
