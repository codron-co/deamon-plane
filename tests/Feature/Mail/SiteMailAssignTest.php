<?php

namespace Tests\Feature\Mail;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteMailAssignTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'hapi-assign-token-never-show';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['app.url' => 'https://plane.example.com']);
    }

    public function test_assigning_hostinger_server_pushes_configure_without_token(): void
    {
        $server = MailServer::factory()->hostingerReady(self::TOKEN)->create();
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
        ]);

        Http::fake([
            'https://shop.example.test/internal/control/v1/mail/configure' => Http::response([
                'ok' => true,
                'mail' => ['provider' => 'hostinger', 'enabled' => true],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->put(route('ops.sites.update', $site), $this->sitePayload($site, $server->id))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame($server->id, $site->mail_server_id);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/internal/control/v1/mail/configure')
                && str_contains($body, '"provider":"hostinger"')
                && str_contains($body, '"mail_domain":"example.com"')
                && ! str_contains($body, self::TOKEN)
                && ! str_contains($body, 'api_token');
        });
    }

    public function test_assign_without_agent_secret_skips_push(): void
    {
        $server = MailServer::factory()->hostingerReady(self::TOKEN)->create();
        $site = Site::factory()->create([
            'status' => SiteStatus::Draft,
            'primary_domain' => 'draft.example.test',
        ]);

        $this->actingAs($this->operator())
            ->put(route('ops.sites.update', $site), $this->sitePayload($site, $server->id))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $this->assertSame($server->id, $site->fresh()->mail_server_id);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function sitePayload(Site $site, ?string $mailServerId): array
    {
        return [
            'slug' => $site->slug,
            'name' => $site->name,
            'domain' => $site->primary_domain,
            'channel' => $site->channel instanceof Channel ? $site->channel->value : 'main',
            'mail_server_id' => $mailServerId,
            'notes' => $site->notes,
        ];
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
