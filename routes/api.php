<?php

use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\Connectors\ExamsController;
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

// Connectors (docs/CONNECTORS.md): other systems call SIS with the key SIS issued for them.
// Disconnecting a connector or switching a tool off closes its routes at once.
Route::prefix('connectors/v1/exams')->middleware('throttle:120,1')->group(function () {
    Route::get('/manifest', [ExamsController::class, 'manifest'])->middleware('connector:exams');
    Route::get('/offerings', [ExamsController::class, 'offerings'])->middleware('connector:exams,rosters');
    Route::get('/offerings/{offering}/students', [ExamsController::class, 'students'])->middleware('connector:exams,rosters')->whereNumber('offering');
    Route::post('/offerings/{offering}/marks', [ExamsController::class, 'marks'])->middleware('connector:exams,marks_inbox')->whereNumber('offering');
    Route::get('/batches/{batch}', [ExamsController::class, 'batch'])->middleware('connector:exams,marks_inbox')->whereNumber('batch');
});
