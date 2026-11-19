<?php

use App\Http\Controllers\Api\AppController;
use Illuminate\Support\Facades\Route;

// The apps' API, see AppController.
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AppController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/two-factor', [AppController::class, 'twoFactor'])->middleware('throttle:10,1');
    Route::get('/info', fn () => response()->json(['system' => 'sis', 'university' => config('sis.university_name'), 'offline' => (bool) config('offline.edition')]));

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/auth/logout', [AppController::class, 'logout']);
        Route::get('/me', [AppController::class, 'me']);
        Route::get('/student', [AppController::class, 'student']);
        Route::get('/notifications', [AppController::class, 'notifications']);
        Route::post('/notifications/read', [AppController::class, 'readNotifications']);
        Route::post('/web-link', [AppController::class, 'webLink'])->middleware('throttle:30,1');
    });
});
