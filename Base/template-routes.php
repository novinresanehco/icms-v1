<?php

use App\Core\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::apiResource('templates', TemplateController::class);
    Route::post('templates/{template}/compile', [TemplateController::class, 'compile']);
});
