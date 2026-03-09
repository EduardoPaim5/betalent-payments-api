<?php

namespace Database\Seeders;

use App\Models\Gateway;
use Illuminate\Database\Seeder;

class GatewaySeeder extends Seeder
{
    public function run(): void
    {
        $gateways = [
            ['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true],
            ['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true],
        ];

        foreach ($gateways as $data) {
            Gateway::query()->updateOrCreate(['code' => $data['code']], $data);
        }
    }
}
