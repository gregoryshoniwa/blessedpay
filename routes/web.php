<?php

use App\Http\Controllers\Console\ActivityController;
use App\Http\Controllers\Console\AuthController;
use App\Http\Controllers\Console\CompanyController;
use App\Http\Controllers\Console\PaymentController;
use App\Http\Controllers\Console\TeamController;
use App\Http\Controllers\Console\TryController;
use App\Http\Controllers\PayPageController;
use App\Http\Controllers\StewardPayCallbackController;
use App\Http\Middleware\ConsoleAccess;
use Illuminate\Support\Facades\Route;

// Public landing page — server-rendered so it loads fast and search engines can read it.
Route::view('/', 'welcome')->name('home');

// The checkout: what a shopper sees, full-page or in the pop-up. No cookies, no
// CSRF token: the long random payment id in the address is the credential.
Route::get('/p/{id}', [PayPageController::class, 'show'])->name('pay.show');
Route::get('/p/{id}/card-return', [PayPageController::class, 'cardReturn'])->name('pay.card-return');
Route::get('/p/{id}/status', [PayPageController::class, 'status'])->middleware('throttle:90,1');
Route::post('/p/{id}/pay', [PayPageController::class, 'pay'])->middleware('throttle:20,1');
Route::post('/p/{id}/cancel', [PayPageController::class, 'cancel'])->middleware('throttle:20,1');
Route::post('/p/{id}/simulate', [PayPageController::class, 'simulate'])->middleware('throttle:30,1');

// The "Try it" page's stand-in for a company's server: keeps what it is sent.
Route::post('/try/hook/{token}', [TryController::class, 'hook'])->middleware('throttle:60,1');

// The bank tells us an EcoCash or bank-account payment went through.
Route::post('/hooks/stewardpay', StewardPayCallbackController::class)->name('hooks.stewardpay')->middleware('throttle:240,1');

// The console's own endpoints: session cookie + CSRF, JSON in and out.
Route::prefix('console-api')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:6,60'); // six sign-ups an hour per address
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('invitations/{token}', [TeamController::class, 'showInvite'])->middleware('throttle:30,1');
    Route::post('invitations/{token}', [TeamController::class, 'acceptInvite'])->middleware('throttle:10,1');

    Route::middleware(ConsoleAccess::class)->group(function () {
        Route::get('me', [AuthController::class, 'me']);

        Route::get('overview', [PaymentController::class, 'overview']);
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/export', [PaymentController::class, 'export']);
        Route::get('payments/{id}', [PaymentController::class, 'show']);
        Route::post('payments/{id}/refresh', [PaymentController::class, 'refresh']);
        Route::post('payments/{id}/callback', [PaymentController::class, 'resendCallback']);

        Route::get('companies', [CompanyController::class, 'index']);
        Route::post('companies', [CompanyController::class, 'store']);
        Route::get('companies/{id}', [CompanyController::class, 'show']);
        Route::put('companies/{id}', [CompanyController::class, 'update']);
        Route::get('companies/{id}/secret', [CompanyController::class, 'secret']);
        Route::post('companies/{id}/secret', [CompanyController::class, 'rotateSecret']);
        Route::get('companies/{id}/keys', [CompanyController::class, 'keys']);
        Route::post('companies/{id}/keys', [CompanyController::class, 'createKey']);
        Route::delete('companies/{id}/keys/{keyId}', [CompanyController::class, 'revokeKey']);

        Route::get('team', [TeamController::class, 'index']);
        Route::put('team/{id}', [TeamController::class, 'update']);
        Route::delete('team/{id}', [TeamController::class, 'destroy']);
        Route::post('invitations', [TeamController::class, 'invite']);
        Route::post('invitations/{id}/resend', [TeamController::class, 'resendInvite'])->whereNumber('id');
        Route::delete('invitations/{id}', [TeamController::class, 'revokeInvite'])->whereNumber('id');

        Route::get('activity', [ActivityController::class, 'index']);
        Route::get('try/hook/{token}', [TryController::class, 'received']);
    });
});

// The console itself is a Vue app; every /console URL serves the same shell.
Route::view('/console/{any?}', 'console')->where('any', '.*')->name('console');
