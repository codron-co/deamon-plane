<?php

namespace Tests\Feature\Comail;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Jobs\ConfigureSiteComailJob;
use App\Jobs\EnsureComailDomainJob;
use App\Jobs\ReconcileComailPushJob;
use App\Models\Deployment;
use App\Models\MailServer;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailDns;
use App\Services\Comail\ComailDomainProvisioner;
use App\Services\Comail\ComailSiteConfigurer;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\Feature\Comail\Support\FakeMailcow;
use Tests\TestCase;

class ComailEnrollmentTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
    }

    public function test_site_create_queues_the_primary_domain(): void
    {
        Queue::fake();

        $this->actingAs($this->opsUser(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'izyem',
                'name' => 'Izyem',
                'domain' => 'izyem.test',
                'channel' => 'beta',
                'coolify_server_uuid' => 'no48ksggg0k8sk4o4w08gks8',
            ])
            ->assertRedirect();

        Queue::assertPushed(EnsureComailDomainJob::class, fn (EnsureComailDomainJob $job): bool => $job->domain === 'izyem.test' && $job->primary);
    }

    public function test_site_create_without_comail_server_queues_nothing(): void
    {
        MailServer::query()->delete();
        Queue::fake();

        $this->actingAs($this->opsUser(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'izyem',
                'name' => 'Izyem',
                'domain' => 'izyem.test',
                'channel' => 'beta',
                'coolify_server_uuid' => 'no48ksggg0k8sk4o4w08gks8',
            ])
            ->assertRedirect();

        Queue::assertNotPushed(EnsureComailDomainJob::class);
    }

    public function test_preview_and_temporary_hosts_are_never_enrolled(): void
    {
        Queue::fake();
        $preview = $this->comailSite('izyem.codron.co');
        $temporary = $this->comailSite('real.test', ['temporary_domain' => 'real.test']);

        $this->assertFalse(EnsureComailDomainJob::dispatchForPrimary($preview));
        $this->assertFalse(EnsureComailDomainJob::dispatchForPrimary($temporary));
        Queue::assertNotPushed(EnsureComailDomainJob::class);
    }

    public function test_provision_success_queues_the_primary_domain(): void
    {
        Queue::fake();
        $site = $this->comailSite('izyem.test');
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'trigger' => DeploymentTrigger::Manual,
        ]);

        app(SiteProvisioner::class)->markSucceeded($site, $deployment, new CoolifyDeployment('dep-1', 'finished', null, null, []));

        Queue::assertPushed(EnsureComailDomainJob::class, fn (EnsureComailDomainJob $job): bool => $job->siteId === (string) $site->id && $job->domain === 'izyem.test');
    }

    public function test_enroll_job_creates_domain_with_limits_reads_dkim_first_and_pushes_configure(): void
    {
        $this->cloudflareAccount();
        $this->cloudflare->zone('acme.test');
        $site = $this->comailSite();

        (new EnsureComailDomainJob((string) $site->id, 'acme.test', true))->handle(
            app(ComailDomainProvisioner::class),
            app(ComailDns::class),
            app(ComailSiteConfigurer::class),
        );

        $row = SiteComailDomain::query()->where('domain', 'acme.test')->firstOrFail();
        $this->assertSame((string) $site->id, (string) $row->site_id);
        $this->assertTrue($row->is_primary);
        $this->assertSame('active', $row->status);
        $this->assertSame('dkim', $row->dkim_selector);
        $this->assertStringStartsWith('v=DKIM1;', (string) $row->dkim_txt);

        $domain = $this->mailcow->domains['acme.test'];
        $this->assertSame(1, $domain['mailboxes']);
        $this->assertSame(1024, $domain['quota']);
        $this->assertSame(1024, $domain['maxquota']);
        $this->assertSame(1024, $domain['defquota']);
        $this->assertSame(10, $domain['aliases']);
        // add/domain made the key: no add/dkim call (mailcow would answer danger).
        $this->assertNotContains('/add/dkim', $this->mailcow->paths());

        // Fresh zone: CoMail MX written.
        $this->assertSame(['mail.example.test'], array_column($this->cloudflare->at('acme.test', 'MX'), 'content'));

        $this->assertCount(1, $this->configurePushes);
        $this->assertSame('https://acme.test/internal/control/v1/comail/configure', $this->configurePushes[0]['url']);
        $this->assertSame([
            'enabled' => true,
            'provider' => 'comail',
            'site_id' => (string) $site->id,
            'plane_base_url' => $this->configurePushes[0]['body']['plane_base_url'],
            'webmail_url' => FakeMailcow::BASE,
        ], $this->configurePushes[0]['body']);
        $this->assertStringNotContainsString(FakeMailcow::KEY, (string) json_encode($this->configurePushes));
        $this->assertNotNull($site->fresh()->comail_configured_at);

        // Idempotent: a second run edits the limits, keeps the row, creates no second domain.
        EnsureComailDomainJob::dispatchSync((string) $site->id, 'acme.test', true);
        $this->assertSame(1, SiteComailDomain::query()->count());
        $this->assertContains('/edit/domain', $this->mailcow->paths());
    }

    public function test_enroll_adds_dkim_only_when_mailcow_has_none(): void
    {
        $this->mailcow->dkimWithDomain = false;
        $site = $this->comailSite();

        EnsureComailDomainJob::dispatchSync((string) $site->id, 'acme.test', true);

        $this->assertContains('/add/dkim', $this->mailcow->paths());
        $this->assertSame('active', SiteComailDomain::query()->value('status'));
    }

    public function test_enroll_failure_is_recorded_on_the_row(): void
    {
        $this->mailcow->failOn['/add/domain'] = ['type' => 'danger', 'msg' => ['domain_invalid', 'acme.test']];
        $site = $this->comailSite();

        EnsureComailDomainJob::dispatchSync((string) $site->id, 'acme.test', true);

        $row = SiteComailDomain::query()->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('domain_invalid', (string) $row->last_error);
        $this->assertSame([], $this->configurePushes);
    }

    public function test_configure_failure_is_recorded_and_reconcile_repushes(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->configureStatus = 500;

        app(ComailSiteConfigurer::class)->sync($site);
        $site->refresh();
        $this->assertSame('http_500', $site->comail_configure_error);
        $this->assertNotNull($site->comail_configure_failed_at);
        $this->assertSame('failed', $site->comailConfigureState());

        $this->configureStatus = 200;
        (new ReconcileComailPushJob)->handle(app(ComailSiteConfigurer::class));
        $site->refresh();
        $this->assertNull($site->comail_configure_failed_at);
        $this->assertNull($site->comail_configure_error);
        $this->assertSame('configured', $site->comailConfigureState());
        $this->assertCount(2, $this->configurePushes);

        // Configured sites are left alone.
        (new ReconcileComailPushJob)->handle(app(ComailSiteConfigurer::class));
        $this->assertCount(2, $this->configurePushes);
    }

    public function test_configure_disables_when_no_comail_server(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        MailServer::query()->delete();

        ConfigureSiteComailJob::dispatchSync((string) $site->id);

        $this->assertSame(false, $this->configurePushes[0]['body']['enabled']);
        $this->assertNull($this->configurePushes[0]['body']['provider']);
        $this->assertArrayNotHasKey('webmail_url', $this->configurePushes[0]['body']);
    }

    public function test_enroll_command_dry_run_and_apply(): void
    {
        Queue::fake();
        $one = $this->comailSite('one.test', ['slug' => 'one']);
        $two = $this->comailSite('two.test', ['slug' => 'two']);
        $this->comailSite('preview.codron.co', ['slug' => 'preview']);
        $this->comailDomain($two, 'two.test');

        $this->artisan('ops:comail-enroll')->assertFailed();

        $this->artisan('ops:comail-enroll', ['--all' => true, '--dry-run' => true])
            ->expectsOutputToContain('one: one.test kaydedilecek.')
            ->expectsOutputToContain('two: two.test zaten CoMail’de.')
            ->expectsOutputToContain('preview: ana alan adı postaya uygun değil')
            ->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('ops:comail-enroll', ['--site' => ['one']])->assertSuccessful();
        Queue::assertPushed(EnsureComailDomainJob::class, 1);
        Queue::assertPushed(EnsureComailDomainJob::class, fn (EnsureComailDomainJob $job): bool => $job->siteId === (string) $one->id);
    }

    public function test_enroll_never_takes_a_domain_owned_by_another_site(): void
    {
        Queue::fake();
        $owner = $this->comailSite('owner.test');
        $this->comailDomain($owner, 'shared.test');
        $other = $this->comailSite('shared.test');

        $this->assertFalse(EnsureComailDomainJob::dispatchForPrimary($other));
    }
}
