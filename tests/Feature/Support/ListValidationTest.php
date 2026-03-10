<?php

namespace Tests\Feature\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_listing_rejects_invalid_status_filter(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/transactions?status=bogus')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_product_listing_rejects_per_page_above_limit(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-limit@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/products?per_page=101')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }
}
