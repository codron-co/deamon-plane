<?php

namespace Tests\Feature\Ops;

use App\Enums\MailProvider;
use App\Enums\OpsRole;
use App\Models\MailServer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MailServerOpsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'hapi-mail-token-secret-never-show';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_index_never_renders_api_token(): void
    {
        $this->server();

        $this->actingAs($this->operator())
            ->get(route('ops.mail-servers.index'))
            ->assertOk()
            ->assertSee(__('mail.title'), false)
            ->assertSee('Prod mail', false)
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_show_never_renders_api_token(): void
    {
        $server = $this->server();

        $this->actingAs($this->operator())
            ->get(route('ops.mail-servers.show', $server))
            ->assertOk()
            ->assertSee(__('mail.fields.token_saved'), false)
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_operator_stores_token_encrypted(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.mail-servers.store'), [
                'name' => 'Prod mail',
                'provider' => MailProvider::Hostinger->value,
                'api_token' => self::TOKEN,
                'is_enabled' => '1',
            ])
            ->assertRedirect();

        $server = MailServer::query()->first();
        $this->assertNotNull($server);
        $this->assertSame(self::TOKEN, $server->api_token);
        $this->assertNotSame(self::TOKEN, DB::table('mail_servers')->value('api_token'));
        $this->assertArrayNotHasKey('api_token', $server->toArray());

        $this->actingAs($this->operator())
            ->get(route('ops.mail-servers.show', $server))
            ->assertOk()
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_mailcow_cannot_be_stored(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.mail-servers.store'), [
                'name' => 'Mailcow',
                'provider' => MailProvider::Mailcow->value,
                'api_token' => self::TOKEN,
            ])
            ->assertSessionHasErrors('provider');

        $this->assertSame(0, MailServer::query()->count());
    }

    public function test_viewer_cannot_write(): void
    {
        $server = $this->server();
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.mail-servers.store'), [
                'name' => 'Nope',
                'provider' => MailProvider::Hostinger->value,
                'api_token' => self::TOKEN,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ops.mail-servers.test', $server))
            ->assertForbidden();
    }

    public function test_connection_lists_orders_without_logging_token(): void
    {
        $server = $this->server();
        Http::fake([
            'https://developers.hostinger.com/api/mail/v1/orders' => Http::response([
                'data' => [[
                    'id' => 'OR1a2b3c4d5e6f7g',
                    'status' => 'active',
                    'seats' => 5,
                    'domain' => ['name' => 'example.com'],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.mail-servers.show', $server))
            ->post(route('ops.mail-servers.test', $server))
            ->assertRedirect(route('ops.mail-servers.show', $server))
            ->assertSessionHas('status')
            ->assertSessionMissing('error');

        $server->refresh();
        $this->assertSame('example.com', $server->mail_domain);
        $this->assertSame('OR1a2b3c4d5e6f7g', $server->hostinger_order_id);
        $this->assertSame(self::TOKEN, $server->api_token);

        $encoded = json_encode($server->last_probe_payload);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(self::TOKEN, $encoded);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_ends_with($request->url(), '/api/mail/v1/orders')
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN);
        });
    }

    public function test_table_row_opens_show_not_edit(): void
    {
        $server = $this->server();

        $html = $this->actingAs($this->operator())
            ->get(route('ops.mail-servers.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('ops.mail-servers.show', $server), $html);
        $this->assertStringNotContainsString('/mail-servers/'.$server->id.'/edit', $html);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }

    private function server(): MailServer
    {
        return MailServer::factory()->create([
            'name' => 'Prod mail',
            'provider' => MailProvider::Hostinger,
            'api_token' => self::TOKEN,
            'is_enabled' => true,
        ]);
    }
}
