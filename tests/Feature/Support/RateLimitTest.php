<?php

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'missing@test.local',
                'password' => 'password123',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login', [
            'email' => 'missing@test.local',
            'password' => 'password123',
        ])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_purchase_is_rate_limited_after_twenty_attempts(): void
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->postJson('/api/purchases', [])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_error');
        }

        $this->postJson('/api/purchases', [])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }
}
