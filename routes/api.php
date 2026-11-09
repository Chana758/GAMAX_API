<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/v1/api_v1.php';
    require __DIR__.'/v1/admin_v1.php';
});