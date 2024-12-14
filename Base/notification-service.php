<?php

namespace App\Core\Services;

use App\Core\Repositories\{
    NotificationRepository,
    NotificationTemplateRepository,
    NotificationLogRepository,
    NotificationChannelRepository
};
use App\Core\Events\NotificationSent;
use App\Core\Exceptions\NotificationException;
use App\Core\Support\NotificationDispatcher;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event};

class NotificationService extends BaseService
{
    protected NotificationTemplateRepository $templateRepository;
    protected NotificationLogRepository $logRepository;
    protected NotificationChannelRepository $channelRepository;
    protected NotificationDispatcher $dispatcher;

    public function __construct(
        NotificationRepository $repository,
        NotificationTemplateRepository $templateRepository,
        NotificationLogRepository $logRepository,
        NotificationChannelRepository $channelRepository,
        NotificationDispatcher $dispatcher
    ) {
        parent::__construct($repository);
        $this->templateRepository = $templateRepository;
        $this->logRepository = $logRepository;
        $this->channelRepository = $channelRepository;
        $this->dispatcher = $dispatcher;
    }

    public function send(string $templateCode, array $data, array $recipients): Model
    {
        try {
            DB::beginTransaction();

            $template = $this->templateRepository->findByCode($templateCode);
            
            if (!$template) {
                throw new NotificationException("Template not found: {$templateCode}");
            }

            $content = $this->templateRepository->compileTemplate($template, $data);

            $notification = $this->repository->create([
                'template_id' => $template->id,
                'content' => $content,
                'data' => $data,
                'recipients' => $recipients
            ]);

            foreach ($this->channelRepository->getActiveChannels() as $channel) {
                $this->dispatchToChannel($notification, $channel);
            }

            DB::commit();

            return $notification;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new NotificationException("Failed to send notification: {$e->getMessage()}", 0, $e);
        }
    }

    protected function dispatchToChannel(Model $notification, Model $channel): void
    {
        try {
            $config = $this->channelRepository->getChannelConfig($channel->name);
            
            $this->dispatcher->dispatch($notification, $channel->name, $config);
            
            $this->logRepository->logNotification(
                $notification,
                $channel->name,
                'success'
            );

            Event::dispatch(new NotificationSent($notification, $channel->name));
        } catch (\Exception $e) {
            $this->logRepository->logNotification(
                $notification,
                $channel->name,
                'failed',
                $e->getMessage()
            );

            if ($channel->fail_on_error) {
                throw $e;
            }
        }
    }

    public function markAsRead(Model $notification): bool
    {
        return $this->repository->markAsRead($notification);
    }

    public function markAllAsRead(int $userId): int
    {
        return $this->repository->markAllAsRead($userId);
    }

    public function getUnreadNotifications(int $userId): Collection
    {
        return $this->repository->getUnread($userId);
    }

    public function getNotificationStats(array $filters = []): array
    {
        return $this->logRepository->getStats($filters);
    }

    public function configureChannel(Model $channel, array $config): bool
    {
        try {
            DB::beginTransaction();

            $updated = $this->channelRepository->updateConfiguration($channel, $config);

            DB::commit();

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new NotificationException("Failed to configure channel: {$e->getMessage()}", 0, $e);
        }
    }

    public function getFailedNotifications(): Collection
    {
        return $this->logRepository->getFailedNotifications();
    }

    public function retry(Model $notification): void
    {
        try {
            DB::beginTransaction();

            foreach ($this->channelRepository->getActiveChannels() as $channel) {
                $this->dispatchToChannel($notification, $channel);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new NotificationException("Failed to retry notification: {$e->getMessage()}", 0, $e);
        }
    }
}
