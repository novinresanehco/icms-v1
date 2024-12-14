<?php

namespace App\Core\Audit;

class AuditNotificationManager
{
    private NotificationRepository $repository;
    private TemplateEngine $templateEngine;
    private ChannelManager $channelManager;
    private RecipientResolver $recipientResolver;
    private RateLimiter $rateLimiter;
    private LoggerInterface $logger;

    public function __construct(
        NotificationRepository $repository,
        TemplateEngine $templateEngine,
        ChannelManager $channelManager,
        RecipientResolver $recipientResolver,
        RateLimiter $rateLimiter,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->templateEngine = $templateEngine;
        $this->channelManager = $channelManager;
        $this->recipientResolver = $recipientResolver;
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;
    }

    public function notify(NotificationData $data): NotificationResult
    {
        try {
            // Validate notification data
            $this->validateNotification($data);

            // Resolve recipients
            $recipients = $this->resolveRecipients($data);

            // Check rate limits
            $this->checkRateLimits($data, $recipients);

            // Prepare notification
            $notification = $this->prepareNotification($data, $recipients);

            // Send through channels
            $results = $this->sendThroughChannels($notification);

            // Store notification
            $this->storeNotification($notification, $results);

            // Return result
            return new NotificationResult(
                $notification,
                $results,
                count($recipients)
            );

        } catch (\Exception $e) {
            $this->handleNotificationError($e, $data);
            throw $e;
        }
    }

    public function notifyBatch(array $notifications): BatchNotificationResult
    {
        $results = [];
        $failures = [];

        foreach ($notifications as $data) {
            try {
                $results[] = $this->notify($data);
            } catch (\Exception $e) {
                $failures[] = [
                    'data' => $data,
                    'error' => $e->getMessage()
                ];
            }
        }

        return new BatchNotificationResult($results, $failures);
    }

    protected function prepareNotification(
        NotificationData $data,
        array $recipients
    ): Notification {
        // Generate notification content
        $content = $this->generateContent($data);

        // Create notification
        return new Notification([
            'id' => Str::uuid(),
            'type' => $data->getType(),
            'content' => $content,
            'recipients' => $recipients,
            'channels' => $data->getChannels(),
            'priority' => $data->getPriority(),
            'metadata' => $data->getMetadata(),
            'created_at' => now()
        ]);
    }

    protected function generateContent(NotificationData $data): array
    {
        $content = [];

        foreach ($data->getChannels() as $channel) {
            $template = $this->getTemplate($data->getType(), $channel);
            
            $content[$channel] = $this->templateEngine->render(
                $template,
                $data->getTemplateData()
            );
        }

        return $content;
    }

    protected function sendThroughChannels(Notification $notification): array
    {
        $results = [];

        foreach ($notification->getChannels() as $channel) {
            try {
                $channelResults = $this->channelManager
                    ->getChannel($channel)
                    ->send($notification);

                $results[$channel] = new ChannelResult(
                    $channel,
                    true,
                    $channelResults
                );

            } catch (\Exception $e) {
                $this->handleChannelError($e, $channel, $notification);
                
                $results[$channel] = new ChannelResult(
                    $channel,
                    false,
                    [],
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    protected function resolveRecipients(NotificationData $data): array
    {
        $recipients = [];

        foreach ($data->getRecipients() as $recipient) {
            try {
                $resolved = $this->recipientResolver->resolve($recipient);
                $recipients = array_merge($recipients, $resolved);
            } catch (\Exception $e) {
                $this->handleRecipientError($e, $recipient, $data);
            }
        }

        return array_unique($recipients);
    }

    protected function checkRateLimits(NotificationData $data, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            if (!$this->rateLimiter->allowNotification($recipient, $data->getType())) {
                throw new RateLimitExceededException(
                    "Rate limit exceeded for recipient: {$recipient}"
                );
            }
        }
    }

    protected function storeNotification(
        Notification $notification,
        array $results
    ): void {
        $this->repository->store(
            $notification,
            $this->summarizeResults($results)
        );
    }

    protected function summarizeResults(array $results): array
    {
        $summary = [
            'success' => true,
            'channels' => []
        ];

        foreach ($results as $channel => $result) {
            $summary['channels'][$channel] = [
                'success' => $result->isSuccess(),
                'error' => $result->getError(),
                'recipients' => $result->getRecipientCount()
            ];

            if (!$result->isSuccess()) {
                $summary['success'] = false;
            }
        }

        return $summary;
    }

    protected function getTemplate(string $type, string $channel): string
    {
        $template = $this->repository->findTemplate($type, $channel);

        if (!$template) {
            throw new TemplateNotFoundException(
                "Template not found for type: {$type}, channel: {$channel}"
            );
        }

        return $template;
    }
}
