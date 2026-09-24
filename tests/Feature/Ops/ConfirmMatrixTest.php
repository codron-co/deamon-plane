<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CloudflareSetting;
use App\Models\CoolifyConnection;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every confirm names who/what/how many, says whether it is danger, and is a
 * real translation — never Blade concatenation. Danger is anything that deletes,
 * unpublishes, stops, or triggers a build on more than one site.
 */
class ConfirmMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_every_sites_list_confirm_carries_title_label_and_explicit_danger(): void
    {
        Site::factory()->dockerfilePack()->create([
            'name' => 'Matrix List',
            'status' => SiteStatus::Active,
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $confirms = $this->confirmTags($html);
        $this->assertNotSame([], $confirms, 'The Sites list must render at least one confirm.');

        foreach ($confirms as $tag) {
            $this->assertConfirmContract($tag, 'Sites list');
        }

        $this->assertStringContainsString('data-confirm-danger="true"', $html);
        $this->assertStringContainsString(
            e(__('site_ops.bulk.confirm_redeploy', ['count' => 1])),
            $html,
        );
        $this->assertTrue(
            $this->tagWithConfirm($confirms, __('site_ops.bulk.confirm_redeploy', ['count' => 1])) !== null
            && str_contains((string) $this->tagWithConfirm($confirms, __('site_ops.bulk.confirm_redeploy', ['count' => 1])), 'data-confirm-danger="true"'),
            'Bulk redeploy triggers builds, so it is danger.',
        );
        $this->assertTrue(
            $this->tagWithConfirm($confirms, __('site_ops.bulk.confirm_auto_on', ['count' => 1])) !== null
            && str_contains((string) $this->tagWithConfirm($confirms, __('site_ops.bulk.confirm_auto_on', ['count' => 1])), 'data-confirm-danger="false"'),
            'Bulk auto-deploy on does not delete, unpublish, stop, or start a build.',
        );
        $this->assertTrue(
            $this->tagWithConfirm($confirms, __('sites.agent.bulk_confirm', ['count' => 1])) !== null
            && str_contains((string) $this->tagWithConfirm($confirms, __('sites.agent.bulk_confirm', ['count' => 1])), 'data-confirm-danger="true"'),
            'Bulk agent-secret inject writes Coolify env, so it is danger.',
        );
    }

    public function test_every_site_detail_confirm_carries_title_label_and_explicit_danger(): void
    {
        $site = Site::factory()->create([
            'name' => 'Matrix Detail',
            'status' => SiteStatus::Draft,
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->getContent();

        $confirms = $this->confirmTags($html);
        $this->assertNotSame([], $confirms, 'Site detail must render at least one confirm.');

        foreach ($confirms as $tag) {
            $this->assertConfirmContract($tag, 'Site detail');
        }
    }

    public function test_app_health_row_confirms_are_translated_sentences_not_concatenated(): void
    {
        $region = (string) file_get_contents(resource_path('views/ops/sites/_region.blade.php'));

        $this->assertStringNotContainsString(
            '}} — {{',
            $region,
            'Confirm bodies must be a translation key, not Blade concatenation.',
        );
        $this->assertStringContainsString('sites.app_health.confirm_fix', $region);

        $turkish = trans('sites.app_health.confirm_fix', [
            'label' => trans('sites.app_health.fixes.redeploy', [], 'tr'),
            'name' => 'Beyazlar',
        ], 'tr');

        $this->assertStringContainsString('Beyazlar', $turkish);
        $this->assertStringContainsString('?', $turkish);
        $this->assertStringNotContainsString('Run “', $turkish);
    }

    public function test_every_coolify_show_confirm_carries_title_label_and_explicit_danger(): void
    {
        $connection = CoolifyConnection::factory()->create(['name' => 'Matrix Coolify']);

        $html = $this->actingAs($this->superAdminUser())
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->getContent();

        $confirms = $this->confirmTags($html);
        $this->assertNotSame([], $confirms, 'Coolify show must render at least one confirm.');

        foreach ($confirms as $tag) {
            $this->assertConfirmContract($tag, 'Coolify show');
        }

        $this->assertTrue(
            $this->tagWithConfirm($confirms, __('coolify.danger.confirm', ['name' => $connection->name])) !== null
            && str_contains((string) $this->tagWithConfirm($confirms, __('coolify.danger.confirm', ['name' => $connection->name])), 'data-confirm-danger="true"'),
            'Disconnecting Coolify deletes the connection, so it is danger.',
        );
        $this->assertTrue(
            $this->tagWithConfirm($confirms, __('coolify.show.sync_confirm', ['name' => $connection->name])) !== null
            && str_contains((string) $this->tagWithConfirm($confirms, __('coolify.show.sync_confirm', ['name' => $connection->name])), 'data-confirm-danger="false"'),
            'Sync reads Coolify and writes Plane inventory; it is not danger.',
        );
    }

    public function test_every_mail_server_show_confirm_carries_title_label_and_explicit_danger(): void
    {
        $server = MailServer::factory()->create(['name' => 'Matrix Mail']);

        $html = $this->actingAs($this->superAdminUser())
            ->get(route('ops.mail-servers.show', $server))
            ->assertOk()
            ->getContent();

        $confirms = $this->confirmTags($html);
        $this->assertNotSame([], $confirms, 'Mail server show must render at least one confirm.');

        foreach ($confirms as $tag) {
            $this->assertConfirmContract($tag, 'Mail server show');
        }

        $this->assertTrue(
            $this->tagWithConfirm($confirms, __('mail.danger.confirm', ['name' => $server->name])) !== null
            && str_contains((string) $this->tagWithConfirm($confirms, __('mail.danger.confirm', ['name' => $server->name])), 'data-confirm-danger="true"'),
            'Deleting a mail server is danger.',
        );
    }

    public function test_every_domains_list_confirm_carries_title_label_and_explicit_danger(): void
    {
        $site = Site::factory()->create(['name' => 'Matrix Domains']);
        SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => 'leftover.example.test',
            'is_primary' => false,
            'verified_at' => null,
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.domains'))
            ->assertOk()
            ->getContent();

        $confirms = $this->confirmTags($html);
        $this->assertNotSame([], $confirms, 'The Domains list must render at least one confirm.');

        foreach ($confirms as $tag) {
            $this->assertConfirmContract($tag, 'Domains list');
        }

        $this->assertTrue(
            $this->tagWithConfirm($confirms, __('domains.bulk.confirm', ['count' => 1])) !== null
            && str_contains((string) $this->tagWithConfirm($confirms, __('domains.bulk.confirm', ['count' => 1])), 'data-confirm-danger="false"'),
            'Bulk bind is a Coolify PATCH, not danger.',
        );
        $this->assertTrue(
            $this->tagWithConfirm($confirms, __('domains.bulk.confirm_clear', ['count' => 1])) !== null
            && str_contains((string) $this->tagWithConfirm($confirms, __('domains.bulk.confirm_clear', ['count' => 1])), 'data-confirm-danger="true"'),
            'Bulk leftover delete is danger.',
        );
    }

    public function test_every_theme_and_git_confirm_carries_title_label_and_explicit_danger(): void
    {
        $theme = Theme::factory()->allowlist()->create(['name' => 'Matrix Theme']);
        $site = Site::factory()->create(['name' => 'Matrix Shop']);
        $theme->allowedSites()->attach($site->id);
        $git = ThemeGitConnection::factory()->pat('matrix-theme-pat')->connected()->create([
            'account_login' => 'matrix-git',
        ]);

        $themeHtml = $this->actingAs($this->superAdminUser())
            ->get(route('ops.themes.show', $theme))
            ->assertOk()
            ->getContent();
        $themeConfirms = $this->confirmTags($themeHtml);
        $this->assertNotSame([], $themeConfirms, 'Theme show must render at least one confirm.');
        foreach ($themeConfirms as $tag) {
            $this->assertConfirmContract($tag, 'Theme show');
        }
        $this->assertTrue(
            $this->tagWithConfirm($themeConfirms, __('themes.show.revoke_confirm', ['name' => $site->name])) !== null
            && str_contains((string) $this->tagWithConfirm($themeConfirms, __('themes.show.revoke_confirm', ['name' => $site->name])), 'data-confirm-danger="true"'),
            'Revoking a site from a theme allowlist is danger.',
        );

        $gitHtml = $this->actingAs($this->superAdminUser())
            ->get(route('ops.themes.git.show', $git))
            ->assertOk()
            ->getContent();
        $gitConfirms = $this->confirmTags($gitHtml);
        $this->assertNotSame([], $gitConfirms, 'Theme git show must render at least one confirm.');
        foreach ($gitConfirms as $tag) {
            $this->assertConfirmContract($tag, 'Theme git show');
        }
        $this->assertTrue(
            $this->tagWithConfirm($gitConfirms, __('themes.git.disconnect.confirm', ['account' => $git->displayName()])) !== null
            && str_contains((string) $this->tagWithConfirm($gitConfirms, __('themes.git.disconnect.confirm', ['account' => $git->displayName()])), 'data-confirm-danger="true"'),
            'Disconnecting theme git is danger.',
        );
    }

    public function test_every_cloudflare_delete_confirm_carries_title_label_and_explicit_danger(): void
    {
        Http::preventStrayRequests();
        $account = CloudflareSetting::factory()->create([
            'name' => 'Matrix CF',
            'account_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'api_token' => 'cf-matrix-token-never-show',
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $zoneId = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        Http::fake(fn (Request $request): PromiseInterface => $this->cloudflareEnvelope($request, $account->account_id, $zoneId));

        $showHtml = $this->actingAs($this->superAdminUser())
            ->get(route('ops.cloudflare.show', $account))
            ->assertOk()
            ->getContent();
        $showConfirms = $this->confirmTags($showHtml);
        $this->assertNotSame([], $showConfirms, 'Cloudflare account show must render at least one confirm.');
        foreach ($showConfirms as $tag) {
            $this->assertConfirmContract($tag, 'Cloudflare account');
        }
        $this->assertTrue(
            $this->tagWithConfirm($showConfirms, __('cloudflare.danger.confirm', ['name' => $account->name])) !== null
            && str_contains((string) $this->tagWithConfirm($showConfirms, __('cloudflare.danger.confirm', ['name' => $account->name])), 'data-confirm-danger="true"'),
            'Removing a Cloudflare account is danger.',
        );

        $zoneHtml = $this->actingAs($this->superAdminUser())
            ->get(route('ops.cloudflare.zones.show', ['account' => $account, 'zone' => $zoneId]))
            ->assertOk()
            ->getContent();
        $zoneConfirms = $this->confirmTags($zoneHtml);
        $this->assertNotSame([], $zoneConfirms, 'Cloudflare zone show must render at least one confirm.');
        foreach ($zoneConfirms as $tag) {
            $this->assertConfirmContract($tag, 'Cloudflare zone');
        }
        $this->assertTrue(
            $this->tagWithConfirm($zoneConfirms, __('cloudflare.zone.danger_confirm', ['domain' => 'example.com'])) !== null
            && str_contains((string) $this->tagWithConfirm($zoneConfirms, __('cloudflare.zone.danger_confirm', ['domain' => 'example.com'])), 'data-confirm-danger="true"'),
            'Deleting a Cloudflare zone is danger.',
        );
        $this->assertTrue(
            $this->tagWithConfirm($zoneConfirms, __('cloudflare.dns.delete_confirm', ['name' => 'www', 'type' => 'A'])) !== null
            && str_contains((string) $this->tagWithConfirm($zoneConfirms, __('cloudflare.dns.delete_confirm', ['name' => 'www', 'type' => 'A'])), 'data-confirm-danger="true"'),
            'Deleting a DNS record is danger.',
        );
        $this->assertTrue(
            $this->tagWithConfirm($zoneConfirms, __('cloudflare.zones.apply_confirm', ['domain' => 'example.com'])) !== null
            && str_contains((string) $this->tagWithConfirm($zoneConfirms, __('cloudflare.zones.apply_confirm', ['domain' => 'example.com'])), 'data-confirm-danger="false"'),
            'Applying Deamon DNS defaults does not delete the zone.',
        );

        $defaultsHtml = $this->actingAs($this->superAdminUser())
            ->get(route('ops.cloudflare.defaults'))
            ->assertOk()
            ->getContent();
        $defaultConfirms = $this->confirmTags($defaultsHtml);
        $this->assertNotSame([], $defaultConfirms, 'Cloudflare DNS defaults must render at least one confirm.');
        foreach ($defaultConfirms as $tag) {
            $this->assertConfirmContract($tag, 'Cloudflare DNS defaults');
        }
        $this->assertTrue(
            collect($defaultConfirms)->contains(static fn (string $tag): bool => str_contains($tag, 'data-confirm-danger="true"')),
            'Deleting a Deamon DNS default row is danger.',
        );
        $this->assertTrue(
            $this->tagWithConfirm($defaultConfirms, __('cloudflare.defaults.reset_confirm')) !== null
            && str_contains((string) $this->tagWithConfirm($defaultConfirms, __('cloudflare.defaults.reset_confirm')), 'data-confirm-danger="false"'),
            'Resetting defaults is reversible enough to stay not-danger.',
        );
    }

    private function cloudflareEnvelope(Request $request, string $accountId, string $zoneId): PromiseInterface
    {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'GET' && str_contains($url, '/zones/'.$zoneId.'/dns_records')) {
            return Http::response([
                'success' => true,
                'result' => [[
                    'id' => 'cccccccccccccccccccccccccccccccc',
                    'type' => 'A',
                    'name' => 'www',
                    'content' => '1.2.3.4',
                    'ttl' => 1,
                    'priority' => null,
                ]],
                'result_info' => ['total_pages' => 1],
            ], 200);
        }

        if ($method === 'GET' && str_contains($url, '/zones/'.$zoneId)) {
            return Http::response([
                'success' => true,
                'result' => [
                    'id' => $zoneId,
                    'name' => 'example.com',
                    'status' => 'active',
                    'name_servers' => ['ada.ns.cloudflare.com'],
                    'account' => ['id' => $accountId],
                ],
            ], 200);
        }

        if ($method === 'GET' && preg_match('#/client/v4/zones(\?|$)#', $url) === 1) {
            return Http::response([
                'success' => true,
                'result' => [[
                    'id' => $zoneId,
                    'name' => 'example.com',
                    'account' => ['id' => $accountId],
                ]],
                'result_info' => ['count' => 1, 'total_pages' => 1],
            ], 200);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
    }

    /**
     * @param  list<string>  $tags
     */
    private function tagWithConfirm(array $tags, string $body): ?string
    {
        $needle = 'data-confirm="'.e($body).'"';

        foreach ($tags as $tag) {
            if (str_contains($tag, $needle)) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function confirmTags(string $html): array
    {
        preg_match_all('/<(?:form|button)\b[^>]*>/is', $html, $matches);

        return array_values(array_filter(
            $matches[0],
            static fn (string $tag): bool => str_contains($tag, 'data-confirm='),
        ));
    }

    private function assertConfirmContract(string $tag, string $surface): void
    {
        $this->assertMatchesRegularExpression(
            '/\bdata-confirm-title="[^"]+"/i',
            $tag,
            $surface.' confirm is missing data-confirm-title: '.$tag,
        );
        $this->assertMatchesRegularExpression(
            '/\bdata-confirm-label="[^"]+"/i',
            $tag,
            $surface.' confirm is missing data-confirm-label: '.$tag,
        );
        $this->assertMatchesRegularExpression(
            '/\bdata-confirm-danger="(?:true|false)"/i',
            $tag,
            $surface.' confirm is missing an explicit data-confirm-danger: '.$tag,
        );
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }

    private function superAdminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::SuperAdmin->value);

        return $user;
    }
}
