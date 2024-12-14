<?php

use App\Core\Tag\Http\Controllers\{
    TagController,
    TagHierarchyController,
    TagAttachmentController
};

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    // Tag Management
    Route::apiResource('tags', TagController::class);
    Route::post('tags/bulk', [TagController::class, 'bulkAction']);
    
    // Tag Hierarchy
    Route::get('tags/hierarchy/{parent?}', [TagHierarchyController::class, 'index']);
    Route::post('tags/reorder', [TagHierarchyController::class, 'reorder']);
    
    // Tag Attachments
    Route::post('tags/attach/{type}/{id}', [TagAttachmentController::class, 'attach']);
    Route::post('tags/detach/{type}/{id}', [TagAttachmentController::class, 'detach']);
});
