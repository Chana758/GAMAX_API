<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProductAdminController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    /**
     * Get a paginated list of products for admin.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $products = $this->productService->paginateForAdmin(
            $request->only(['category_id', 'search', 'is_active', 'brand', 'on_sale']),
            min((int) $request->integer('per_page', 15), 100)
        );

        return response()->json([
            'success' => true,
            'message' => 'Admin products fetched successfully.',
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Get details of a specific product, including its category and variants.
     */
    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($this->productService->loadForAdmin($product)),
        ]);
    }

    /**
     * Create a new product.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $this->productService->create(
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => new ProductResource($product),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an existing product.
     */
    public function update(
        UpdateProductRequest $request,
        Product $product
    ): JsonResponse {
        $this->authorize('update', $product);

        $product = $this->productService->update(
            $product,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Delete a product if it has no associated variants.
     */
    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        if ($product->variants()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a product that still has variants.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->productService->delete($product);

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }
}