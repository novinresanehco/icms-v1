<?php

use App\Core\Api\CriticalApiController;

Route::group(['prefix' => 'api', 'middleware' => ['auth', 'throttle']], function () {
    // Content Management
    Route::post('/content', [CriticalApiController::class, 'handle']);
    Route::get('/content/{id}', [CriticalApiController::class, 'handle']);
    Route::put('/content/{id}', [CriticalApiController::class, 'handle']);
    Route::delete('/content/{id}', [CriticalApiController::class, 'handle']);

    // User Management
    Route::post('/users', [CriticalApiController::class, 'handle']);
    Route::get('/users/{id}', [CriticalApiController::class, 'handle']);
    Route::put('/users/{id}', [CriticalApiController::class, 'handle']);
    Route::delete('/users/{id}', [CriticalApiController::class, 'handle']);

    // Media Management
    Route::post('/media', [CriticalApiController::class, 'handle']);
    Route::get('/media/{id}', [CriticalApiController::class, 'handle']);
    Route::delete('/media/{id}', [CriticalApiController::class, 'handle']);
});
