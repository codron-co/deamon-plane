<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('hostinger_order_id')->nullable()->after('mail_server_id');
            $table->string('mail_domain')->nullable()->after('hostinger_order_id');
        });

        $sites = DB::table('sites')->whereNotNull('mail_server_id')->get(['id', 'mail_server_id', 'primary_domain']);
        foreach ($sites as $site) {
            $server = DB::table('mail_servers')->where('id', $site->mail_server_id)->first();
            if ($server === null || ! is_string($server->hostinger_order_id) || $server->hostinger_order_id === '') {
                continue;
            }

            $siteDomain = strtolower(trim((string) $site->primary_domain));
            $orderDomain = strtolower(trim((string) $server->mail_domain));
            if ($siteDomain === '' || $orderDomain === '' || $siteDomain !== $orderDomain) {
                continue;
            }

            DB::table('sites')->where('id', $site->id)->update([
                'hostinger_order_id' => $server->hostinger_order_id,
                'mail_domain' => $server->mail_domain,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['hostinger_order_id', 'mail_domain']);
        });
    }
};
