<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_registration_screen()
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_nobody_can_register()
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'stranger@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }
}
