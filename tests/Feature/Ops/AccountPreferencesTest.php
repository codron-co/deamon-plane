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

class AccountPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_operator_can_update_profile_and_password(): void
    {
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->get(route('ops.account.show'))
            ->assertOk()
            ->assertSee('Account', false)
            ->assertSee($user->email, false);

        $this->actingAs($user)
            ->put(route('ops.account.update'), [
                'name' => 'Updated Operator',
                'email' => 'updated.ops@example.test',
            ])
            ->assertRedirect(route('ops.account.show'));

        $user->refresh();
        $this->assertSame('Updated Operator', $user->name);
        $this->assertSame('updated.ops@example.test', $user->email);

        $this->actingAs($user)
            ->put(route('ops.account.password'), [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertRedirect(route('ops.account.show'));

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_operator_can_upload_and_remove_avatar(): void
    {
        Storage::fake('public');
        $user = $this->user(OpsRole::Operator);
        $file = UploadedFile::fake()->image('avatar.jpg', 120, 120);

        $this->actingAs($user)
            ->post(route('ops.account.avatar'), ['avatar' => $file])
            ->assertRedirect(route('ops.account.show'));

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);

        $this->actingAs($user)
            ->delete(route('ops.account.avatar.destroy'))
            ->assertRedirect(route('ops.account.show'));

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_locale_and_appearance_persist_and_switch_copy(): void
    {
        $user = $this->user(OpsRole::Operator);

        $this->actingAs($user)
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('data-theme="dark"', false)
            ->assertSee('Fleet', false);

        $this->actingAs($user)
            ->put(route('ops.account.preferences.update'), [
                'locale' => 'tr',
                'appearance' => Appearance::Light->value,
            ])
            ->assertRedirect(route('ops.account.preferences'));

        $user->refresh();
        $this->assertSame('tr', $user->locale);
        $this->assertSame(Appearance::Light, $user->appearance);

        $this->actingAs($user)
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('lang="tr"', false)
            ->assertSee('data-theme="light"', false)
            ->assertSee('Filo', false)
            ->assertDontSee('>Fleet</h1>', false);
    }

    public function test_user_menu_does_not_expose_permanent_sign_out_block(): void
    {
        $user = $this->user(OpsRole::Viewer);

        $html = $this->actingAs($user)
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-user-menu', $html);
        $this->assertStringContainsString(route('ops.account.show'), $html);
        $this->assertStringContainsString(route('ops.account.preferences'), $html);
        $this->assertStringNotContainsString('ops-logout', $html);
        $this->assertStringContainsString(__('ops.user_menu.sign_out'), $html);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
