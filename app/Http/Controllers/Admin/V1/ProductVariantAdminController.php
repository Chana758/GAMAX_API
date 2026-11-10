<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVariantRequest;
use App\Http\Requests\UpdateVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ProductVariantAdminController extends Controller
{
    public function __construct(private readonly ProductVariantService $variantService)
    {
    }

    /**  Get all variants belonging to a specific product.*/
    public function index(Product $product): JsonResponse
    {
        // Uses ProductVariantPolicy (variant.view)
        $this->authorize('viewAny', ProductVariant::class);

        return response()->json([
            'success' => true,
            'message' => 'Variants fetched successfully.',
            'data'    => ProductVariantResource::collection(
                $this->variantService->listForProduct($product)
            ),
        ]);
    }

    /** Create a new variant for a specific product.*/
    public function store(StoreVariantRequest $request, Product $product): JsonResponse
    {
        // Uses ProductVariantPolicy (variant.create)
        $this->authorize('create', ProductVariant::class);

        try {
            $variant = $this->variantService->create($product, $request->validated());
        } catch (UniqueConstraintViolationException $e) {
            return $this->duplicateResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Variant created successfully.',
            'data'    => new ProductVariantResource($variant),
        ], Response::HTTP_CREATED);
    }

    /** Get details of a specific product variant. */
    public function show(ProductVariant $variant): JsonResponse
    {
        $this->authorize('view', $variant);

        return response()->json([
            'success' => true,
            'data'    => new ProductVariantResource($variant),
        ]);
    }

    /**Update an existing product variant. */
    public function update(UpdateVariantRequest $request, ProductVariant $variant): JsonResponse
    {
        $this->authorize('update', $variant);

        try {
            $variant = $this->variantService->update($variant, $request->validated());
        } catch (UniqueConstraintViolationException $e) {
            return $this->duplicateResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Variant updated successfully.',
            'data'    => new ProductVariantResource($variant),
        ]);
    }

    /** Delete a product variant if it is not referenced by order history. */
    public function destroy(ProductVariant $variant): JsonResponse
    {
        $this->authorize('delete', $variant);

        // Ordered variants are kept for order history (the database refuses too)
        if (OrderItem::where('product_variant_id', $variant->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This variant has been ordered, so it is kept for order history. Untick "Visible in the store" to hide it instead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->variantService->delete($variant);
        } catch (QueryException $e) {
            // SQLSTATE 23503 = foreign key violation (PostgreSQL):
            // the variant is referenced by order_items, so it must be kept for order history.
            if (($e->errorInfo[0] ?? $e->getCode()) === '23503') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete a variant that has been ordered. Hide it instead (is_active = false).',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Variant deleted successfully.',
        ]);
    }

    /** The generated title (or SKU) is already used by another visible variant of this product. */
    private function duplicateResponse(UniqueConstraintViolationException $e): JsonResponse
    {
        $field = str_contains($e->getMessage(), 'sku') ? 'sku' : 'title';

        return response()->json([
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors'  => [$field => [$field === 'sku'
                ? 'This SKU is already in use.'
                : 'Another visible variant of this product already has this title. Change an option or type a title.']],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
