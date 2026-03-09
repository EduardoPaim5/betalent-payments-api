<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_token(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@test.local',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonStructure([
            'data' => ['token', 'user'],
        ]);
    }
}
