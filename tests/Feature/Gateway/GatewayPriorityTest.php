<?php

namespace Tests\Feature\Gateway;

use App\Enums\UserRole;
use App\Models\Gateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GatewayPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reorder_gateways(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        $gateway1 = Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        $gateway2 = Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/gateways/'.$gateway2->id.'/priority', [
            'priority' => 1,
        ]);

        $response->assertOk()->assertJsonPath('data.gateway.priority', 1);
        $this->assertSame(2, $gateway1->fresh()->priority);
        $this->assertSame(1, $gateway2->fresh()->priority);
    }
}
