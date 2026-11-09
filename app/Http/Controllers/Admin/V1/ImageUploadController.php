<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ImageUploadController extends Controller
{
    // Tables/columns that store image paths
    private const IMAGE_REFERENCES = [
        ['products', 'image'],
        ['product_images', 'path'],
        ['banners', 'image'],
        ['categories', 'icon'],
        ['brands', 'logo'],
    ];

    /** POST /admin/uploads/image */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image'  => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], // 2MB
            'folder' => ['sometimes', 'in:products,banners,categories,brands'],
        ]);

        $path       = $request->file('image')->store($request->input('folder', 'products'), 'public');
        $publicPath = '/storage/' . $path;

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully.',
            'data'    => [
                'path' => $publicPath,
                'url'  => url($publicPath),
            ],
        ], Response::HTTP_CREATED);
    }

    /** DELETE /admin/uploads/image (blocked if still in use) */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
        ]);

        $path     = $request->input('path');
        $relative = $this->relative($path);

        if ($relative === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid path.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->isInUse($path)) {
            return response()->json([
                'success' => false, 
                'message' => 'This image is still in use.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! Storage::disk('public')->exists($relative)) {
            return response()->json([
                'success' => false,
                'message' => 'Image not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        Storage::disk('public')->delete($relative);

        return response()->json([
            'success' => true,
            'message' => 'Image deleted successfully.',
        ]);
    }

    // True if any table references this path
    private function isInUse(string $path): bool
    {
        foreach (self::IMAGE_REFERENCES as [$table, $column]) {
            if (Schema::hasTable($table) && DB::table($table)->where($column, $path)->exists()) {
                return true;
            }
        }

        return false;
    }

    // "/storage/products/a.png" => "products/a.png" (null if unsafe)
    private function relative(string $path): ?string
    {
        return preg_match('#^/storage/(products|banners|categories|brands)/[\w-]+\.(jpe?g|png|webp)$#i', $path)
            ? substr($path, strlen('/storage/'))
            : null;
    }
}