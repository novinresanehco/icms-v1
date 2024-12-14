<?php

namespace App\Core\Notification\Repositories;

use App\Core\Repository\BaseRepository;
use App\Core\Notification\Models\Notification;
use App\Core\Notification\Contracts\NotifiableInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class NotificationRepository extends BaseRepository
{
    protected $cachePrefix = 'notifications:';
    protected $cacheTTL = 3600; // 1 hour

    public function model(): string
    {
        return Notification::class;
    }

    /**
     * Create a new notification record
     *
     * @param array $data
     * @return Notification
     */
    public function create(array $data): Notification
    {
        $notification = parent::create($data);
        
        $this->clearCache($notification);
        
        return $notification;
    }

    /**
     * Update a notification record
     *
     * @param string $id
     * @param array $data
     * @return Notification
     */
    public function update(string $id, array $data): Notification
    {
        $notification = parent::update($id, $data);
        
        $this->clearCache($notification);
        
        return $notification;
    }

    /**
     * Get notification history for a notifiable entity
     *
     * @param NotifiableInterface $notifiable
     * @param array $filters
     * @return Collection
     */
    public function getHistory(NotifiableInterface $notifiable, array $filters = []): Collection
    {
        $cacheKey = $this->getHistoryCacheKey($notifiable, $filters);

        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($notifiable, $filters) {
            $query = $this->model->newQuery()
                ->where('notifiable_type', get_class($notifiable))
                ->where('notifiable_id', $notifiable->id);

            // Apply filters
            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['from_date'])) {
                $query->where('created_at', '>=', $filters['from_date']);
            }

            if (isset($filters['to_date'])) {
                $query->where('created_at', '<=', $filters['to_date']);
            }

            return $query->orderBy('created_at', 'desc')->get();
        });
    }

    /**
     * Get unread notifications for a notifiable entity
     *
     * @param NotifiableInterface $notifiable
     * @return Collection
     */
    public function getUnread(NotifiableInterface $notifiable): Collection
    {
        $cacheKey = $this->getUnreadCacheKey($notifiable);

        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($notifiable) {
            return $this->model->newQuery()
                ->where('notifiable_type', get_class($notifiable))
                ->where('notifiable_id', $notifiable->id)
                ->whereNull('read_at')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    /**
     * Mark notifications as read
     *
     * @param array $ids
     * @return bool
     */
    public function markAsRead(array $ids): bool
    {
        $notifications = $this->model->whereIn('id', $ids)->get();
        
        foreach ($notifications as $notification) {
            $notification->update(['read_at' => now()]);
            $this->clearCache($notification);
        }

        return true;
    }

    /**
     * Delete old notifications
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted notifications
     */
    public function deleteOld(int $days): int
    {
        $date = now()->subDays($days);
        
        $count = $this->model->where('created_at', '<', $date)->delete();
        
        if ($count > 0) {
            Cache::tags('notifications')->flush();
        }

        return $count;
    }

    /**
     * Clear cache for a notification
     *
     * @param Notification $notification
     * @return void
     */
    protected function clearCache(Notification $notification): void
    {
        $tags = [
            'notifications',
            "notifications:user:{$notification->notifiable_id}",
            "notifications:type:{$notification->type}"
        ];

        Cache::tags($tags)->flush();
    }

    /**
     * Get cache key for notification history
     *
     * @param NotifiableInterface $notifiable
     * @param array $filters
     * @return string
     */
    protected function getHistoryCacheKey(NotifiableInterface $notifiable, array $filters): string
    {
        $filterKey = md5(json_encode($filters));
        return "{$this->cachePrefix}history:{$notifiable->id}:{$filterKey}";
    }

    /**
     * Get cache key for unread notifications
     *
     * @param NotifiableInterface $notifiable
     * @return string
     */
    protected function getUnreadCacheKey(NotifiableInterface $notifiable): string
    {
        return "{$this->cachePrefix}unread:{$notifiable->id}";
    }
}