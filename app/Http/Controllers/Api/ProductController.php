<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Support\ApiResponse;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::query()->latest()->paginate(request('per_page', 15));

        return ApiResponse::success([
            'products' => ApiResponse::paginated($products, ProductResource::class),
        ]);
    }

    public function store(StoreProductRequest $request)
    {
        $product = Product::query()->create($request->validated());

        return ApiResponse::success(['product' => ProductResource::make($product)], 201);
    }

    public function show(Product $product)
    {
        return ApiResponse::success(['product' => ProductResource::make($product)]);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $product->update($request->validated());

        return ApiResponse::success(['product' => ProductResource::make($product->fresh())]);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->noContent();
    }
}
