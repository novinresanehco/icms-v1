<?php

namespace App\Core\Notifications;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Events\EventManagerInterface;
use App\Core\Exception\NotificationException;
use Psr\Log\LoggerInterface;

class NotificationManager implements NotificationManagerInterface
{
    private SecurityManagerInterface $security;
    private EventManagerInterface $events;
    private LoggerInterface $logger;
    private array $handlers = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        EventManagerInterface $events,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->events = $events;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function send(array $notification): string
    {
        $notificationId = $this->generateNotificationId();

        try {
            // Validate notification structure
            $this->validateNotification($notification);

            // Security check
            $this->security->validateOperation('notification:send', $notification['type']);

            // Get appropriate handlers
            $handlers = $this->getHandlers($notification['type']);

            // Execute handlers
            $results = [];
            foreach ($handlers as $handler) {
                $result = $this->executeHandler($handler, $notification);
                $results[$handler->getName()] = $result;
            }

            // Log success
            $this->logSuccess($notificationId, $notification, $results);

            // Dispatch event
            $this->events->dispatch('notification.sent', [
                'notification_id' => $notificationId,
                'type' => $notification['type'],
                'results' => $results
            ]);

            return $notificationId;

        } catch (\Exception $e) {
            $this->handleFailure($notificationId, $notification, $e);
            throw new NotificationException('Notification send failed', 0, $e);
        }
    }

    public function registerHandler(
        NotificationHandlerInterface $handler,
        array $options = []
    ): void {
        try {
            // Validate handler
            $this->validateHandler($handler);

            // Register with metadata
            $this->handlers[$handler->getName()] = [
                'handler' => $handler,
                'types' => $options['types'] ?? ['*'],
                'priority' => $options['priority'] ?? 0,
                'security' => $options['security'] ?? []
            ];

        } catch (\Exception $e) {
            throw new NotificationException(
                "Handler registration failed: {$handler->getName()}",
                0,
                $e
            );
        }
    }

    private function validateNotification(array $notification): void
    {
        $required = ['type', 'content', 'recipient'];
        foreach ($required as $field) {
            if (!isset($notification[$field])) {
                throw new NotificationException("Missing required field: {$field}");
            }
        }

        if (!preg_match('/^[a-zA-Z0-9\.:_-]+$/', $notification['type'])) {
            throw new NotificationException('Invalid notification type format');
        }

        if (strlen(json_encode($notification['content'])) > $this->config['max_content_size']) {
            throw new NotificationException('Content size exceeds limit');
        }
    }

    private function validateHandler(NotificationHandlerInterface $handler): void
    {
        if (!$handler->isSupported()) {
            throw new NotificationException('Handler not supported in current environment');
        }
    }

    private function executeHandler(
        array $handlerData,
        array $notification
    ): NotificationResult {
        $handler = $handlerData['handler'];
        
        try {
            // Check handler security
            if (!empty($handlerData['security'])) {
                $this->security->validateContext($handlerData['security']);
            }

            // Execute with timeout
            return $this->executeWithTimeout(
                fn() => $handler->handle($notification),
                $this->config['handler_timeout']
            );

        } catch (\Exception $e) {
            throw new NotificationException(
                "Handler execution failed: {$handler->getName()}",
                0,
                $e
            );
        }
    }

    private function executeWithTimeout(callable $callback, int $timeout): mixed
    {
        $result = null;
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new NotificationException('Could not fork process');
        } else if ($pid) {
            // Parent process
            $status = null;
            pcntl_waitpid($pid, $status, WNOHANG);
            
            $waited = 0;
            $interval = 100000; // 0.1 second
            
            while ($waited < $timeout) {
                $res = pcntl_waitpid($pid, $status, WNOHANG);
                if ($res == -1 || $res > 0) {
                    break;
                }
                usleep($interval);
                $waited += $interval;
            }
            
            if ($waited >= $timeout) {
                posix_kill($pid, SIGTERM);
                throw new NotificationException('Handler execution timed out');
            }
            
            return $result;
        } else {
            // Child process
            try {
                $result = $callback();
                exit(0);
            } catch (\Exception $e) {
                exit(1);
            }
        }
    }

    private function getHandlers(string $type): array
    {
        return array_filter(
            $this->handlers,
            fn($handler) => in_array($type, $handler['types']) || 
                           in_array('*', $handler['types'])
        );
    }

    private function generateNotificationId(): string
    {
        return uniqid('notif_', true);
    }

    private function logSuccess(
        string $notificationId,
        array $notification,
        array $results
    ): void {
        $this->logger->info('Notification sent successfully', [
            'notification_id' => $notificationId,
            'type' => $notification['type'],
            'recipient' => $notification['recipient'],
            'results' => array_map(fn($r) => $r->toArray(), $results)
        ]);
    }

    private function handleFailure(
        string $notificationId,
        array $notification,
        \Exception $e
    ): void {
        $this->logger->error('Notification failed', [
            'notification_id' => $notificationId,
            'type' => $notification['type'],
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->events->dispatch('notification.failed', [
            'notification_id' => $notificationId,
            'type' => $notification['type'],
            'error' => $e->getMessage()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_content_size' => 65536, // 64KB
            'handler_timeout' => 30, // seconds
            'max_handlers' => 10,
            'retry_attempts' => 3
        ];
    }
}
