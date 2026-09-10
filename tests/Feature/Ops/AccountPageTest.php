<?php

namespace Tests\Feature\Ops;

use App\Enums\Appearance;
use App\Enums\OpsRole;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_account_page_groups_profile_photo_and_password(): void
    {
        $user = $this->operator(['name' => 'Ada Ops', 'email' => 'ada@example.com']);

        $html = $this->actingAs($user)
            ->get(route('ops.account.show'))
            ->assertOk()
            ->assertSee('Ada Ops', false)
            ->assertSee('ada@example.com', false)
            ->assertSee(__('account.profile'), false)
            ->assertSee(__('account.avatar'), false)
            ->assertSee(__('account.password'), false)
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertDontSee('id="github-token"', false)
            ->assertDontSee(__('settings.github.title'), false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'action="'.url('/logout').'"'));
        $this->assertStringNotContainsString('account-logout', $html);
    }

    public function test_preferences_page_is_language_and_appearance_only(): void
    {
        $html = $this->actingAs($this->operator())
            ->get(route('ops.account.preferences'))
            ->assertOk()
            ->assertSee(__('account.language'), false)
            ->assertSee(__('account.appearance'), false)
            ->assertSee(__('account.appearance_modes.light'), false)
            ->assertSee(__('account.appearance_modes.semidark'), false)
            ->assertSee(__('account.appearance_modes.dark'), false)
            ->assertDontSee('name="current_password"', false)
            ->assertDontSee('id="github-token"', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'action="'.url('/logout').'"'));
    }

    public function test_guest_is_redirected_from_account_and_preferences(): void
    {
        $this->get(route('ops.account.show'))->assertRedirect(route('login'));
        $this->get(route('ops.account.preferences'))->assertRedirect(route('login'));
    }

    public function test_operator_can_update_profile(): void
    {
        $user = $this->operator();

        $this->actingAs($user)
            ->put(route('ops.account.update'), [
                'name' => 'Updated Operator',
                'email' => 'updated@example.com',
            ])
            ->assertRedirect(route('ops.account.show'))
            ->assertSessionHas('status', __('account.flash.profile_updated'));

        $this->assertSame('Updated Operator', $user->fresh()->name);
        $this->assertSame('updated@example.com', $user->fresh()->email);
    }

    public function test_password_change_requires_current_password_and_confirmation(): void
    {
        $user = $this->operator();

        $this->actingAs($user)
            ->from(route('ops.account.show'))
            ->put(route('ops.account.password'), [
                'current_password' => 'wrong-password',
                'password' => 'new-pass-word',
                'password_confirmation' => 'new-pass-word',
            ])
            ->assertRedirect(route('ops.account.show'))
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->put(route('ops.account.password'), [
                'current_password' => 'password',
                'password' => 'new-pass-word',
                'password_confirmation' => 'new-pass-word',
            ])
            ->assertRedirect(route('ops.account.show'))
            ->assertSessionHas('status', __('account.flash.password_updated'));

        $this->assertTrue(Hash::check('new-pass-word', $user->fresh()->password));
    }

    public function test_operator_can_upload_and_remove_avatar(): void
    {
        Storage::fake('public');
        $user = $this->operator();

        $this->actingAs($user)
            ->post(route('ops.account.avatar'), [
                'avatar' => UploadedFile::fake()->image('portrait.jpg', 64, 64),
            ])
            ->assertRedirect(route('ops.account.show'))
            ->assertSessionHas('status', __('account.flash.avatar_updated'));

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user)
            ->delete(route('ops.account.avatar.destroy'))
            ->assertRedirect(route('ops.account.show'))
            ->assertSessionHas('status', __('account.flash.avatar_removed'));

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_operator_can_save_preferences(): void
    {
        $user = $this->operator();

        $this->actingAs($user)
            ->put(route('ops.account.preferences.update'), [
                'locale' => 'tr',
                'appearance' => Appearance::Light->value,
            ])
            ->assertRedirect(route('ops.account.preferences'))
            ->assertSessionHas('status', __('account.flash.preferences_updated'));

        $user->refresh();
        $this->assertSame('tr', $user->locale);
        $this->assertSame(Appearance::Light, $user->appearance);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function operator(array $attributes = []): User
    {
        $operator = User::factory()->create($attributes);
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
