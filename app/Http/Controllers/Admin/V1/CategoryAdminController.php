<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CategoryAdminController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService
    ) {}

    /**
     * Get a paginated list of categories for admin.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $categories = $this->categoryService->paginateForAdmin(
            $request->only(['search', 'per_page'])
        );

        return response()->json([
            'success' => true,
            'message' => 'Admin categories fetched successfully.',
            'data' => CategoryResource::collection($categories),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'total' => $categories->total(),
            ],
        ]);
    }

    /**
     * Get details of a specific category.
     */
    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return response()->json([
            'success' => true,
            'message' => 'Category fetched successfully.',
            'data' => new CategoryResource($category),
        ]);
    }

    /**
     * Create a new category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = $this->categoryService->create(
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully.',
            'data' => new CategoryResource($category),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an existing category.
     */
    public function update(
        UpdateCategoryRequest $request,
        Category $category
    ): JsonResponse {
        $this->authorize('update', $category);

        $category = $this->categoryService->update(
            $category,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully.',
            'data' => new CategoryResource($category),
        ]);
    }

    /**
     * Delete a category if it has no associated products.
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        if ($category->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a category that still has products.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->categoryService->delete($category);

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully.',
        ]);
    }
}