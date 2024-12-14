<?php

namespace App\Core\Notification\Services;

use App\Core\Notification\Models\Notification;
use App\Core\Notification\Repositories\NotificationRepository;
use App\Core\User\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function __construct(
        private NotificationRepository $repository,
        private NotificationValidator $validator,
        private NotificationDispatcher $dispatcher
    ) {}

    public function send(string $type, User $user, array $data): Notification
    {
        $this->validator->validateNotification($type, $data);

        return DB::transaction(function () use ($type, $user, $data) {
            $notification = $this->repository->create([
                'type' => $type,
                'user_id' => $user->id,
                'data' => $data,
                'status' => 'pending'
            ]);

            $this->dispatcher->dispatch($notification);
            return $notification;
        });
    }

    public function sendBulk(string $type, array $userIds, array $data): Collection
    {
        $this->validator->validateBulkNotification($type, $userIds, $data);

        return DB::transaction(function () use ($type, $userIds, $data) {
            $notifications = collect();

            foreach ($userIds as $userId) {
                $notification = $this->repository->create([
                    'type' => $type,
                    'user_id' => $userId,
                    'data' => $data,
                    'status' => 'pending'
                ]);

                $this->dispatcher->dispatch($notification);
                $notifications->push($notification);
            }

            return $notifications;
        });
    }

    public function markAsRead(int $notificationId): bool
    {
        $notification = $this->repository->findOrFail($notificationId);
        return $this->repository->markAsRead($notification);
    }

    public function markAllAsRead(User $user): int
    {
        return $this->repository->markAllAsRead($user);
    }

    public function delete(int $notificationId): bool
    {
        $notification = $this->repository->findOrFail($notificationId);
        return $this->repository->delete($notification);
    }

    public function deleteAll(User $user): int
    {
        return $this->repository->deleteAll($user);
    }

    public function getUserNotifications(User $user, array $filters = []): Collection
    {
        return $this->repository->getUserNotifications($user, $filters);
    }

    public function getUnreadCount(User $user): int
    {
        return $this->repository->getUnreadCount($user);
    }

    public function reschedule(Notification $notification): void
    {
        $this->repository->updateStatus($notification, 'pending');
        $this->dispatcher->dispatch($notification);
    }

    public function handleFailure(Notification $notification, \Exception $e): void
    {
        $this->repository->logFailure($notification, $e->getMessage());
        $this->repository->updateStatus($notification, 'failed');

        if ($notification->shouldRetry()) {
            $this->reschedule($notification);
        }
    }
}
