<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Notebook Pro', 'amount' => 549900, 'is_active' => true],
            ['name' => 'Monitor 27', 'amount' => 129900, 'is_active' => true],
            ['name' => 'Mechanical Keyboard', 'amount' => 39900, 'is_active' => true],
        ];

        foreach ($products as $data) {
            Product::query()->updateOrCreate(['name' => $data['name']], $data);
        }
    }
}
