<?php
// routes/api.php

use App\Core\Notification\Http\Controllers\{
    NotificationController,
    NotificationPreferenceController,
    NotificationTemplateController
};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    
    // Notification Routes
    Route::prefix('notifications')->group(function () {
        // List notifications
        Route::get('/', [NotificationController::class, 'index']);
        
        // Get unread count
        Route::get('/unread/count', [NotificationController::class, 'unreadCount']);
        
        // Get single notification
        Route::get('/{notification}', [NotificationController::class, 'show']);
        
        // Mark notifications as read
        Route::post('/mark-read', [NotificationController::class, 'markAsRead']);
        
        // Mark single notification as read
        Route::post('/{notification}/mark-read', [NotificationController::class, 'markOneAsRead']);
        
        // Delete notifications
        Route::delete('/', [NotificationController::class, 'destroy']);
        
        // Delete single notification
        Route::delete('/{notification}', [NotificationController::class, 'destroyOne']);
    });

    // Notification Preferences Routes
    Route::prefix('notification-preferences')->group(function () {
        // Get preferences
        Route::get('/', [NotificationPreferenceController::class, 'index']);
        
        // Update preferences
        Route::put('/', [NotificationPreferenceController::class, 'update']);
        
        // Update channel preference
        Route::put('/channels/{channel}', [NotificationPreferenceController::class, 'updateChannel']);
        
        // Get available channels
        Route::get('/channels', [NotificationPreferenceController::class, 'channels']);
    });
});

// Admin routes with additional middleware
Route::middleware(['auth:sanctum', 'verified', 'admin'])->group(function () {
    
    // Notification Template Routes
    Route::prefix('notification-templates')->group(function () {
        // List templates
        Route::get('/', [NotificationTemplateController::class, 'index']);
        
        // Create template
        Route::post('/', [NotificationTemplateController::class, 'store']);
        
        // Get single template
        Route::get('/{template}', [NotificationTemplateController::class, 'show']);
        
        // Update template
        Route::put('/{template}', [NotificationTemplateController::class, 'update']);
        
        // Delete template
        Route::delete('/{template}', [NotificationTemplateController::class, 'destroy']);
        
        // Preview template
        Route::post('/{template}/preview', [NotificationTemplateController::class, 'preview']);
    });

    // Notification Management Routes
    Route::prefix('notifications/manage')->group(function () {
        // Send test notification
        Route::post('/test', [NotificationController::class, 'sendTest']);
        
        // Get notification statistics
        Route::get('/stats', [NotificationController::class, 'statistics']);
        
        // Clear all notifications
        Route::post('/clear', [NotificationController::class, 'clearAll']);
        
        // Resend failed notifications
        Route::post('/resend-failed', [NotificationController::class, 'resendFailed']);
    });
});