<?php

namespace Tests\Feature\Gateway;

use App\Enums\UserRole;
use App\Models\Gateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GatewayAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_access_admin_gateway_route(): void
    {
        $user = User::query()->create([
            'name' => 'Common User',
            'email' => 'user@test.local',
            'password' => 'password123',
            'role' => UserRole::USER,
        ]);

        $gateway = Gateway::query()->create([
            'code' => 'gateway_1',
            'name' => 'Gateway 1',
            'priority' => 1,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/gateways/'.$gateway->id.'/priority', [
            'priority' => 1,
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }
}
