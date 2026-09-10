<?php

use App\Services\Cloudflare\CloudflareDnsTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_dns_defaults', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('name', 255);
            $table->text('content');
            $table->unsignedInteger('ttl')->default(1);
            $table->unsignedInteger('priority')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $existing = DB::table('cloudflare_settings')->orderBy('id')->first();
        $origin = is_object($existing) && filled($existing->origin_ipv4 ?? null)
            ? (string) $existing->origin_ipv4
            : (string) config('ops.cloudflare.default_origin_ipv4', '72.62.117.147');
        $mail = ! is_object($existing) || (bool) ($existing->mail_template_enabled ?? true);

        $order = 0;
        foreach (CloudflareDnsTemplate::records($origin !== '' ? $origin : '72.62.117.147', $mail) as $record) {
            DB::table('cloudflare_dns_defaults')->insert([
                'type' => $record['type'],
                'name' => $record['name'],
                'content' => $record['content'],
                'ttl' => $record['ttl'] ?? 1,
                'priority' => $record['priority'] ?? null,
                'sort_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('cloudflare_settings', function (Blueprint $table) {
            $table->string('name', 120)->default('Cloudflare');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
        });

        $first = true;
        foreach (DB::table('cloudflare_settings')->orderBy('id')->get() as $row) {
            DB::table('cloudflare_settings')->where('id', $row->id)->update([
                'name' => $first ? 'Cloudflare' : ('Cloudflare '.$row->id),
                'is_enabled' => true,
                'is_default' => $first,
            ]);
            $first = false;
        }

        // Keep origin_ipv4 / proxied / mail_template_enabled: nested hostname A records
        // and the Hostinger mail toggle still read those columns.
    }

    public function down(): void
    {
        Schema::table('cloudflare_settings', function (Blueprint $table) {
            $table->dropColumn(['name', 'is_enabled', 'is_default']);
        });

        Schema::dropIfExists('cloudflare_dns_defaults');
    }
};
