<?php

namespace Tests\Feature\Mail;

use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteMailboxRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteMailboxRequestDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_pending_request_is_fulfilled_once(): void
    {
        [$site, $request] = $this->pendingRequest();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.mailbox-requests.fulfill', [$site, $request]))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status', __('mail.flash.request_fulfilled', ['email' => 'info@shop.example.test']));

        $this->assertSame(SiteMailboxRequest::STATUS_FULFILLED, $request->fresh()->status);
        $this->assertSame(1, $this->auditCount('mail.mailbox_request_fulfilled'));
    }

    public function test_rejected_request_cannot_be_fulfilled_afterwards(): void
    {
        [$site, $request] = $this->pendingRequest();
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('ops.sites.mailbox-requests.reject', [$site, $request]));

        $this->actingAs($operator)
            ->post(route('ops.sites.mailbox-requests.fulfill', [$site, $request]))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error', __('mail.flash.request_already_handled', [
                'email' => 'info@shop.example.test',
                'status' => __('mail.requests.statuses.rejected'),
            ]));

        $this->assertSame(SiteMailboxRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame(0, $this->auditCount('mail.mailbox_request_fulfilled'));
    }

    public function test_double_reject_writes_one_audit_row(): void
    {
        [$site, $request] = $this->pendingRequest();
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('ops.sites.mailbox-requests.reject', [$site, $request]));
        $this->actingAs($operator)
            ->post(route('ops.sites.mailbox-requests.reject', [$site, $request]))
            ->assertSessionHas('error');

        $this->assertSame(1, $this->auditCount('mail.mailbox_request_rejected'));
    }

    /**
     * @return array{0: Site, 1: SiteMailboxRequest}
     */
    private function pendingRequest(): array
    {
        $site = Site::factory()->create(['primary_domain' => 'shop.example.test']);
        $request = $site->mailboxRequests()->create([
            'local_part' => 'info',
            'domain' => 'shop.example.test',
            'note' => null,
            'requester_kind' => 'admin',
            'requester_ref' => '1',
            'status' => SiteMailboxRequest::STATUS_PENDING,
        ]);

        return [$site, $request];
    }

    private function auditCount(string $action): int
    {
        return AuditLog::query()->where('action', $action)->count();
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
