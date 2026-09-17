<?php

namespace Tests\Feature\Ops;

use App\Enums\SiteStatus;
use App\Jobs\ReconcileDeskronPushJob;
use App\Models\DeskronSetting;
use App\Models\Site;
use App\Services\Agent\ControlPlaneAgentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileDeskronPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_it_pushes_to_a_site_the_key_never_reached(): void
    {
        $this->readySetting();
        $this->fakeAccepted();

        $site = $this->site(['deskron_pushed_at' => null, 'deskron_push_failed_at' => null]);

        $this->sweep();

        Http::assertSent(fn (Request $request): bool => str_ends_with(
            $request->url(),
            ControlPlaneAgentContract::DESKRON_CONFIGURE_PATH,
        ));

        $this->assertNotNull($site->refresh()->deskron_pushed_at);
    }

    public function test_it_pushes_to_a_site_whose_last_attempt_failed(): void
    {
        $this->readySetting();
        $this->fakeAccepted();

        // Answered 405 before it had the configure route, after its last success.
        $site = $this->site([
            'deskron_pushed_at' => now()->subDay(),
            'deskron_push_failed_at' => now()->subHour(),
            'deskron_push_error' => 'http_405',
        ]);

        $this->sweep();

        $site->refresh();
        $this->assertNull($site->deskron_push_failed_at);
        $this->assertNull($site->deskron_push_error);
    }

    public function test_it_leaves_a_configured_site_alone(): void
    {
        $this->readySetting();
        Http::fake();

        $this->site([
            'deskron_pushed_at' => now(),
            'deskron_push_failed_at' => now()->subDay(),
        ]);

        $this->sweep();

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_until_the_key_is_set_here(): void
    {
        Http::fake();

        $this->site(['deskron_pushed_at' => null]);

        $this->sweep();

        Http::assertNothingSent();
    }

    public function test_a_site_still_on_older_code_records_its_reason_without_failing_the_sweep(): void
    {
        $this->readySetting();
        Http::fake([
            'https://shop.example.test/*' => Http::response('Method Not Allowed', 405),
        ]);

        $site = $this->site(['deskron_pushed_at' => null]);

        $this->sweep();

        $site->refresh();
        $this->assertSame('http_405', $site->deskron_push_error);
        $this->assertNotNull($site->deskron_push_failed_at);
        $this->assertNull($site->deskron_pushed_at);
    }

    private function sweep(): void
    {
        app()->call([new ReconcileDeskronPushJob, 'handle']);
    }

    private function fakeAccepted(): void
    {
        Http::fake([
            'https://shop.example.test/*' => Http::response(
                ['ok' => true, 'deskron' => ['configured' => true]],
                200,
            ),
        ]);
    }

    private function readySetting(): void
    {
        DeskronSetting::query()->create([
            'application_id' => '01KDESKRONAPP0000000000000',
            'api_key' => 'dsk_master_key',
            'webhook_secret' => 'whsec_value',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(array $attributes = []): Site
    {
        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
            ...$attributes,
        ]);
    }
}
