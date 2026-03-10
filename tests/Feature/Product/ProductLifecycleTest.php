<?php

namespace Tests\Feature\Product;

use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_with_purchase_history_cannot_be_deleted(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $gateway = Gateway::query()->create([
            'code' => 'gateway_2',
            'name' => 'Gateway 2',
            'priority' => 1,
            'is_active' => true,
        ]);
        $client = Client::query()->create([
            'name' => 'Client',
            'email' => 'client@test.local',
        ]);
        $product = Product::query()->create([
            'name' => 'Notebook',
            'amount' => 1000,
            'is_active' => true,
        ]);
        $transaction = Transaction::query()->create([
            'client_id' => $client->id,
            'gateway_id' => $gateway->id,
            'external_id' => 'external-123',
            'correlation_id' => '11111111-1111-1111-1111-111111111114',
            'status' => TransactionStatus::PAID->value,
            'amount' => 1000,
            'card_last_numbers' => '6063',
        ]);

        $transaction->products()->attach($product->id, [
            'quantity' => 1,
            'unit_amount' => 1000,
            'line_total' => 1000,
        ]);

        $this->deleteJson('/api/products/'.$product->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'product_has_transaction_history')
            ->assertJsonPath('error.message', 'Products with purchase history cannot be deleted. Disable the product instead.');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_product_without_purchase_history_can_be_deleted(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-delete@test.local',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $product = Product::query()->create([
            'name' => 'Mouse',
            'amount' => 500,
            'is_active' => true,
        ]);

        $this->deleteJson('/api/products/'.$product->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
