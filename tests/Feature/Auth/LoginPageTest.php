<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class LoginPageTest extends TestCase
{
    public function test_login_page_returns_ok(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_login_page_uses_guest_card_not_ops_dashboard(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('guest-card', false)
            ->assertSee('guest-shell', false)
            ->assertSee('ops-guest', false)
            ->assertSee('family=Inter', false)
            ->assertSee(__('auth.heading'), false)
            ->assertSee(__('auth.lede'), false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false)
            ->assertDontSee('ops-shell', false)
            ->assertDontSee('kpi-card', false)
            ->assertDontSee('ops-nav', false);
    }

    public function test_guests_are_redirected_from_ops_home(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
