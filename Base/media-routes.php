<?php

use App\Core\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::apiResource('media', MediaController::class);
    Route::patch('media/{id}/metadata', [MediaController::class, 'updateMetadata'])->name('media.metadata.update');
    Route::get('media/type/{type}', [MediaController::class, 'index'])->name('media.type.index');
});
