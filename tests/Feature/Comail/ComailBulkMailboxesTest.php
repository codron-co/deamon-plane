<?php

namespace Tests\Feature\Comail;

use App\Enums\OpsRole;
use App\Http\Controllers\Ops\ComailMailboxOpsController;
use App\Models\AuditLog;
use App\Models\MailServer;
use App\Models\Site;
use App\Services\Comail\ComailMailboxActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

/**
 * Bulk mailbox actions and the "change on next webmail login" flag
 * (docs: CONTRACT-bulk-mailboxes): the CMS API and the Super Admin page.
 */
class ComailBulkMailboxesTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
        $this->site = $this->comailSite();
        $this->comailDomain($this->site);
        $this->mailcow->addMailbox('info@acme.test');
        $this->mailcow->addMailbox('satis@acme.test');
        // A domain on the CoMail server that belongs to no site.
        $this->mailcow->addMailbox('kim@nosite.test');
        $this->mailcow->addMailbox('ali@nosite.test');
    }

    // ── CMS API ─────────────────────────────────────────────────────────

    public function test_mailbox_rows_report_the_must_change_flag_and_patch_sets_it_with_the_password(): void
    {
        $this->signed('GET', '/mailboxes', $this->site)
            ->assertOk()
            ->assertJsonPath('mailboxes.0.must_change_password', false);

        $this->signed('PATCH', '/mailboxes/info@acme.test', $this->site, [
            'password' => 'Gecici-Parola-7',
            'force_password_change' => true,
        ])->assertOk()->assertJsonPath('mailbox.must_change_password', true);

        $this->assertSame('Gecici-Parola-7', $this->mailcow->passwords['info@acme.test']);
        $this->assertSame('1', $this->mailcow->mailboxes['info@acme.test']['attributes']['force_pw_update']);
        $audit = AuditLog::query()->where('action', 'comail.mailbox_updated')->sole();
        $this->assertSame(['password', 'force_password_change'], $audit->after['changed']);
        $this->assertStringNotContainsString('Gecici-Parola-7', json_encode($audit->after));

        // Without the flag the password changes and the flag stays as it is.
        $this->signed('PATCH', '/mailboxes/info@acme.test', $this->site, ['password' => 'Baska-Parola-8'])->assertOk();
        $this->assertSame('1', $this->mailcow->mailboxes['info@acme.test']['attributes']['force_pw_update']);
    }

    public function test_bulk_deactivate_and_activate_only_touch_the_sites_own_mailboxes(): void
    {
        $this->signed('POST', '/mailboxes/bulk', $this->site, [
            'action' => 'deactivate',
            'addresses' => ['info@acme.test', 'SATIS@acme.test', 'kim@nosite.test'],
        ])->assertOk()
            ->assertJsonPath('results.0', ['address' => 'info@acme.test', 'ok' => true])
            ->assertJsonPath('results.1', ['address' => 'satis@acme.test', 'ok' => true])
            ->assertJsonPath('results.2.ok', false)
            ->assertJsonPath('results.2.error', 'not_found');

        $this->assertSame(0, $this->mailcow->mailboxes['info@acme.test']['active']);
        $this->assertSame(0, $this->mailcow->mailboxes['satis@acme.test']['active']);
        $this->assertSame(1, $this->mailcow->mailboxes['kim@nosite.test']['active']);
        $this->assertSame(2, AuditLog::query()->where('action', 'comail.mailbox_updated')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'comail.access_denied')->exists());

        $this->signed('POST', '/mailboxes/bulk', $this->site, [
            'action' => 'activate',
            'addresses' => ['info@acme.test'],
        ])->assertOk()->assertJsonPath('results.0.ok', true);
        $this->assertSame(1, $this->mailcow->mailboxes['info@acme.test']['active']);
    }

    public function test_bulk_password_sets_each_password_and_the_flag_without_logging_them(): void
    {
        $this->signed('POST', '/mailboxes/bulk', $this->site, [
            'action' => 'password',
            'passwords' => [
                ['address' => 'info@acme.test', 'password' => 'Bir-Parola-11'],
                ['address' => 'satis@acme.test', 'password' => 'Iki-Parola-22'],
            ],
            'force_password_change' => true,
        ])->assertOk()->assertJsonCount(2, 'results');

        $this->assertSame('Bir-Parola-11', $this->mailcow->passwords['info@acme.test']);
        $this->assertSame('Iki-Parola-22', $this->mailcow->passwords['satis@acme.test']);
        $this->assertSame('1', $this->mailcow->mailboxes['satis@acme.test']['attributes']['force_pw_update']);

        $after = json_encode(AuditLog::query()->pluck('after')->all());
        $this->assertStringNotContainsString('Bir-Parola-11', $after);
        $this->assertStringNotContainsString('Iki-Parola-22', $after);
        $this->assertStringContainsString('"bulk":true', $after);
    }

    public function test_bulk_with_a_weak_password_changes_nothing(): void
    {
        $this->signed('POST', '/mailboxes/bulk', $this->site, [
            'action' => 'password',
            'passwords' => [
                ['address' => 'info@acme.test', 'password' => 'Saglam-Parola-1'],
                ['address' => 'satis@acme.test', 'password' => 'sadeceharf'],
            ],
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $this->assertArrayNotHasKey('info@acme.test', $this->mailcow->passwords);
        $this->assertSame([], $this->mailcow->paths());
    }

    public function test_bulk_delete_and_its_limits(): void
    {
        $this->signed('POST', '/mailboxes/bulk', $this->site, [
            'action' => 'delete',
            'addresses' => ['satis@acme.test'],
        ])->assertOk()->assertJsonPath('results.0.ok', true);

        $this->assertArrayNotHasKey('satis@acme.test', $this->mailcow->mailboxes);
        $this->assertTrue(AuditLog::query()->where('action', 'comail.mailbox_deleted')->exists());

        $tooMany = array_map(static fn (int $i): string => "u{$i}@acme.test", range(1, ComailMailboxActions::MAX_ITEMS + 1));
        $this->signed('POST', '/mailboxes/bulk', $this->site, ['action' => 'delete', 'addresses' => $tooMany])->assertStatus(422);
        $this->signed('POST', '/mailboxes/bulk', $this->site, ['action' => 'archive', 'addresses' => ['info@acme.test']])->assertStatus(422);
        $this->signed('POST', '/mailboxes/bulk', $this->site, ['action' => 'activate'])->assertStatus(422);
        $this->assertArrayHasKey('info@acme.test', $this->mailcow->mailboxes);
    }

    public function test_generated_passwords_are_readable_and_pass_the_rule(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $password = ComailMailboxActions::generatePassword();
            $this->assertSame(12, strlen($password));
            $this->assertNull(ComailMailboxActions::passwordProblem($password));
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password);
            $seen[$password] = true;
        }
        $this->assertCount(50, $seen);
    }

    // ── Super Admin page ────────────────────────────────────────────────

    public function test_page_lists_every_mailbox_on_the_server_including_domains_without_a_site(): void
    {
        $admin = $this->opsUser();

        $this->actingAs($admin)->get(route('ops.comail.mailboxes'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('info@acme.test')
            ->assertSee('kim@nosite.test')
            ->assertSee('data-comail-no-site', false)
            // A real submit: ops-async would swallow the one-time passwords in the flash.
            ->assertSee('data-ops-bulk data-ops-native', false)
            ->assertSee($this->site->name);

        $this->actingAs($admin)->get(route('ops.comail.mailboxes', ['site' => 'none']))
            ->assertOk()
            ->assertSee('kim@nosite.test')
            ->assertDontSee('info@acme.test');

        $this->actingAs($admin)->get(route('ops.comail.mailboxes', ['domain' => 'acme.test', 'q' => 'sat']))
            ->assertOk()
            ->assertSee('satis@acme.test')
            ->assertDontSee('info@acme.test')
            ->assertDontSee('kim@nosite.test');
    }

    public function test_page_gives_temporary_passwords_once_and_audits_on_the_site_or_the_server(): void
    {
        $admin = $this->opsUser();

        $this->actingAs($admin)
            ->from(route('ops.comail.mailboxes'))
            ->post(route('ops.comail.mailboxes.bulk'), [
                'action' => 'password',
                'mailbox_ids' => ['info@acme.test', 'kim@nosite.test'],
                'password_mode' => 'each',
                'force_password_change' => '1',
            ])
            ->assertRedirect(route('ops.comail.mailboxes'))
            ->assertSessionHas('status');

        $info = $this->mailcow->passwords['info@acme.test'];
        $kim = $this->mailcow->passwords['kim@nosite.test'];
        $this->assertNotSame($info, $kim);
        $this->assertNull(ComailMailboxActions::passwordProblem($info));
        $this->assertSame('1', $this->mailcow->mailboxes['kim@nosite.test']['attributes']['force_pw_update']);

        // Shown on the next page, then gone.
        $this->actingAs($admin)->get(route('ops.comail.mailboxes'))
            ->assertOk()
            ->assertSee('data-comail-revealed', false)
            ->assertSee($info)
            ->assertSee($kim);
        $this->actingAs($admin)->get(route('ops.comail.mailboxes'))
            ->assertOk()
            ->assertDontSee('data-comail-revealed', false)
            ->assertDontSee($info);

        $siteAudit = AuditLog::query()->where('action', 'comail.mailbox_password_reset')->where('subject_type', $this->site->getMorphClass())->sole();
        $this->assertSame('info@acme.test', $siteAudit->after['address']);
        $this->assertSame($admin->id, $siteAudit->actor_user_id);
        $this->assertTrue($siteAudit->after['force_password_change']);
        $server = MailServer::comail();
        $this->assertNotNull($server);
        $serverAudit = AuditLog::query()->where('action', 'comail.mailbox_password_reset')->where('subject_type', $server->getMorphClass())->sole();
        $this->assertSame('kim@nosite.test', $serverAudit->after['address']);

        $after = json_encode(AuditLog::query()->pluck('after')->all());
        $this->assertStringNotContainsString($info, $after);
        $this->assertStringNotContainsString($kim, $after);
    }

    public function test_page_same_password_typed_generated_or_refused(): void
    {
        $admin = $this->opsUser();

        $this->actingAs($admin)->post(route('ops.comail.mailboxes.bulk'), [
            'action' => 'password',
            'mailbox_ids' => ['kim@nosite.test', 'ali@nosite.test'],
            'password_mode' => 'same',
            'password' => 'Firma-Sifre-2026',
            'force_password_change' => '0',
        ])->assertRedirect();
        $this->assertSame('Firma-Sifre-2026', $this->mailcow->passwords['kim@nosite.test']);
        $this->assertSame('Firma-Sifre-2026', $this->mailcow->passwords['ali@nosite.test']);
        $this->assertSame('0', $this->mailcow->mailboxes['ali@nosite.test']['attributes']['force_pw_update']);

        $this->actingAs($admin)->post(route('ops.comail.mailboxes.bulk'), [
            'action' => 'password',
            'mailbox_ids' => ['kim@nosite.test', 'ali@nosite.test'],
            'password_mode' => 'same',
        ])->assertRedirect()->assertSessionHas(ComailMailboxOpsController::SESSION_PASSWORDS);
        $this->assertSame($this->mailcow->passwords['kim@nosite.test'], $this->mailcow->passwords['ali@nosite.test']);
        $this->assertNotSame('Firma-Sifre-2026', $this->mailcow->passwords['kim@nosite.test']);

        $before = $this->mailcow->passwords;
        $this->actingAs($admin)->post(route('ops.comail.mailboxes.bulk'), [
            'action' => 'password',
            'mailbox_ids' => ['kim@nosite.test'],
            'password_mode' => 'same',
            'password' => 'kisa1',
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame($before, $this->mailcow->passwords);
    }

    public function test_page_deactivates_and_deletes_and_reports_missing_mailboxes(): void
    {
        $admin = $this->opsUser();

        $this->actingAs($admin)->post(route('ops.comail.mailboxes.bulk'), [
            'action' => 'deactivate',
            'mailbox_ids' => ['kim@nosite.test', 'yok@nosite.test'],
        ])->assertRedirect()->assertSessionHas('status')->assertSessionHas('error');
        $this->assertSame(0, $this->mailcow->mailboxes['kim@nosite.test']['active']);
        $this->assertTrue(AuditLog::query()->where('action', 'comail.mailbox_deactivated')->exists());

        $this->actingAs($admin)->post(route('ops.comail.mailboxes.bulk'), [
            'action' => 'delete',
            'mailbox_ids' => ['ali@nosite.test'],
        ])->assertRedirect();
        $this->assertArrayNotHasKey('ali@nosite.test', $this->mailcow->mailboxes);

        $this->actingAs($admin)->post(route('ops.comail.mailboxes.bulk'), ['action' => 'delete'])
            ->assertSessionHasErrors('mailbox_ids');
    }

    public function test_page_and_bulk_are_super_admin_only(): void
    {
        foreach ([OpsRole::Operator, OpsRole::Viewer] as $role) {
            $user = $this->opsUser($role);
            $this->actingAs($user)->get(route('ops.comail.mailboxes'))->assertForbidden();
            $this->actingAs($user)->post(route('ops.comail.mailboxes.bulk'), [
                'action' => 'delete',
                'mailbox_ids' => ['kim@nosite.test'],
            ])->assertForbidden();
            $this->actingAs($user)->post(route('ops.comail.mailboxes.webmail', 'kim@nosite.test'))->assertForbidden();
        }

        $this->assertArrayHasKey('kim@nosite.test', $this->mailcow->mailboxes);
    }

    public function test_webmail_login_for_a_mailbox_without_a_site_is_audited_on_the_server(): void
    {
        $admin = $this->opsUser();

        $this->actingAs($admin)->post(route('ops.comail.mailboxes.webmail', 'kim@nosite.test'))
            ->assertStatus(302)
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $audit = AuditLog::query()->where('action', 'comail.webmail_login_ops')->sole();
        $this->assertSame((new MailServer)->getMorphClass(), $audit->subject_type);
        $this->assertSame('kim@nosite.test', $audit->after['address']);

        $this->mailcow->mailboxes['ali@nosite.test']['active'] = 0;
        $this->mailcow->mailboxes['ali@nosite.test']['active_int'] = 0;
        $this->actingAs($admin)->from(route('ops.comail.mailboxes'))
            ->post(route('ops.comail.mailboxes.webmail', 'ali@nosite.test'))
            ->assertRedirect(route('ops.comail.mailboxes'))
            ->assertSessionHas('error');
    }
}
