<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\MailServer;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registry-empty and filter-empty are different problems. One needs a Hostinger
 * account added; the other needs the chips cleared. Same pattern as Sites.
 */
class MailListEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_an_empty_registry_explains_itself_and_offers_create(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.mail-servers.index'))
            ->assertOk()
            ->assertSee(__('mail.empty'))
            ->assertSee(__('mail.empty_hint'))
            ->assertSee(route('ops.mail-servers.create'), false)
            ->assertDontSee(__('mail.empty_filtered_title'))
            ->assertDontSee('ops-filter-chips', false);
    }

    public function test_a_viewer_sees_why_there_is_no_create_action(): void
    {
        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.mail-servers.index'))
            ->assertOk()
            ->assertSee(__('mail.empty'))
            ->assertSee(__('ops.viewer_readonly'))
            ->assertDontSee(route('ops.mail-servers.create'), false);
    }

    public function test_search_and_status_keep_only_matching_servers(): void
    {
        MailServer::factory()->create(['name' => 'Alpha Hostinger', 'is_enabled' => true]);
        MailServer::factory()->create(['name' => 'Beta Hostinger', 'is_enabled' => false]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.mail-servers.index', ['q' => 'Alpha']))
            ->assertOk()
            ->assertSee('Alpha Hostinger')
            ->assertDontSee('Beta Hostinger');

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.mail-servers.index', ['status' => 'disabled']))
            ->assertOk()
            ->assertSee('Beta Hostinger')
            ->assertDontSee('Alpha Hostinger');
    }

    public function test_a_filtered_empty_result_names_the_filters_and_the_registry_size(): void
    {
        MailServer::factory()->count(3)->create();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.mail-servers.index', ['q' => 'nothing-here', 'status' => 'disabled']))
            ->assertOk()
            ->assertSee(__('mail.empty_filtered_title'))
            ->assertSee(__('mail.empty_filtered_hint', ['total' => 3]))
            ->assertDontSee(__('mail.empty'))
            ->assertSee(__('mail.filter_search'))
            ->assertSee('nothing-here')
            ->assertSee(__('mail.filter_status'))
            ->assertSee(__('ops.disabled'));
    }

    public function test_each_filter_chip_drops_only_itself_and_clearing_all_stays_prominent(): void
    {
        MailServer::factory()->create();

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.mail-servers.index', [
                'q' => 'nope',
                'status' => 'enabled',
            ]))
            ->assertOk();

        $response->assertSee(e(route('ops.mail-servers.index', ['status' => 'enabled'])), false);
        $response->assertSee(e(route('ops.mail-servers.index', ['q' => 'nope'])), false);
        $response->assertSee(__('ops.actions.clear_filters'));
        $this->assertStringContainsString(
            'btn-primary',
            $response->getContent(),
        );
    }

    public function test_the_async_region_carries_the_same_empty_states(): void
    {
        MailServer::factory()->create();

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.mail-servers.index', ['q' => 'no-such-server']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee(__('mail.empty_filtered_title'))
            ->assertSee('ops-filter-chips', false)
            ->assertDontSee('ops-sidebar', false);
    }

    public function test_filtered_copy_reads_as_turkish_for_a_turkish_operator(): void
    {
        MailServer::factory()->create();

        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.mail-servers.index', ['q' => 'yok']))
            ->assertOk()
            ->assertSee(trans('mail.empty_filtered_title', [], 'tr'), false)
            ->assertSee(trans('ops.actions.clear_filters', [], 'tr'), false)
            ->assertDontSee(trans('mail.empty_filtered_title', [], 'en'), false);
    }

    public function test_registry_empty_hint_names_a_mailbox_not_a_box(): void
    {
        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.mail-servers.index'))
            ->assertOk()
            ->assertSee(trans('mail.empty_hint', [], 'tr'), false)
            ->assertSee('posta kutusu', false)
            ->assertDontSee('kutu açabilmesi', false);
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
