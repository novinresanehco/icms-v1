<?php

namespace App\Core\Audit;

class EventCollector
{
    private array $handlers;
    private EventValidator $validator;
    private EventEnricher $enricher;
    private SecurityContext $securityContext;
    private LoggerInterface $logger;

    public function __construct(
        EventValidator $validator,
        EventEnricher $enricher,
        SecurityContext $securityContext,
        LoggerInterface $logger
    ) {
        $this->handlers = [];
        $this->validator = $validator;
        $this->enricher = $enricher;
        $this->securityContext = $securityContext;
        $this->logger = $logger;
    }

    public function registerHandler(string $eventType, callable $handler): void
    {
        if (isset($this->handlers[$eventType])) {
            throw new DuplicateHandlerException("Handler already registered for event type: {$eventType}");
        }

        $this->handlers[$eventType] = $handler;
    }

    public function collect(AuditEvent $event): array
    {
        try {
            // Validate event
            $this->validator->validate($event);

            // Collect base data
            $data = $this->collectBaseData($event);

            // Enrich with handler-specific data
            $data = $this->enrichWithHandlerData($event, $data);

            // Enrich with context
            $data = $this->enricher->enrich($data, [
                'security_context' => $this->securityContext->getContext(),
                'timestamp' => now(),
                'environment' => $this->getEnvironmentData()
            ]);

            // Final validation
            $this->validator->validateCollectedData($data);

            return $data;

        } catch (\Exception $e) {
            $this->logger->error('Event collection failed', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
            throw new EventCollectionException('Failed to collect event data', 0, $e);
        }
    }

    protected function collectBaseData(AuditEvent $event): array
    {
        return [
            'event_id' => $event->getId(),
            'type' => $event->getType(),
            'action' => $event->getAction(),
            'data' => $event->getData(),
            'user_id' => $event->getUserId(),
            'ip_address' => $this->securityContext->getIpAddress(),
            'user_agent' => $this->securityContext->getUserAgent(),
            'metadata' => $event->getMetadata(),
            'severity' => $event->getSeverity(),
            'trace_id' => $this->generateTraceId(),
            'session_id' => $this->securityContext->getSessionId()
        ];
    }

    protected function enrichWithHandlerData(AuditEvent $event, array $baseData): array
    {
        $eventType = $event->getType();

        if (isset($this->handlers[$eventType])) {
            try {
                $handlerData = ($this->handlers[$eventType])($event);
                
                if (!is_array($handlerData)) {
                    throw new InvalidHandlerDataException(
                        "Handler for {$eventType} must return array"
                    );
                }

                return array_merge($baseData, [
                    'handler_data' => $handlerData,
                    'handler_timestamp' => now()
                ]);

            } catch (\Exception $e) {
                $this->logger->warning('Handler execution failed', [
                    'event_type' => $eventType,
                    'error' => $e->getMessage()
                ]);
                
                return array_merge($baseData, [
                    'handler_error' => $e->getMessage()
                ]);
            }
        }

        return $baseData;
    }

    protected function getEnvironmentData(): array
    {
        return [
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? null,
            'hostname' => gethostname(),
            'environment' => config('app.env'),
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }

    protected function generateTraceId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
