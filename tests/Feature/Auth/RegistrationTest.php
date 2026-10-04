<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_registration_screen_is_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_public_registration_cannot_create_session(): void
    {
        $this->post('/register', ['email' => 'test@example.com', 'password' => 'password'])->assertNotFound();
        $this->assertGuest();
    }
}
