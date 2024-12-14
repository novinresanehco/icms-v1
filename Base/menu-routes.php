<?php

use App\Http\Controllers\Admin\MenuController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'api/admin', 'middleware' => ['auth:sanctum', 'admin']], function () {
    Route::get('menus', [MenuController::class, 'index']);
    Route::post('menus', [MenuController::class, 'store']);
    Route::get('menus/{id}', [MenuController::class, 'show']);
    Route::put('menus/{id}', [MenuController::class, 'update']);
    Route::delete('menus/{id}', [MenuController::class, 'destroy']);
    
    Route::post('menus/{menuId}/items', [MenuController::class, 'addItem']);
    Route::put('menu-items/{itemId}', [MenuController::class, 'updateItem']);
    Route::delete('menu-items/{itemId}', [MenuController::class, 'deleteItem']);
    Route::post('menu-items/reorder', [MenuController::class, 'reorderItems']);
});

// Public API routes
Route::group(['prefix' => 'api'], function () {
    Route::get('menus/{location}', [MenuController::class, 'getByLocation']);
});
