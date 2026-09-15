<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Http\Middleware\ConvertOpsAjaxRedirect;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AsyncRedirectErrorBagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Route::middleware(['web', ConvertOpsAjaxRedirect::class])->group(function (): void {
            Route::post('/_test/async/error-bag', fn () => redirect('/')->withErrors(['thing' => 'Bag message.']));
            Route::post('/_test/async/error-and-bag', fn () => redirect('/')->with('error', 'Flash error.')->withErrors(['thing' => 'Bag message.']));
            Route::post('/_test/async/status', fn () => redirect('/')->with('status', 'Done.'));
        });
    }

    public function test_error_bag_becomes_a_failed_json_answer(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/_test/async/error-bag')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('type', 'error')
            ->assertJsonPath('message', 'Bag message.');
    }

    public function test_flash_error_wins_over_the_bag(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/_test/async/error-and-bag')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Flash error.');
    }

    public function test_status_still_answers_ok(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/_test/async/status')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('type', 'status')
            ->assertJsonPath('message', 'Done.');
    }

    public function test_non_json_request_keeps_the_redirect_and_the_bag(): void
    {
        $this->actingAs($this->operator())
            ->post('/_test/async/error-bag')
            ->assertRedirect('/')
            ->assertSessionHasErrors('thing');
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
