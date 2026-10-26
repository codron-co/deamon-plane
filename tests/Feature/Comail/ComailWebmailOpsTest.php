<?php

namespace Tests\Feature\Comail;

use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Comail\ComailWebmailSso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\Feature\Comail\Support\FakeMailcow;
use Tests\TestCase;

class ComailWebmailOpsTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
        $this->site = $this->comailSite();
        $this->comailDomain($this->site);
        $this->mailcow->addMailbox('info@acme.test', 2048, 300 * 1048576);
        $this->mailcow->mailboxes['info@acme.test']['name'] = 'Acme Bilgi';
        $this->admin = $this->opsUser();
    }

    public function test_super_admin_is_redirected_to_a_one_time_webmail_url_and_the_login_is_audited(): void
    {
        // The customer's own one-click password must survive a support login.
        $this->mailcow->appPasswords[500] = ['id' => 500, 'name' => ComailWebmailSso::APP_NAME, 'mailbox' => 'info@acme.test', 'app_passwd' => 'customer'];
        $this->mailcow->appPasswords[501] = ['id' => 501, 'name' => ComailWebmailSso::OPS_APP_NAME, 'mailbox' => 'info@acme.test', 'app_passwd' => 'old-support'];

        $response = $this->webmail('info@acme.test')
            ->assertStatus(302)
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $location = (string) $response->headers->get('Location');
        $this->assertMatchesRegularExpression('~\A'.preg_quote(FakeMailcow::BASE, '~').'/webmail/\?_task=login&_comail_sso=[a-f0-9]{64}\z~', $location);

        $this->assertArrayHasKey(500, $this->mailcow->appPasswords);
        $this->assertArrayNotHasKey(501, $this->mailcow->appPasswords);
        $created = collect($this->mailcow->appPasswords)->firstWhere('name', ComailWebmailSso::OPS_APP_NAME);
        $this->assertSame('info@acme.test', $created['mailbox']);

        $audit = AuditLog::query()->where('action', 'comail.webmail_login_ops')->sole();
        $this->assertSame($this->admin->id, $audit->actor_user_id);
        $this->assertSame((string) $this->site->id, (string) $audit->subject_id);
        $this->assertSame('info@acme.test', $audit->after['address']);
        $this->assertSame('ops', $audit->after['source']);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $token = (string) $query['_comail_sso'];
        $this->assertStringNotContainsString($token, json_encode($audit->after));
        $this->assertStringNotContainsString($created['app_passwd'], json_encode($audit->after));

        // The webmail server redeems it like a site-issued token.
        $this->assertSame(
            ['username' => 'info@acme.test', 'password' => $created['app_passwd']],
            app(ComailWebmailSso::class)->redeem($token),
        );
    }

    public function test_operator_and_viewer_are_forbidden(): void
    {
        foreach ([OpsRole::Operator, OpsRole::Viewer] as $role) {
            $this->webmail('info@acme.test', $this->opsUser($role))->assertForbidden();
        }

        $this->assertSame([], $this->mailcow->appPasswords);
        $this->assertFalse(AuditLog::query()->where('action', 'comail.webmail_login_ops')->exists());
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->post(route('ops.sites.comail.webmail', [$this->site, 'info@acme.test']))->assertRedirect();

        $this->assertSame([], $this->mailcow->appPasswords);
    }

    public function test_unknown_or_foreign_mailbox_is_404(): void
    {
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');
        $this->mailcow->addMailbox('boss@other.test');

        $this->webmail('nobody@acme.test')->assertNotFound();
        // Exists on mailcow, but on another site's domain.
        $this->webmail('boss@other.test')->assertNotFound();
        // A domain Plane does not know at all.
        $this->webmail('x@stranger.test')->assertNotFound();

        $this->assertSame([], $this->mailcow->appPasswords);
        $this->assertFalse(AuditLog::query()->where('action', 'comail.webmail_login_ops')->exists());
    }

    public function test_inactive_mailbox_is_refused(): void
    {
        $this->mailcow->mailboxes['info@acme.test']['active'] = 0;
        $this->mailcow->mailboxes['info@acme.test']['active_int'] = 0;

        $this->webmail('info@acme.test')
            ->assertRedirect(route('ops.sites.show', $this->site).'#site-comail')
            ->assertSessionHas('error');

        $this->assertSame([], $this->mailcow->appPasswords);
        $this->assertFalse(AuditLog::query()->where('action', 'comail.webmail_login_ops')->exists());
    }

    public function test_mailcow_failure_goes_back_with_an_error(): void
    {
        $this->mailcow->failOn['/add/app-passwd'] = ['type' => 'danger', 'msg' => ['app_passwd_invalid']];

        $this->webmail('info@acme.test')->assertRedirect()->assertSessionHas('error');

        $this->assertFalse(AuditLog::query()->where('action', 'comail.webmail_login_ops')->exists());
    }

    public function test_login_is_throttled_per_user(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->webmail('info@acme.test')->assertStatus(302);
        }

        $this->webmail('info@acme.test')->assertStatus(429);
        // Another Super Admin has his own budget.
        $this->webmail('info@acme.test', $this->opsUser())->assertStatus(302);
    }

    public function test_site_card_loads_the_mailbox_panel(): void
    {
        $this->actingAs($this->admin)->get(route('ops.sites.show', $this->site))
            ->assertOk()
            ->assertSee(route('ops.sites.comail.mailboxes.panel', $this->site), false);
    }

    public function test_panel_lists_mailboxes_and_shows_the_button_only_to_super_admin(): void
    {
        $this->mailcow->addMailbox('old@acme.test');
        $this->mailcow->mailboxes['old@acme.test']['active'] = 0;
        $this->mailcow->mailboxes['old@acme.test']['active_int'] = 0;

        $html = $this->actingAs($this->admin)->get(route('ops.sites.comail.mailboxes.panel', $this->site))
            ->assertOk()
            ->assertSee('data-comail-mailbox="info@acme.test"', false)
            ->assertSee('data-comail-mailbox="old@acme.test"', false)
            ->assertSee('Acme Bilgi')
            ->assertSee('300 MB / 2,0 GB')
            ->assertSee('data-comail-webmail="info@acme.test"', false)
            ->assertDontSee('data-comail-webmail="old@acme.test"', false)
            ->getContent();

        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString(route('ops.sites.comail.webmail', [$this->site, 'info@acme.test']), $html);

        $this->actingAs($this->opsUser(OpsRole::Operator))->get(route('ops.sites.comail.mailboxes.panel', $this->site))
            ->assertOk()
            ->assertSee('data-comail-mailbox="info@acme.test"', false)
            ->assertDontSee('data-comail-webmail', false);
    }

    public function test_panel_shows_a_mailcow_error(): void
    {
        $this->mailcow->httpStatus = 500;

        $this->actingAs($this->admin)->get(route('ops.sites.comail.mailboxes.panel', $this->site))
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertDontSee('data-comail-mailbox=', false);
    }

    public function test_mailbox_search_is_super_admin_only(): void
    {
        $this->mailcow->addMailbox('sales@acme.test');

        $this->actingAs($this->admin)->get(route('ops.comail.mailboxes', ['q' => 'info@acme.test']))
            ->assertOk()
            ->assertSee('data-comail-mailbox="info@acme.test"', false)
            ->assertDontSee('data-comail-mailbox="sales@acme.test"', false)
            ->assertSee('data-comail-webmail="info@acme.test"', false)
            ->assertSee($this->site->name);

        $this->actingAs($this->admin)->get(route('ops.comail.mailboxes', ['q' => 'ACME.test']))
            ->assertOk()
            ->assertSee('data-comail-mailbox="info@acme.test"', false)
            ->assertSee('data-comail-mailbox="sales@acme.test"', false);

        $this->actingAs($this->admin)->get(route('ops.comail.mailboxes', ['q' => 'nobody@stranger.test']))
            ->assertOk()
            ->assertSee('data-comail-search-empty', false);

        $this->actingAs($this->admin)->get(route('ops.comail.mailboxes'))
            ->assertOk()
            ->assertSee(route('ops.comail.mailboxes'), false);

        foreach ([OpsRole::Operator, OpsRole::Viewer] as $role) {
            $this->actingAs($this->opsUser($role))->get(route('ops.comail.mailboxes', ['q' => 'acme.test']))->assertForbidden();
        }
    }

    public function test_nav_link_is_only_shown_to_super_admin(): void
    {
        $this->actingAs($this->admin)->get(route('ops.mail-packages.index'))
            ->assertOk()
            ->assertSee(route('ops.comail.mailboxes'), false);

        $this->actingAs($this->opsUser(OpsRole::Operator))->get(route('ops.mail-packages.index'))
            ->assertOk()
            ->assertDontSee(route('ops.comail.mailboxes'), false);
    }

    private function webmail(string $address, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->admin)
            ->post(route('ops.sites.comail.webmail', [$this->site, $address]));
    }
}
