<?php

namespace Tests\Feature\Comail\Support;

use App\Enums\Channel;
use App\Enums\MailProvider;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CloudflareSetting;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Comail\DnsResolver;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

trait ComailTestHelpers
{
    protected FakeMailcow $mailcow;

    protected FakeCloudflare $cloudflare;

    protected FakeDnsResolver $resolver;

    /** @var list<array{url: string, body: array<string, mixed>}> */
    protected array $configurePushes = [];

    protected int $configureStatus = 200;

    protected function setUpComail(bool $withServer = true): void
    {
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();

        $this->mailcow = new FakeMailcow;
        $this->cloudflare = new FakeCloudflare;
        $this->resolver = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->resolver);

        Http::fake($this->mailcow->fake() + $this->cloudflare->fake() + [
            'https://*/internal/control/v1/comail/configure' => function ($request) {
                $this->configurePushes[] = ['url' => $request->url(), 'body' => json_decode($request->body(), true) ?? []];

                return Http::response(['ok' => true], $this->configureStatus);
            },
        ]);

        if ($withServer) {
            $this->comailServer();
        }
    }

    protected function comailServer(): MailServer
    {
        return MailServer::query()->create([
            'name' => 'CoMail',
            'provider' => MailProvider::Mailcow,
            'api_token' => FakeMailcow::KEY,
            'base_url' => FakeMailcow::BASE,
            'mail_hostname' => 'mail.example.test',
            'is_enabled' => true,
        ]);
    }

    protected function cloudflareAccount(): CloudflareSetting
    {
        return CloudflareSetting::factory()->withToken('cf-comail-token')->create();
    }

    protected function comailSite(string $domain = 'acme.test', array $attributes = []): Site
    {
        return Site::factory()->withSecrets()->create($attributes + [
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'primary_domain' => $domain,
            'agent_base_url' => 'https://'.$domain,
        ]);
    }

    protected function comailDomain(Site $site, string $domain = 'acme.test', array $attributes = []): SiteComailDomain
    {
        $this->mailcow->domains[$domain] = ['domain_name' => $domain];
        $this->mailcow->dkim[$domain] = [
            'dkim_txt' => 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey',
            'dkim_selector' => 'dkim',
        ];

        return SiteComailDomain::query()->create($attributes + [
            'site_id' => $site->id,
            'domain' => $domain,
            'is_primary' => true,
            'status' => SiteComailDomain::STATUS_ACTIVE,
            'mailcow_synced_at' => now(),
            'dkim_selector' => 'dkim',
            'dkim_txt' => 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtestkey',
        ]);
    }

    protected function opsUser(OpsRole $role = OpsRole::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function signed(string $method, string $uri, Site $site, array $payload = [], ?string $secret = null): TestResponse
    {
        $body = in_array($method, ['GET', 'DELETE'], true) ? '' : ControlPlaneAgentContract::encodeJson($payload);
        $signed = ControlPlaneAgentSignature::headers($secret ?? (string) $site->agent_secret_encrypted, $body);

        return $this->call($method, '/internal/site/v1/comail'.$uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEAMON_TIMESTAMP' => $signed['timestamp'],
            'HTTP_X_DEAMON_NONCE' => $signed['nonce'],
            'HTTP_X_DEAMON_SIGNATURE' => $signed['signature'],
            'HTTP_X_DEAMON_SITE' => $site->id,
        ], $body);
    }
}
