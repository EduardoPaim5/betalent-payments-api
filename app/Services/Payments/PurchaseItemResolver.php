<?php

namespace App\Services\Payments;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class PurchaseItemResolver
{
    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $items
     * @return array<int, int>
     */
    public function group(array $items): array
    {
        $groupedItems = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $groupedItems[$productId] = ($groupedItems[$productId] ?? 0) + (int) $item['quantity'];
        }

        ksort($groupedItems);

        return $groupedItems;
    }

    /**
     * @param  array<int, int>  $groupedItems
     * @return array<int, array{product:Product, quantity:int, unit_amount:int, line_total:int}>
     */
    public function resolveNormalizedItems(array $groupedItems): array
    {
        $products = Product::query()
            ->whereIn('id', array_keys($groupedItems))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($groupedItems)) {
            throw ValidationException::withMessages([
                'items' => ['One or more selected products are unavailable.'],
            ]);
        }

        $normalizedItems = [];

        foreach ($groupedItems as $productId => $quantity) {
            /** @var Product $product */
            $product = $products->get($productId);
            $lineTotal = $product->amount * $quantity;

            $normalizedItems[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_amount' => $product->amount,
                'line_total' => $lineTotal,
            ];
        }

        return $normalizedItems;
    }
}
