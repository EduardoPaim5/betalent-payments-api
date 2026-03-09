<?php

namespace Tests\Feature\User;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_cannot_create_admin_user(): void
    {
        $manager = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@test.local',
            'password' => 'password123',
            'role' => UserRole::MANAGER,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/users', [
            'name' => 'Blocked Admin',
            'email' => 'blocked-admin@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN->value,
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }

    public function test_manager_can_create_regular_user(): void
    {
        $manager = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@test.local',
            'password' => 'password123',
            'role' => UserRole::MANAGER,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/users', [
            'name' => 'Regular User',
            'email' => 'regular@test.local',
            'password' => 'password123',
            'role' => UserRole::USER->value,
        ]);

        $response->assertCreated()->assertJsonPath('data.user.role', UserRole::USER->value);
    }

    public function test_manager_cannot_delete_admin_user(): void
    {
        $manager = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@test.local',
            'password' => 'password123',
            'role' => UserRole::MANAGER,
        ]);

        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->deleteJson('/api/users/'.$admin->id);

        $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }

    public function test_manager_list_does_not_expose_admin_users(): void
    {
        $manager = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@test.local',
            'password' => 'password123',
            'role' => UserRole::MANAGER,
        ]);

        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        User::query()->create([
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $emails = collect($response->json('data.users.data'))->pluck('email')->all();

        $this->assertContains('manager@test.local', $emails);
        $this->assertContains('finance@test.local', $emails);
        $this->assertNotContains('admin@test.local', $emails);
    }

    public function test_user_cannot_delete_own_account(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson('/api/users/'.$admin->id);

        $response->assertStatus(422)->assertJsonPath('error.code', 'invalid_operation');
    }
}
