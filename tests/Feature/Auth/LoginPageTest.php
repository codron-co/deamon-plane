<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class LoginPageTest extends TestCase
{
    public function test_login_page_returns_ok(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_guests_are_redirected_from_ops_home(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
