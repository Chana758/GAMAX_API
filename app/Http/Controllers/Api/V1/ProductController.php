<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Brand;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $productService)
    {
    }

    /**
     * Get a paginated list of public products with optional filters,
     * search, price range, and sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $products = $this->productService->paginatePublic(
            $request->only(['category', 'search', 'min_price', 'max_price', 'sort', 'brand', 'on_sale', 'featured']),
            min((int) $request->integer('per_page', 12), 50)
        );

        return response()->json([
            'success' => true,
            'message' => 'Products fetched successfully.',
            'data'    => ProductResource::collection($products->items()),
            'meta'    => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }

    /**  Get details of a public product using its slug.*/
    public function show(Product $product): JsonResponse
    {
        $product = $this->productService->findPublicBySlug($product);

        return response()->json([
            'success' => true,
            'data'    => new ProductResource($product),
        ]);
    }

    /** Brand names that have at least one visible product (used by the filter sidebar). */
    public function brands(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => Brand::query()
                ->whereHas('products', fn ($q) => $q->where('is_active', true))
                ->ordered()
                ->pluck('name'),
        ]);
    }
}