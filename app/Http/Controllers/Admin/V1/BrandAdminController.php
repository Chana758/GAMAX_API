<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BrandAdminController extends Controller
{
    /** GET /admin/brands?search=as */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);

        $brands = Brand::query()
            ->withCount('products')
            ->when($request->string('search')->trim()->value(), function ($q, $search) {
                $like = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
                $q->whereRaw('LOWER(name) LIKE ?', [$like]);
            })
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Brands fetched successfully.',
            'data'    => BrandResource::collection($brands),
        ]);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $this->authorize('create', Brand::class);

        $brand = Brand::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Brand created successfully.',
            'data'    => new BrandResource($brand->loadCount('products')),
        ], Response::HTTP_CREATED);
    }

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json([
            'success' => true,
            'data'    => new BrandResource($brand->loadCount('products')),
        ]);
    }

    /** Renaming a brand renames it on every product at once. */
    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $oldLogo = $brand->logo;
        $data    = $request->validated();

        $brand->update($data);

        if (array_key_exists('logo', $data) && $data['logo'] !== $oldLogo) {
            $this->deleteLogo($oldLogo);
        }

        return response()->json([
            'success' => true,
            'message' => 'Brand updated successfully.',
            'data'    => new BrandResource($brand->fresh()->loadCount('products')),
        ]);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('delete', $brand);

        if ($brand->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a brand that still has products. Move the products to another brand first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $logo = $brand->logo;
        $brand->delete();
        $this->deleteLogo($logo);

        return response()->json([
            'success' => true,
            'message' => 'Brand deleted successfully.',
        ]);
    }

    private function deleteLogo(?string $path): void
    {
        if ($path && Str::startsWith($path, '/storage/brands/')) {
            Storage::disk('public')->delete(Str::after($path, '/storage/'));
        }
    }
}
