<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ShopRules;
use Illuminate\Http\JsonResponse;

/** GET /api/v1/settings — public shop settings (delivery fees, payment methods). */
class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => ShopRules::forStorefront(),
        ]);
    }
}
