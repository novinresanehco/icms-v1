<?php

namespace App\Core\Notification\Http\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Notification\Services\NotificationService;
use App\Core\Notification\Http\Requests\{
    ListNotificationsRequest,
    MarkNotificationsReadRequest,
    DeleteNotificationsRequest
};
use App\Core\Notification\Http\Resources\{
    NotificationResource,
    NotificationCollection
};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    /**
     * Create a new controller instance.
     *
     * @param NotificationService $notificationService
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * List notifications for the authenticated user.
     *
     * @param ListNotificationsRequest $request
     * @return NotificationCollection
     */
    public function index(ListNotificationsRequest $request): NotificationCollection
    {
        try {
            $filters = $request->validated();
            $notifications = $this->notificationService->getNotifications(
                $request->user(),
                $filters
            );

            return new NotificationCollection($notifications);

        } catch (\Exception $e) {
            Log::error('Failed to fetch notifications', [
                'user_id' => $request->user()->id,
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get unread notification count.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unreadCount(Request $request): JsonResponse
    {
        try {
            $count = $this->notificationService->getUnreadCount($request->user());

            return response()->json(['count' => $count]);

        } catch (\Exception $e) {
            Log::error('Failed to get unread count', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Show a single notification.
     *
     * @param Request $request
     * @param string $id
     * @return NotificationResource
     */
    public function show(Request $request, string $id): NotificationResource
    {
        try {
            $notification = $this->notificationService->findNotification(
                $request->user(),
                $id
            );

            return new NotificationResource($notification);

        } catch (\Exception $e) {
            Log::error('Failed to fetch notification', [
                'notification_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Mark notifications as read.
     *
     * @param MarkNotificationsReadRequest $request
     * @return JsonResponse
     */
    public function markAsRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        try {
            $ids = $request->input('notifications', []);
            
            $this->notificationService->markAsRead(
                $request->user(),
                $ids
            );

            return response()->json(['message' => 'Notifications marked as read']);

        } catch (\Exception $e) {
            Log::error('Failed to mark notifications as read', [
                'notification_ids' => $ids,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Mark a single notification as read.
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function markOneAsRead(Request $request, string $id): JsonResponse
    {
        try {
            $this->notificationService->markAsRead(
                $request->user(),
                [$id]
            );

            return response()->json(['message' => 'Notification marked as read']);

        } catch (\Exception $e) {
            Log::error('Failed to mark notification as read', [
                'notification_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete notifications.
     *
     * @param DeleteNotificationsRequest $request
     * @return JsonResponse
     */
    public function destroy(DeleteNotificationsRequest $request): JsonResponse
    {
        try {
            $ids = $request->input('notifications', []);
            
            $this->notificationService->deleteNotifications(
                $request->user(),
                $ids
            );

            return response()->json(['message' => 'Notifications deleted']);

        } catch (\Exception $e) {
            Log::error('Failed to delete notifications', [
                'notification_ids' => $ids,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete a single notification.
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function destroyOne(Request $request, string $id): JsonResponse
    {
        try {
            $this->notificationService->deleteNotifications(
                $request->user(),
                [$id]
            );

            return response()->json(['message' => 'Notification deleted']);

        } catch (\Exception $e) {
            Log::error('Failed to delete notification', [
                'notification_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get notification statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $stats = $this->notificationService->getStatistics(
                $request->user()
            );

            return response()->json($stats);

        } catch (\Exception $e) {
            Log::error('Failed to get notification statistics', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}