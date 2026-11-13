<?php

use App\Http\Controllers\Api\PaymentApiController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\LogApiRequest;
use Illuminate\Support\Facades\Route;

// The company-facing API (what the shop's gateway driver calls). Stateless,
// authenticated by API key. Served under /api.
Route::prefix('v1')->middleware([LogApiRequest::class, AuthenticateApiKey::class, 'throttle:120,1'])->group(function () {
    Route::post('payments', [PaymentApiController::class, 'store']);
    Route::get('payments/{id}', [PaymentApiController::class, 'show']);
});
