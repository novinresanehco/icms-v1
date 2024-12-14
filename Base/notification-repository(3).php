<?php

namespace App\Repositories;

use App\Models\Notification;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use Illuminate\Support\Collection;

class NotificationRepository extends BaseRepository implements NotificationRepositoryInterface
{
    protected array $filterableFields = ['type', 'read_at', 'user_id'];

    public function getUserNotifications(int $userId, bool $unreadOnly = false): Collection
    {
        $query = $this->model->where('user_id', $userId);
        
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }
        
        return $query->orderByDesc('created_at')->get();
    }

    public function markAsRead(array $notificationIds): bool
    {
        try {
            DB::beginTransaction();
            $this->model->whereIn('id', $notificationIds)
                ->update(['read_at' => now()]);
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function send(array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $notification = $this->create([
                'user_id' => $data['user_id'],
                'type' => $data['type'],
                'title' => $data['title'],
                'content' => $data['content'],
                'metadata' => $data['metadata'] ?? [],
                'data' => $data['data'] ?? []
            ]);
            
            if (isset($data['channels'])) {
                $this->sendThroughChannels($notification, $data['channels']);
            }
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    protected function sendThroughChannels($notification, array $channels): void
    {
        foreach ($channels as $channel) {
            app(NotificationService::class)->sendToChannel($channel, $notification);
        }
    }
}
