<?php

namespace Tests\Feature\Comail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Comail\Support\ComailTestHelpers;
use Tests\TestCase;

class ComailSpamApiTest extends TestCase
{
    use ComailTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpComail();
    }

    public function test_spam_state_defaults_to_normal_and_hides_domain_wide_entries(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');
        $this->mailcow->policies[900] = ['prefid' => 900, 'object' => 'acme.test', 'list' => 'bl', 'value' => '*@domainwide.test'];

        $this->signed('GET', '/mailboxes/info@acme.test/spam', $site)
            ->assertOk()
            ->assertJsonPath('level', 'normal')
            ->assertJsonPath('score', ['low' => 8, 'high' => 15])
            ->assertJsonPath('allow', [])
            ->assertJsonPath('block', []);
    }

    public function test_level_change_sets_the_mailcow_score_and_is_audited(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');

        $this->signed('PUT', '/mailboxes/info@acme.test/spam', $site, ['level' => 'strict'])
            ->assertOk()
            ->assertJsonPath('level', 'strict')
            ->assertJsonPath('score', ['low' => 5, 'high' => 10]);
        $this->assertSame('5,10', $this->mailcow->spamScores['info@acme.test']);

        $this->signed('PUT', '/mailboxes/info@acme.test/spam', $site, ['level' => 'relaxed'])->assertJsonPath('level', 'relaxed');
        $this->assertSame('10,20', $this->mailcow->spamScores['info@acme.test']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.spam_level', 'subject_id' => $site->id]);

        $this->signed('PUT', '/mailboxes/info@acme.test/spam', $site, ['level' => 'paranoid'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->mailcow->spamScores['info@acme.test'] = '6,12';
        $this->signed('GET', '/mailboxes/info@acme.test/spam', $site)->assertJsonPath('level', 'custom');
    }

    public function test_allow_and_block_lists_add_normalise_and_remove(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');

        $this->signed('POST', '/mailboxes/info@acme.test/spam/allow', $site, ['value' => 'Partner@Supplier.test'])
            ->assertCreated()
            ->assertJsonPath('allow.0.value', 'partner@supplier.test');
        $response = $this->signed('POST', '/mailboxes/info@acme.test/spam/block', $site, ['value' => '@spam.test'])
            ->assertCreated()
            ->assertJsonPath('block.0.value', '*@spam.test');
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.spam_list', 'subject_id' => $site->id]);

        $this->signed('POST', '/mailboxes/info@acme.test/spam/block', $site, ['value' => '*@spam.test'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
        $this->signed('POST', '/mailboxes/info@acme.test/spam/block', $site, ['value' => 'partner@supplier.test'])
            ->assertStatus(422);
        $this->signed('POST', '/mailboxes/info@acme.test/spam/allow', $site, ['value' => 'not an address'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $id = $response->json('block.0.id');
        $this->signed('DELETE', '/mailboxes/info@acme.test/spam/block/'.$id, $site)
            ->assertOk()
            ->assertJsonPath('block', []);
        $this->assertSame([], array_values(array_filter($this->mailcow->policies, static fn (array $row): bool => $row['list'] === 'bl')));
    }

    public function test_list_entries_and_mailboxes_of_another_site_are_not_reachable(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->addMailbox('info@acme.test');
        $other = $this->comailSite('other.test');
        $this->comailDomain($other, 'other.test');
        $this->mailcow->addMailbox('boss@other.test');
        $this->mailcow->policies[700] = ['prefid' => 700, 'object' => 'boss@other.test', 'list' => 'bl', 'value' => '*@x.test'];

        $this->signed('GET', '/mailboxes/boss@other.test/spam', $site)->assertNotFound();
        $this->signed('PUT', '/mailboxes/boss@other.test/spam', $site, ['level' => 'strict'])->assertNotFound();
        $this->signed('DELETE', '/mailboxes/info@acme.test/spam/block/700', $site)
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
        $this->assertArrayHasKey(700, $this->mailcow->policies);
        $this->assertArrayNotHasKey('boss@other.test', $this->mailcow->spamScores);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.access_denied', 'subject_id' => $site->id]);
    }

    public function test_quarantine_lists_only_the_sites_messages_newest_first(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->quarantineItem(11, 'info@acme.test', ['created' => 1793000100, 'virus_flag' => 3]);
        $this->mailcow->quarantineItem(12, 'sales@acme.test', ['created' => 1793000200, 'subject' => 'Kampanya', 'score' => '18.25']);
        $this->mailcow->quarantineItem(13, 'boss@other.test');

        $response = $this->signed('GET', '/quarantine', $site)->assertOk()->assertJsonCount(2, 'items');

        $response->assertJsonPath('items.0.id', 12)
            ->assertJsonPath('items.0.mailbox', 'sales@acme.test')
            ->assertJsonPath('items.0.subject', 'Kampanya')
            ->assertJsonPath('items.0.score', 18.25)
            ->assertJsonPath('items.0.virus', false)
            ->assertJsonPath('items.1.id', 11)
            ->assertJsonPath('items.1.virus', true)
            ->assertJsonPath('items.1.sender', 'spam@spam.test');
        $this->assertNotNull($response->json('items.0.created_at'));
    }

    public function test_quarantine_release_and_delete_check_the_recipient(): void
    {
        $site = $this->comailSite();
        $this->comailDomain($site);
        $this->mailcow->quarantineItem(21, 'info@acme.test');
        $this->mailcow->quarantineItem(22, 'info@acme.test');
        $this->mailcow->quarantineItem(23, 'boss@other.test');

        $this->signed('POST', '/quarantine/21/release', $site)->assertOk()->assertJsonPath('ok', true);
        $this->assertSame([21], $this->mailcow->released);
        $this->assertDatabaseHas('audit_logs', ['action' => 'comail.quarantine_release', 'subject_id' => $site->id]);

        $this->signed('DELETE', '/quarantine/22', $site)->assertOk();
        $this->assertArrayNotHasKey(22, $this->mailcow->quarantine);

        $this->signed('POST', '/quarantine/23/release', $site)->assertNotFound();
        $this->signed('DELETE', '/quarantine/23', $site)->assertNotFound();
        $this->signed('DELETE', '/quarantine/abc', $site)->assertNotFound();
        $this->assertArrayHasKey(23, $this->mailcow->quarantine);
        $this->assertSame([21], $this->mailcow->released);
    }
}
