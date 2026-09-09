<?php

namespace Tests\Feature\Security;

use App\Enums\OpsRole;
use App\Models\User;
use App\Support\ProductionDebugGuard;
use App\Support\SecretRedactor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_secret_redactor_strips_known_secrets(): void
    {
        $text = 'token=super-secret-value and again super-secret-value';

        $this->assertTrue(SecretRedactor::containsSecret($text, ['super-secret-value']));
        $this->assertSame(
            'token=[redacted] and again [redacted]',
            SecretRedactor::redact($text, ['super-secret-value']),
        );
    }

    public function test_production_debug_guard_rejects_debug_true(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['app.debug' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must be false in production.');

        ProductionDebugGuard::assert();
    }

    public function test_production_debug_guard_allows_debug_false(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['app.debug' => false]);

        ProductionDebugGuard::assert();

        $this->assertTrue(true);
    }

    public function test_empty_ip_allowlist_does_not_block_ops(): void
    {
        config(['ops.access.ip_allowlist' => '']);

        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $this->actingAs($operator)->get(route('ops.fleet'))->assertOk();
    }

    public function test_ip_allowlist_blocks_other_clients(): void
    {
        config(['ops.access.ip_allowlist' => '203.0.113.10']);

        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $this->actingAs($operator)
            ->get(route('ops.fleet'))
            ->assertForbidden();
    }

    public function test_themes_index_has_no_zip_file_input(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $this->actingAs($operator)
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee('git-only', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('ZipArchive', false)
            ->assertDontSee('name="zip"', false);
    }
}
