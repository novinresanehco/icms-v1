<?php

use App\Core\Media\Http\Controllers\{
    MediaController,
    MediaVariantController,
    MediaTypeController,
    MediaBatchController
};

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    // Media Resource Routes
    Route::apiResource('media', MediaController::class);
    Route::post('media/bulk', [MediaController::class, 'bulkAction']);
    
    // Media Variants
    Route::post('media/{media}/variants/{variant}/regenerate', [MediaVariantController::class, 'regenerate']);
    Route::delete('media/{media}/variants/{variant}', [MediaVariantController::class, 'delete']);
    
    // Media Types
    Route::get('media/types/{type}', [MediaTypeController::class, 'index']);
    Route::get('media/types/{type}/stats', [MediaTypeController::class, 'stats']);
    
    // Batch Operations
    Route::post('media/batch/upload', [MediaBatchController::class, 'upload']);
    Route::post('media/batch/download', [MediaBatchController::class, 'download']);
});
