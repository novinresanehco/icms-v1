<?php

use App\Core\Tag\Http\Controllers\TagController;

Route::prefix('api/v1')->middleware(['auth:sanctum'])->group(function () {
    Route::get('tags/popular', [TagController::class, 'popular'])
         ->name('api.tags.popular');
         
    Route::post('tags/merge', [TagController::class, 'merge'])
         ->name('api.tags.merge')
         ->middleware('can:merge,App\Core\Tag\Models\Tag');

    Route::get('tags/{id}/contents', [TagController::class, 'contents'])
         ->name('api.tags.contents');

    Route::apiResource('tags', TagController::class, [
        'names' => [
            'index' => 'api.tags.index',
            'store' => 'api.tags.store',
            'show' => 'api.tags.show',
            'update' => 'api.tags.update',
            'destroy' => 'api.tags.destroy',
        ]
    ])->middleware(['cache.headers:public;max_age=3600;etag']);
});
