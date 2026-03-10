<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\PaymentIdempotencyService;
use Tests\TestCase;

class PaymentIdempotencyServiceTest extends TestCase
{
    public function test_stable_hash_ignores_cvv_changes(): void
    {
        $service = new PaymentIdempotencyService;
        $groupedItems = [1 => 2, 5 => 1];

        $firstHash = $service->buildStableHash([
            'name' => 'Tester',
            'email' => 'tester@email.com',
            'card_number' => '5569000000006063',
            'cvv' => '010',
        ], $groupedItems);

        $secondHash = $service->buildStableHash([
            'name' => 'Tester',
            'email' => 'tester@email.com',
            'card_number' => '5569000000006063',
            'cvv' => '999',
        ], $groupedItems);

        $this->assertSame($firstHash, $secondHash);
    }
}
