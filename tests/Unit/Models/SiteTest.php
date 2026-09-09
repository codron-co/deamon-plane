<?php

namespace Tests\Unit\Models;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_draft_site(): void
    {
        $site = Site::factory()->create();

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'slug' => $site->slug,
            'status' => SiteStatus::Draft->value,
        ]);

        $this->assertSame(SiteStatus::Draft, $site->status);
        $this->assertTrue($site->channel->isAllowed());
        $this->assertContains($site->channel->value, config('ops.channels'));
        $this->assertSame(26, strlen($site->id));
        $this->assertNull($site->app_key_encrypted);
        $this->assertNull($site->agent_secret_encrypted);
    }

    public function test_default_status_is_draft_when_omitted(): void
    {
        $site = Site::query()->create([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'primary_domain' => 'izyem.example.test',
            'channel' => Channel::Main,
        ]);

        $this->assertSame(SiteStatus::Draft, $site->fresh()->status);
    }

    public function test_git_repository_defaults_from_ops_config(): void
    {
        $site = Site::factory()->create([
            'git_repository' => null,
        ]);

        $this->assertSame(
            config('ops.deamon.repository'),
            $site->git_repository
        );
        $this->assertSame(
            'https://github.com/codron-co/deamon.git',
            $site->git_repository
        );
    }

    public function test_channel_must_be_in_ops_config_allowlist(): void
    {
        config(['ops.channels' => ['main']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Channel [alpha] is not in config('ops.channels').");

        Site::factory()->create([
            'channel' => Channel::Alpha,
        ]);
    }

    public function test_encrypted_secrets_round_trip_and_are_hidden(): void
    {
        $appKey = 'base64:'.base64_encode(str_repeat('a', 32));
        $agentSecret = 'agent-secret-not-for-logs';

        $site = Site::factory()->create([
            'app_key_encrypted' => $appKey,
            'agent_secret_encrypted' => $agentSecret,
        ]);

        $site->refresh();

        $this->assertSame($appKey, $site->app_key_encrypted);
        $this->assertSame($agentSecret, $site->agent_secret_encrypted);

        $array = $site->toArray();
        $this->assertArrayNotHasKey('app_key_encrypted', $array);
        $this->assertArrayNotHasKey('agent_secret_encrypted', $array);

        $raw = $site->getRawOriginal('app_key_encrypted');
        $this->assertNotSame($appKey, $raw);
        $this->assertNotSame($agentSecret, $site->getRawOriginal('agent_secret_encrypted'));
    }

    public function test_site_has_domains_deployments_and_audit_relations(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create([
            'primary_domain' => 'shop.example.test',
        ]);

        $domain = SiteDomain::factory()->primary()->create([
            'site_id' => $site->id,
            'domain' => $site->primary_domain,
        ]);

        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'channel' => $site->channel,
            'trigger' => DeploymentTrigger::Create,
            'status' => DeploymentStatus::Queued,
            'requested_by' => $user->id,
        ]);

        $log = $site->auditLogs()->create([
            'actor_user_id' => $user->id,
            'action' => 'site.created',
            'after' => ['slug' => $site->slug],
            'ip' => '127.0.0.1',
        ]);

        $this->assertTrue($site->domains->contains($domain));
        $this->assertTrue($site->deployments->contains($deployment));
        $this->assertTrue($site->auditLogs->contains($log));
        $this->assertTrue($deployment->requestedBy->is($user));
        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertTrue($log->subject->is($site));
    }

    public function test_status_machine_allows_documented_transitions(): void
    {
        $site = Site::factory()->create();

        $this->assertTrue($site->canTransitionTo(SiteStatus::Provisioning));
        $this->assertFalse($site->canTransitionTo(SiteStatus::Active));

        $site->transitionTo(SiteStatus::Provisioning);
        $site->save();

        $this->assertSame(SiteStatus::Provisioning, $site->fresh()->status);
        $this->assertTrue($site->canTransitionTo(SiteStatus::Active));
        $this->assertTrue($site->canTransitionTo(SiteStatus::Error));
    }

    public function test_can_switch_channel_requires_provisioned_active_or_error(): void
    {
        $draft = Site::factory()->create([
            'status' => SiteStatus::Draft,
            'coolify_app_uuid' => 'app-1',
        ]);
        $this->assertFalse($draft->canSwitchChannel());

        $active = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'app-1',
        ]);
        $this->assertTrue($active->canSwitchChannel());

        $deploying = Site::factory()->create([
            'status' => SiteStatus::Deploying,
            'coolify_app_uuid' => 'app-1',
        ]);
        $this->assertFalse($deploying->canSwitchChannel());

        $unprovisioned = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => null,
        ]);
        $this->assertFalse($unprovisioned->canSwitchChannel());
    }

    public function test_status_machine_rejects_illegal_transition(): void
    {
        $site = Site::factory()->create();

        $this->expectException(LogicException::class);
        $site->transitionTo(SiteStatus::Archived);
    }
}
