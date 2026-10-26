<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Build once, run many: every Deamon site is pinned to a commit now, so a pin no
 * longer means "an operator held this version". `coolify_pin_held` marks the pins
 * an operator chose (Commite geç, rollback to the last good commit); CI rollouts
 * skip only those. Release pins (newest build, release.sh, provision, channel
 * switch, rollouts) leave it false.
 *
 * Backfill: a site whose mirrored pin is the commit of its latest ref-changing
 * audit and that audit is `site.git_pinned` is held.
 */
return new class extends Migration
{
    /** Audit actions that move a site's Coolify ref. */
    private const REF_ACTIONS = [
        'site.git_pinned',
        'site.git_follow_head',
        'site.git_update_head',
        'site.built_image_pinned',
        'site.ci_rollout_deployed',
        'site.channel_switched',
    ];

    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('coolify_pin_held')->default(false)->after('coolify_pinned_sha');
        });

        $sites = DB::table('sites')
            ->whereNotNull('coolify_pinned_sha')
            ->where('coolify_pinned_sha', '!=', '')
            ->get(['id', 'coolify_pinned_sha']);

        foreach ($sites as $site) {
            $last = DB::table('audit_logs')
                ->where('subject_type', 'App\Models\Site')
                ->where('subject_id', $site->id)
                ->whereIn('action', self::REF_ACTIONS)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['action', 'after']);

            if ($last === null || $last->action !== 'site.git_pinned') {
                continue;
            }

            $after = json_decode((string) $last->after, true);
            $sha = strtolower(trim((string) (is_array($after) ? ($after['git_commit_sha'] ?? '') : '')));
            $pinned = strtolower(trim((string) $site->coolify_pinned_sha));

            if ($sha !== '' && (str_starts_with($pinned, $sha) || str_starts_with($sha, $pinned))) {
                DB::table('sites')->where('id', $site->id)->update(['coolify_pin_held' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('coolify_pin_held');
        });
    }
};
