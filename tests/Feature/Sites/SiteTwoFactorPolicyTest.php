<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteTwoFactorPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    private function site(): Site
    {
        $site = Site::factory()->create();
        $site->forceFill(['agent_secret_encrypted' => 'agent-secret-for-tests', 'agent_base_url' => 'https://musteri.example'])->save();

        return $site;
    }

    private function operator(): User
    {
        $operator = User::factory()->create(['locale' => 'tr']);
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }

    public function test_operator_pushes_policy_over_the_signed_agent_and_it_is_audited(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response(['ok' => true, 'two_factor' => [
            'required' => true, 'grace_days' => 3, 'admins_total' => 2, 'admins_with_two_factor' => 0,
        ]], 200)]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.two-factor.update', $site), ['required' => '1', 'grace_days' => '3'])
            ->assertRedirect(route('ops.sites.show', $site).'#infrastructure')
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), ControlPlaneAgentContract::TWO_FACTOR_POLICY_PATH)
            && $request['required'] === true
            && $request['grace_days'] === 3
            && $request->hasHeader(ControlPlaneAgentContract::HEADER_SIGNATURE));

        $audit = AuditLog::query()->where('action', 'site.two_factor_policy.updated')->firstOrFail();
        $this->assertTrue($audit->after['required']);
    }

    public function test_agent_failure_is_reported_and_validation_rejects_bad_input(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response([], 404)]);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('ops.sites.two-factor.update', $site), ['required' => '1'])
            ->assertSessionHasErrorsIn('two_factor', 'agent');

        $this->actingAs($operator)
            ->post(route('ops.sites.two-factor.update', $site), ['required' => '1', 'grace_days' => '999'])
            ->assertSessionHasErrorsIn('two_factor', 'grace_days');
    }

    public function test_viewer_cannot_change_the_policy(): void
    {
        $site = $this->site();
        $viewer = User::factory()->create(['locale' => 'tr']);
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.sites.two-factor.update', $site), ['required' => '1'])
            ->assertForbidden();
    }
}
