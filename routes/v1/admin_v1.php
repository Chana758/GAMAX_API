<?php

use App\Http\Controllers\Admin\V1\BrandAdminController;
use App\Http\Controllers\Admin\V1\CategoryAdminController;
use App\Http\Controllers\Admin\V1\ImageUploadController;
use App\Http\Controllers\Admin\V1\OrderAdminController;
use App\Http\Controllers\Admin\V1\ProductAdminController;
use App\Http\Controllers\Admin\V1\ProductVariantAdminController;
use Illuminate\Support\Facades\Route;

// /api/v1/admin/... (role check here, permissions in Policies)
Route::prefix('admin')
->middleware(['auth:sanctum', 'role:admin|staff'])
    ->group(function () {

        Route::prefix('uploads')
            ->middleware([
                'permission:product.create|product.update|banner.create|banner.update|category.create|category.update|brand.create|brand.update',
                'throttle:30,1',
            ])
            ->group(function () {
                Route::post('image',   [ImageUploadController::class, 'store']);
                Route::delete('image', [ImageUploadController::class, 'destroy']);
            });

        Route::apiResource('categories', CategoryAdminController::class);
        Route::apiResource('brands', BrandAdminController::class);
        Route::apiResource('products', ProductAdminController::class);
        Route::apiResource('products.variants', ProductVariantAdminController::class)->shallow();

        // Orders: no create/delete for staff (orders come from customers and are kept for records)
        Route::get('orders',                  [OrderAdminController::class, 'index']);
        Route::get('orders/summary',          [OrderAdminController::class, 'summary']); // before {order}
        Route::get('orders/stats',            [OrderAdminController::class, 'stats']);   // before {order}
        Route::get('orders/{order:number}',   [OrderAdminController::class, 'show']);
        Route::patch('orders/{order:number}', [OrderAdminController::class, 'update']);

        // Money: every amount received or given back is one row (permissions payment.record / payment.refund)
        Route::post('orders/{order:number}/payments', [OrderAdminController::class, 'storePayment'])
            ->name('admin.orders.payments');
        Route::post('orders/{order:number}/refunds', [OrderAdminController::class, 'storeRefund'])
            ->name('admin.orders.refunds');
    });