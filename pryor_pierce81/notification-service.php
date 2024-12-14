<?php

namespace App\Core\Notification;

class NotificationService implements NotificationInterface
{
    private MessageValidator $validator;
    private ChannelManager $channelManager;
    private PriorityHandler $priorityHandler;
    private DeliveryTracker $tracker;
    private NotificationLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        MessageValidator $validator,
        ChannelManager $channelManager,
        PriorityHandler $priorityHandler,
        DeliveryTracker $tracker,
        NotificationLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->validator = $validator;
        $this->channelManager = $channelManager;
        $this->priorityHandler = $priorityHandler;
        $this->tracker = $tracker;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function dispatchNotification(NotificationContext $context): NotificationResult
    {
        $notificationId = $this->initializeNotification($context);
        
        try {
            DB::beginTransaction();

            $this->validateNotification($context);
            $channels = $this->determineChannels($context);
            
            $messages = $this->prepareMessages($context, $channels);
            $deliveryResult = $this->deliverMessages($messages);

            $this->verifyDelivery($deliveryResult);
            $this->trackDelivery($deliveryResult);

            $result = new NotificationResult([
                'notificationId' => $notificationId,
                'channels' => $channels,
                'delivery' => $deliveryResult,
                'status' => NotificationStatus::DELIVERED,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (NotificationException $e) {
            DB::rollBack();
            $this->handleNotificationFailure($e, $notificationId);
            throw new CriticalNotificationException($e->getMessage(), $e);
        }
    }

    private function validateNotification(NotificationContext $context): void
    {
        if (!$this->validator->validate($context)) {
            throw new ValidationException('Notification validation failed');
        }
    }

    private function determineChannels(NotificationContext $context): array
    {
        $priority = $this->priorityHandler->determinePriority($context);
        return $this->channelManager->getChannels($priority);
    }

    private function deliverMessages(array $messages): DeliveryResult
    {
        foreach ($messages as $message) {
            try {
                $this->channelManager->deliver($message);
            } catch (DeliveryException $e) {
                if ($message->isCritical()) {
                    $this->emergency->handleCriticalDeliveryFailure($e, $message);
                }
                throw $e;
            }
        }
    }
}
