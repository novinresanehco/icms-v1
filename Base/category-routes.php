<?php

use App\Core\Http\Controllers\CategoryController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('categories', CategoryController::class);
    Route::post('categories/reorder', [CategoryController::class, 'reorder'])
        ->name('categories.reorder');
});
