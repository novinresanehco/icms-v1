<?php

namespace App\Core\Repositories;

use App\Core\Models\{Notification, NotificationTemplate, NotificationLog};
use App\Core\Events\NotificationSent;
use App\Core\Exceptions\NotificationException;
use Illuminate\Database\Eloquent\{Model, Collection, Builder};
use Illuminate\Support\Facades\{DB, Event};

class NotificationRepository extends Repository
{
    protected array $with = ['template', 'user'];

    public function create(array $attributes): Model
    {
        return DB::transaction(function() use ($attributes) {
            $notification = parent::create($attributes);

            if (isset($attributes['recipients'])) {
                $this->createRecipients($notification, $attributes['recipients']);
            }

            return $notification;
        });
    }

    public function markAsRead(Model $notification): bool
    {
        return $this->update($notification, [
            'read_at' => now(),
            'status' => 'read'
        ]);
    }

    public function markAllAsRead(int $userId): int
    {
        return $this->query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'status' => 'read']);
    }

    public function getUnread(int $userId): Collection
    {
        return $this->query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->get();
    }

    protected function createRecipients(Model $notification, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            $notification->recipients()->create([
                'user_id' => $recipient['user_id'],
                'type' => $recipient['type'] ?? 'to',
                'status' => 'pending'
            ]);
        }
    }
}

class NotificationTemplateRepository extends Repository
{
    public function findByCode(string $code): ?Model
    {
        return $this->remember(fn() =>
            $this->query()
                ->where('code', $code)
                ->first()
        );
    }

    public function compileTemplate(Model $template, array $data): string
    {
        try {
            return view()
                ->make("notifications.{$template->view}", $data)
                ->render();
        } catch (\Exception $e) {
            throw new NotificationException("Template compilation failed: {$e->getMessage()}");
        }
    }
}

class NotificationLogRepository extends Repository
{
    public function logNotification(
        Model $notification,
        string $channel,
        string $status,
        ?string $error = null
    ): Model {
        return $this->create([
            'notification_id' => $notification->id,
            'channel' => $channel,
            'status' => $status,
            'error' => $error,
            'sent_at' => now()
        ]);
    }

    public function getFailedNotifications(): Collection
    {
        return $this->query()
            ->where('status', 'failed')
            ->with('notification')
            ->orderByDesc('created_at')
            ->get();
    }

    public function getStats(array $filters = []): array
    {
        $query = $this->query();

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return [
            'total' => $query->count(),
            'success' => $query->where('status', 'success')->count(),
            'failed' => $query->where('status', 'failed')->count(),
            'by_channel' => $query->groupBy('channel')
                ->select('channel', DB::raw('count(*) as count'))
                ->pluck('count', 'channel')
                ->toArray()
        ];
    }
}

class NotificationChannelRepository extends Repository
{
    public function getActiveChannels(): Collection
    {
        return $this->remember(fn() =>
            $this->query()
                ->where('active', true)
                ->orderBy('priority')
                ->get()
        );
    }

    public function getChannelConfig(string $channel): array
    {
        $config = $this->query()
            ->where('name', $channel)
            ->first();

        return $config ? $config->configuration : [];
    }

    public function updateConfiguration(Model $channel, array $config): bool
    {
        return $this->update($channel, [
            'configuration' => array_merge($channel->configuration ?? [], $config)
        ]);
    }
}
