<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\CoolifyServer;
use App\Models\Deployment;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteMailBinding;
use App\Models\User;
use App\Support\Lists\SiteListColumns;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteListOptionalColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_NEW = ['site', 'last_deploy', 'server', 'auto_deploy', 'mail'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_last_deploy_is_on_by_default_and_shows_result_and_commit(): void
    {
        $this->assertContains('last_deploy', SiteListColumns::defaults());

        $site = Site::factory()->create(['name' => 'Deploy Row']);
        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'commit_sha' => 'feedc0ffee1234567',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(3),
            'diagnosis' => [],
        ]);

        $this->actingAs($this->user())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.columns.last_deploy'))
            ->assertSee('data-last-deploy-chip', false)
            ->assertSee('status-failed', false)
            ->assertSee(DeploymentStatus::Failed->label())
            ->assertSee('feedc0f');
    }

    public function test_a_site_without_deploys_says_so(): void
    {
        Site::factory()->create();

        $this->actingAs($this->user())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.cells.deploy_none'));
    }

    public function test_server_auto_deploy_and_mail_columns_render_when_chosen(): void
    {
        $connection = CoolifyConnection::factory()->create(['name' => 'Prod Coolify']);
        CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'srv-uuid-1',
            'name' => 'hetzner-fsn-1',
            'is_active' => true,
        ]);
        $mail = MailServer::factory()->create(['name' => 'Hostinger Mail']);

        $site = Site::factory()->create([
            'name' => 'Full Row',
            'coolify_connection_id' => $connection->id,
            'coolify_server_uuid' => 'srv-uuid-1',
            'coolify_auto_deploy' => false,
            'coolify_pinned_sha' => 'abc1234def',
            'coolify_deploy_settings_at' => now(),
            'mail_server_id' => $mail->id,
        ]);
        SiteMailBinding::query()->create([
            'site_id' => $site->id,
            'hostinger_order_id' => 'order-1',
            'mail_domain' => 'full-row.test',
        ]);

        Site::factory()->create([
            'name' => 'Orphan Row',
            'coolify_connection_id' => $connection->id,
            'coolify_server_uuid' => 'zz-not-in-inventory',
        ]);

        $user = $this->user();
        $user->saveListPreference(SiteListColumns::LIST_KEY, ['columns' => self::ALL_NEW]);

        $this->actingAs($user->fresh())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('hetzner-fsn-1')
            ->assertSee('Prod Coolify')
            // Unknown server: a muted short uuid, never an empty cell.
            ->assertSee('zz-not-i')
            ->assertSee(__('sites.cells.auto_off'))
            ->assertSee('abc1234')
            // Never read from Coolify: an explicit "not read" state, not "off".
            ->assertSee(__('sites.cells.auto_unknown'))
            ->assertSee('Hostinger Mail')
            ->assertSee(trans_choice('sites.cells.mail_domains', 1, ['count' => 1]));
    }

    public function test_optional_columns_do_not_add_queries_per_row(): void
    {
        $user = $this->user();
        $user->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => [...self::ALL_NEW, 'domain', 'publish', 'live', 'theme'],
        ]);

        $this->seedRows(2);
        // Warm the once-per-process work (permission cache, cached fleet scans)
        // so the comparison only sees what the rows themselves cost.
        $this->countQueries($user);
        $few = $this->countQueries($user);

        $this->seedRows(4);
        $many = $this->countQueries($user);

        $this->assertSame($few, $many, 'Rendering 6 rows must cost the same queries as 2.');
    }

    public function test_a_custom_column_order_is_persisted_and_rendered(): void
    {
        Site::factory()->create();
        $user = $this->user();

        $this->actingAs($user)
            ->from(route('ops.sites'))
            ->post(route('ops.sites.list-preferences'), [
                'columns' => ['site', 'updated', 'domain', 'publish'],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['site', 'updated', 'domain', 'publish'],
            $user->fresh()->listPreference(SiteListColumns::LIST_KEY)['columns'],
        );

        $html = $this->actingAs($user->fresh())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $thead = substr($html, (int) strpos($html, '<thead>'), (int) strpos($html, '</thead>') - (int) strpos($html, '<thead>'));
        $this->assertLessThan(strpos($thead, 'sort=domain'), strpos($thead, 'sort=updated'));
        $this->assertLessThan(strpos($thead, 'sort=publish'), strpos($thead, 'sort=domain'));

        // The picker lists visible columns in that order, so posting it back keeps it.
        $this->assertLessThan(
            strpos($html, 'data-ops-column-key="domain"'),
            strpos($html, 'data-ops-column-key="updated"'),
        );
        $this->assertStringContainsString('data-ops-column-move="-1"', $html);
    }

    public function test_the_site_column_is_forced_first_but_the_rest_keep_their_order(): void
    {
        $this->assertSame(
            ['site', 'publish', 'domain'],
            SiteListColumns::sanitize(['publish', 'site', 'domain', 'publish', 'made_up']),
        );
        $this->assertSame(
            ['site', 'mail', 'domain'],
            SiteListColumns::sanitize(['mail', 'domain']),
        );
    }

    public function test_a_saved_view_keeps_its_column_order(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post(route('ops.sites.list-views.store'), [
                'name' => 'Ordered',
                'columns' => ['site', 'mail', 'updated', 'domain'],
                'sort_key' => 'updated',
                'sort_dir' => 'desc',
            ])
            ->assertRedirect();

        $stored = $user->fresh()->listPreference(SiteListColumns::LIST_KEY);
        $this->assertSame(['site', 'mail', 'updated', 'domain'], $stored['views'][0]['columns']);
        $this->assertSame(['site', 'mail', 'updated', 'domain'], $stored['columns']);
    }

    private function seedRows(int $count): void
    {
        $connection = CoolifyConnection::factory()->create();
        $mail = MailServer::factory()->create();

        for ($i = 0; $i < $count; $i++) {
            $uuid = 'srv-'.bin2hex(random_bytes(4));
            CoolifyServer::query()->create([
                'coolify_connection_id' => $connection->id,
                'uuid' => $uuid,
                'name' => 'server-'.$uuid,
                'is_active' => true,
            ]);
            $site = Site::factory()->create([
                'coolify_connection_id' => $connection->id,
                'coolify_server_uuid' => $uuid,
                'mail_server_id' => $mail->id,
            ]);
            Deployment::factory()->create([
                'site_id' => $site->id,
                'status' => DeploymentStatus::Finished,
                'commit_sha' => str_repeat('a', 40),
            ]);
            SiteMailBinding::query()->create([
                'site_id' => $site->id,
                'hostinger_order_id' => 'order-'.$uuid,
                'mail_domain' => $uuid.'.test',
            ]);
        }
    }

    private function countQueries(User $user): int
    {
        $user = $user->fresh();
        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('ops.sites'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
