<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------
| Public Routes (no token)
|-------------------------------
*/
Route::get('categories',                 [CategoryController::class, 'index']);
Route::get('categories/{category:slug}', [CategoryController::class, 'show']);
Route::get('products',                   [ProductController::class, 'index']);
Route::get('brands',                     [ProductController::class, 'brands']);
Route::get('products/{product:slug}',    [ProductController::class, 'show']);
Route::get('settings',                   [SettingsController::class, 'show']); // delivery fees, payment methods

/*
|-----------------------------------------
| Auth routes (Customer) — /api/v1/auth/*
|-----------------------------------------
*/
Route::prefix('auth')->group(function () {

    // Guest-only. throttle: max 10 attempts per minute per IP (slows down password guessing)
    Route::middleware(['guest.only', 'throttle:10,1'])->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    // Requires Bearer token
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
|-----------------------------------------------------------
| Customer account — login required (Bearer token)
|-----------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('addresses', AddressController::class)->except('show');

    Route::get('orders',                        [OrderController::class, 'index']);
    Route::post('orders/quote',                 [OrderController::class, 'quote'])->middleware('throttle:60,1');
    Route::post('orders',                       [OrderController::class, 'store'])->middleware('throttle:10,1');
    Route::get('orders/{order:number}',         [OrderController::class, 'show']);
    Route::post('orders/{order:number}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:10,1');
    Route::post('orders/{order:number}/payment', [OrderController::class, 'reportPayment'])->middleware('throttle:10,1'); // "I've paid" (KHQR)
});