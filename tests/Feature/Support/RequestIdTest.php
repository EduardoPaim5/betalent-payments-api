<?php

namespace Tests\Feature\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_id_is_generated_when_missing(): void
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

        $response->assertOk();
        $this->assertNotNull($response->headers->get('X-Request-ID'));
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('request_id'));
    }
}
