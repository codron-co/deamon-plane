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

    public function test_assigning_hostinger_server_matches_site_domain_order(): void
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
            'https://developers.hostinger.com/api/mail/v1/orders*' => Http::response([
                'data' => [[
                    'id' => 'OR9siteorder',
                    'status' => 'active',
                    'seats' => 1,
                    'domain' => ['name' => 'shop.example.test'],
                ]],
                'meta' => ['current_page' => 1, 'per_page' => 100, 'total' => 1, 'last_page' => 1],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->put(route('ops.sites.update', $site), $this->sitePayload($site, $server->id))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame($server->id, $site->mail_server_id);
        $this->assertSame('OR9siteorder', $site->hostinger_order_id);
        $this->assertSame('shop.example.test', $site->mail_domain);
        $this->assertNull($server->fresh()->hostinger_order_id);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/internal/control/v1/mail/configure')
                && str_contains($body, '"provider":"hostinger"')
                && str_contains($body, '"mail_domain":"shop.example.test"')
                && ! str_contains($body, self::TOKEN)
                && ! str_contains($body, 'api_token');
        });
    }

    public function test_unmatched_domain_does_not_enable_plugin(): void
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
                'mail' => ['enabled' => false],
            ], 200),
            'https://developers.hostinger.com/api/mail/v1/orders*' => Http::response([
                'data' => [[
                    'id' => 'ORotherdomain',
                    'status' => 'active',
                    'domain' => ['name' => 'other.example.test'],
                ]],
                'meta' => ['last_page' => 1],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->put(route('ops.sites.update', $site), $this->sitePayload($site, $server->id))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame($server->id, $site->mail_server_id);
        $this->assertNull($site->hostinger_order_id);
        $this->assertNull($site->mail_domain);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/internal/control/v1/mail/configure')
                && str_contains($body, '"enabled":false')
                && ! str_contains($body, self::TOKEN);
        });
    }

    public function test_assign_without_agent_secret_skips_push(): void
    {
        $server = MailServer::factory()->hostingerReady(self::TOKEN)->create();
        $site = Site::factory()->create([
            'status' => SiteStatus::Draft,
            'primary_domain' => 'draft.example.test',
        ]);

        Http::fake([
            'https://developers.hostinger.com/api/mail/v1/orders*' => Http::response([
                'data' => [],
                'meta' => ['last_page' => 1],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->put(route('ops.sites.update', $site), $this->sitePayload($site, $server->id))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $this->assertSame($server->id, $site->fresh()->mail_server_id);
        $this->assertNull($site->fresh()->hostinger_order_id);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/api/mail/v1/orders');
        });
        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), '/mail/configure');
        });
    }

    public function test_site_detail_assigns_mail_server(): void
    {
        $server = MailServer::factory()->hostingerReady(self::TOKEN)->create();
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
        ]);

        Http::fake([
            'https://shop.example.test/internal/control/v1/mail/configure' => Http::response(['ok' => true], 200),
            'https://developers.hostinger.com/api/mail/v1/orders*' => Http::response([
                'data' => [[
                    'id' => 'OR9siteorder',
                    'status' => 'active',
                    'domain' => ['name' => 'shop.example.test'],
                ]],
                'meta' => ['last_page' => 1],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('id="site_detail_mail_server"', false)
            ->assertSee($server->name, false);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.mail', $site), ['mail_server_id' => $server->id])
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame($server->id, $site->mail_server_id);
        $this->assertSame('OR9siteorder', $site->hostinger_order_id);
    }

    public function test_viewer_cannot_assign_mail_from_detail(): void
    {
        $server = MailServer::factory()->hostingerReady(self::TOKEN)->create();
        $site = Site::factory()->create();
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.sites.mail', $site), ['mail_server_id' => $server->id])
            ->assertForbidden();

        $this->assertNull($site->fresh()->mail_server_id);
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
