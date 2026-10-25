<?php

namespace Tests\Feature\Comail;

use App\Enums\MailProvider;
use App\Enums\OpsRole;
use App\Jobs\ApplyComailPackageJob;
use App\Mcp\Tools\RouteToolCatalog;
use App\Models\MailPackage;
use App\Models\MailServer;
use App\Models\SiteComailDomain;
use App\Models\SiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\Feature\Comail\Support\FakeMailcow;
use Tests\TestCase;

class ComailOpsTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail(withServer: false);
    }

    // ── Packages ────────────────────────────────────────────────────────

    public function test_migration_seeds_the_free_default_and_two_inactive_examples(): void
    {
        $packages = MailPackage::query()->orderBy('sort')->get()->keyBy('slug');

        $this->assertSame(['free', 'starter', 'business'], $packages->keys()->all());
        $free = $packages['free'];
        $this->assertTrue($free->is_default);
        $this->assertTrue($free->is_active);
        $this->assertSame('Ücretsiz', $free->name);
        $this->assertSame([1, 1024, 1024, 10], [$free->mailbox_limit, $free->storage_mb, $free->mailbox_max_quota_mb, $free->alias_limit]);
        $this->assertNull($free->domain_limit);
        $this->assertNull($free->price_monthly);
        $this->assertFalse($packages['starter']->is_active);
        $this->assertSame([5, 10240, 5120, 50], [$packages['starter']->mailbox_limit, $packages['starter']->storage_mb, $packages['starter']->mailbox_max_quota_mb, $packages['starter']->alias_limit]);
        $this->assertFalse($packages['business']->is_active);
        $this->assertSame([25, 51200, 10240, 200], [$packages['business']->mailbox_limit, $packages['business']->storage_mb, $packages['business']->mailbox_max_quota_mb, $packages['business']->alias_limit]);
        $this->assertSame(1, MailPackage::query()->where('is_default', true)->count());
    }

    public function test_default_package_is_recreated_when_missing(): void
    {
        DB::table('mail_packages')->delete();

        $default = MailPackage::default();

        $this->assertSame('free', $default->slug);
        $this->assertTrue($default->is_default);
    }

    public function test_packages_crud_keeps_exactly_one_default(): void
    {
        $admin = $this->opsUser();

        $this->actingAs($admin)->get(route('ops.mail-packages.index'))
            ->assertOk()
            ->assertSee('Ücretsiz', false)
            ->assertSee('data-mail-package="starter"', false);

        $this->actingAs($admin)->post(route('ops.mail-packages.store'), [
            'name' => 'Kurumsal',
            'slug' => 'kurumsal',
            'mailbox_limit' => 100,
            'storage_mb' => 102400,
            'mailbox_max_quota_mb' => 20480,
            'alias_limit' => 500,
            'domain_limit' => 3,
            'price_monthly' => '499.90',
            'currency' => 'try',
            'is_active' => '1',
            'is_default' => '1',
        ])->assertRedirect();

        $package = MailPackage::query()->where('slug', 'kurumsal')->firstOrFail();
        $this->assertTrue($package->is_default);
        $this->assertSame('TRY', $package->currency);
        $this->assertSame('499.90', (string) $package->price_monthly);
        $this->assertSame(1, MailPackage::query()->where('is_default', true)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'mail_package.created', 'subject_id' => $package->id]);

        // Max quota above total storage is refused.
        $this->actingAs($admin)->put(route('ops.mail-packages.update', $package), [
            'name' => 'Kurumsal', 'slug' => 'kurumsal', 'mailbox_limit' => 100, 'storage_mb' => 100,
            'mailbox_max_quota_mb' => 200, 'alias_limit' => 5, 'currency' => 'TRY',
        ])->assertSessionHasErrors('mailbox_max_quota_mb');

        // Unchecking default on the default package keeps it default.
        $this->actingAs($admin)->put(route('ops.mail-packages.update', $package), [
            'name' => 'Kurumsal+', 'slug' => 'kurumsal', 'mailbox_limit' => 100, 'storage_mb' => 102400,
            'mailbox_max_quota_mb' => 20480, 'alias_limit' => 500, 'currency' => 'TRY', 'is_default' => '0', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertTrue($package->fresh()->is_default);
        $this->assertSame('Kurumsal+', $package->fresh()->name);

        $this->actingAs($admin)->delete(route('ops.mail-packages.destroy', $package))->assertSessionHas('error');
        $this->assertDatabaseHas('mail_packages', ['id' => $package->id]);

        $starter = MailPackage::query()->where('slug', 'starter')->firstOrFail();
        $site = $this->comailSite();
        $site->forceFill(['mail_package_id' => $starter->id])->save();
        $this->actingAs($admin)->delete(route('ops.mail-packages.destroy', $starter))->assertSessionHas('error');

        $business = MailPackage::query()->where('slug', 'business')->firstOrFail();
        $this->actingAs($admin)->delete(route('ops.mail-packages.destroy', $business))->assertRedirect(route('ops.mail-packages.index'));
        $this->assertDatabaseMissing('mail_packages', ['id' => $business->id]);
    }

    public function test_viewer_cannot_change_packages(): void
    {
        $viewer = $this->opsUser(OpsRole::Viewer);
        $package = MailPackage::default();

        $this->actingAs($viewer)->get(route('ops.mail-packages.index'))->assertOk();
        $this->actingAs($viewer)->get(route('ops.mail-packages.create'))->assertForbidden();
        $this->actingAs($viewer)->put(route('ops.mail-packages.update', $package), ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('ops.mail-packages.destroy', $package))->assertForbidden();
    }

    public function test_package_edit_reapplies_limits_to_sites_using_it(): void
    {
        Queue::fake();
        $this->comailServer();
        $site = $this->comailSite();
        $this->comailDomain($site);
        $default = MailPackage::default();

        $this->actingAs($this->opsUser())->put(route('ops.mail-packages.update', $default), [
            'name' => 'Ücretsiz', 'slug' => 'free', 'mailbox_limit' => 2, 'storage_mb' => 2048,
            'mailbox_max_quota_mb' => 1024, 'alias_limit' => 10, 'currency' => 'TRY', 'is_default' => '1', 'is_active' => '1',
        ])->assertRedirect();

        Queue::assertPushed(ApplyComailPackageJob::class, fn (ApplyComailPackageJob $job): bool => $job->siteId === (string) $site->id);
    }

    // ── CoMail mail server ──────────────────────────────────────────────

    public function test_super_admin_creates_and_tests_the_comail_server_without_leaking_the_key(): void
    {
        $admin = $this->opsUser();

        $this->actingAs($admin)->get(route('ops.mail-servers.create'))
            ->assertOk()
            ->assertSee('value="mailcow"', false)
            ->assertDontSee('value="mailcow" disabled', false);

        $this->actingAs($admin)->post(route('ops.mail-servers.store'), [
            'name' => 'CoMail',
            'provider' => 'mailcow',
            'api_token' => FakeMailcow::KEY,
            'base_url' => FakeMailcow::BASE.'/',
            'mail_hostname' => 'MAIL.example.test',
            'is_enabled' => '1',
        ])->assertRedirect();

        $server = MailServer::query()->firstOrFail();
        $this->assertSame(MailProvider::Mailcow, $server->provider);
        $this->assertSame(FakeMailcow::BASE, $server->base_url);
        $this->assertSame('mail.example.test', $server->mail_hostname);
        $this->assertNotSame(FakeMailcow::KEY, DB::table('mail_servers')->value('api_token'));
        $this->assertTrue(MailServer::comail()?->is($server));
        $this->assertStringNotContainsString(FakeMailcow::KEY, (string) json_encode(DB::table('audit_logs')->get()));

        $this->actingAs($admin)->post(route('ops.mail-servers.test', $server))
            ->assertSessionHas('status', __('mail.flash.comail_tested', ['version' => '2026-09']));
        $this->assertSame('2026-09', $server->fresh()->last_probe_payload['version']);
        $this->assertSame('/get/status/version', $this->mailcow->paths('GET')[0]);

        $this->actingAs($admin)->get(route('ops.mail-servers.show', $server))
            ->assertOk()
            ->assertSee('mail.example.test', false)
            ->assertSee(__('mail.comail.sites_title'), false)
            ->assertDontSee(FakeMailcow::KEY, false);
    }

    public function test_comail_server_needs_https_base_url_and_super_admin(): void
    {
        $this->actingAs($this->opsUser())->post(route('ops.mail-servers.store'), [
            'name' => 'CoMail', 'provider' => 'mailcow', 'api_token' => FakeMailcow::KEY, 'base_url' => 'http://mail.example.test',
        ])->assertSessionHasErrors('base_url');

        $this->actingAs($this->opsUser(OpsRole::Operator))->post(route('ops.mail-servers.store'), [
            'name' => 'CoMail', 'provider' => 'mailcow', 'api_token' => FakeMailcow::KEY, 'base_url' => FakeMailcow::BASE,
        ])->assertForbidden();

        $this->assertSame(0, MailServer::query()->count());
    }

    public function test_failed_comail_test_flashes_an_error(): void
    {
        $server = $this->comailServer();
        $server->forceFill(['api_token' => 'wrong-key'])->save();

        $this->actingAs($this->opsUser())->post(route('ops.mail-servers.test', $server))
            ->assertSessionHas('error', __('mail.errors.comail_test_failed'));
    }

    public function test_comail_server_is_not_offered_as_a_hostinger_site_mail_server(): void
    {
        $comail = $this->comailServer();
        $site = $this->comailSite();

        $this->actingAs($this->opsUser())
            ->post(route('ops.sites.mail', $site), ['mail_server_id' => $comail->id])
            ->assertSessionHasErrors('mail_server_id');

        $this->assertNull($site->fresh()->mail_server_id);
    }

    // ── Site detail CoMail card ─────────────────────────────────────────

    public function test_site_card_shows_domains_and_actions(): void
    {
        $this->comailServer();
        $site = $this->comailSite();
        $this->comailDomain($site);

        $this->actingAs($this->opsUser())->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('id="site-comail"', false)
            ->assertSee('data-comail-domain="acme.test"', false)
            ->assertSee('data-comail-mx="waiting"', false)
            ->assertSee(route('ops.sites.comail.activate', [$site, SiteComailDomain::query()->first()]), false);
    }

    public function test_site_card_without_server_points_to_mail_servers(): void
    {
        $site = $this->comailSite();

        $this->actingAs($this->opsUser())->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('data-comail-no-server', false);
    }

    public function test_package_change_add_domain_check_and_repush_from_the_card(): void
    {
        $this->comailServer();
        $site = $this->comailSite();
        $this->comailDomain($site);
        $admin = $this->opsUser();
        $starter = MailPackage::query()->where('slug', 'starter')->firstOrFail();
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'acme.test', 'is_primary' => true]);
        SiteDomain::query()->create(['site_id' => $site->id, 'domain' => 'extra.test']);

        $this->actingAs($admin)->post(route('ops.sites.comail.package', $site), ['mail_package_id' => $starter->id])
            ->assertRedirect(route('ops.sites.show', $site).'#site-comail');
        $this->assertSame($starter->id, $site->fresh()->mail_package_id);
        // Limits re-applied to mailcow (queue is sync in tests).
        $this->assertSame(5, $this->mailcow->domains['acme.test']['mailboxes']);
        $this->assertSame(10240, $this->mailcow->domains['acme.test']['quota']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.package_changed', 'subject_id' => $site->id]);

        $this->actingAs($admin)->post(route('ops.sites.comail.domains.store', $site), ['domain' => 'foreign.test'])
            ->assertSessionHasErrors('domain');
        $this->actingAs($admin)->post(route('ops.sites.comail.domains.store', $site), ['domain' => 'extra.test'])
            ->assertSessionHas('status');
        $this->assertDatabaseHas('site_comail_domains', ['domain' => 'extra.test', 'site_id' => $site->id, 'is_primary' => false, 'status' => 'active']);

        $row = SiteComailDomain::query()->where('domain', 'acme.test')->firstOrFail();
        $this->resolver->set('MX', 'acme.test', [['target' => 'mail.example.test', 'priority' => 10]]);
        $this->actingAs($admin)->post(route('ops.sites.comail.dns.check', [$site, $row]))->assertSessionHas('status');
        $this->assertNotNull($row->fresh()->mx_active_at);

        $this->actingAs($admin)->post(route('ops.sites.comail.dns.apply', [$site, $row]))->assertSessionHas('error');

        $pushes = count($this->configurePushes);
        $this->actingAs($admin)->post(route('ops.sites.comail.configure', $site))->assertSessionHas('status', __('mail.flash.configure_queued'));
        $this->assertCount($pushes + 1, $this->configurePushes);
    }

    public function test_card_actions_refuse_a_domain_of_another_site_and_viewers(): void
    {
        $this->comailServer();
        $site = $this->comailSite();
        $other = $this->comailSite('other.test');
        $row = $this->comailDomain($other, 'other.test');

        $this->actingAs($this->opsUser())->post(route('ops.sites.comail.activate', [$site, $row]))->assertNotFound();
        $this->actingAs($this->opsUser(OpsRole::Viewer))->post(route('ops.sites.comail.package', $site), [])->assertForbidden();
    }

    public function test_every_comail_mcp_tool_points_at_a_registered_route(): void
    {
        $names = [];
        foreach (RouteToolCatalog::tools() as $tool) {
            $names[] = $tool->name();
            $this->assertTrue(app('router')->has($tool->route()), $tool->name());
        }

        foreach (['set_site_mail_package', 'add_comail_domain', 'apply_comail_dns', 'check_comail_dns', 'activate_comail_mx', 'resend_comail_configure', 'create_mail_package', 'update_mail_package', 'delete_mail_package'] as $name) {
            $this->assertContains($name, $names);
        }
    }
}
