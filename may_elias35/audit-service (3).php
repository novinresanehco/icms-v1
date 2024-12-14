<?php

namespace App\Core\Audit;

class AuditService
{
    private AuditRepository $repository;
    private EventCollector $collector;
    private AuditFormatter $formatter;
    private AuditLogger $logger;

    public function __construct(
        AuditRepository $repository,
        EventCollector $collector,
        AuditFormatter $formatter,
        AuditLogger $logger
    ) {
        $this->repository = $repository;
        $this->collector = $collector;
        $this->formatter = $formatter;
        $this->logger = $logger;
    }

    public function recordEvent(AuditEvent $event): void
    {
        $formattedEvent = $this->formatter->format($event);
        $this->repository->store($formattedEvent);
        $this->logger->logEvent($event);
    }

    public function getEvents(array $filters = []): array
    {
        return $this->repository->getEvents($filters);
    }

    public function generateReport(ReportConfig $config): AuditReport
    {
        $events = $this->repository->getEvents($config->getFilters());
        return $this->formatter->generateReport($events, $config);
    }

    public function exportEvents(array $filters, string $format): string
    {
        $events = $this->repository->getEvents($filters);
        return $this->formatter->export($events, $format);
    }

    public function purgeEvents(array $criteria): void
    {
        $this->repository->purge($criteria);
        $this->logger->logPurge($criteria);
    }
}

class AuditEvent
{
    private string $type;
    private string $action;
    private array $data;
    private string $userId;
    private array $metadata;
    private int $timestamp;

    public function __construct(
        string $type,
        string $action,
        array $data,
        string $userId,
        array $metadata = []
    ) {
        $this->type = $type;
        $this->action = $action;
        $this->data = $data;
        $this->userId = $userId;
        $this->metadata = $metadata;
        $this->timestamp = time();
    }

    public function getType(): string 
    {
        return $this->type;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}

class AuditRepository
{
    private DatabaseConnection $db;
    private QueryBuilder $queryBuilder;
    private array $config;

    public function store(array $event): void
    {
        $this->db->insert('audit_events', [
            'type' => $event['type'],
            'action' => $event['action'],
            'data' => json_encode($event['data']),
            'user_id' => $event['user_id'],
            'metadata' => json_encode($event['metadata']),
            'timestamp' => $event['timestamp'],
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function getEvents(array $filters = []): array
    {
        $query = $this->queryBuilder
            ->select('*')
            ->from('audit_events');

        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }

        if (isset($filters['from'])) {
            $query->where('timestamp', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('timestamp', '<=', $filters['to']);
        }

        $query->orderBy('timestamp', 'DESC');

        return array_map(function ($row) {
            $row['data'] = json_decode($row['data'], true);
            $row['metadata'] = json_decode($row['metadata'], true);
            return $row;
        }, $query->get());
    }

    public function purge(array $criteria): void
    {
        $query = $this->queryBuilder
            ->delete()
            ->from('audit_events');

        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }

        $query->execute();
    }
}

class EventCollector
{
    private array $handlers;

    public function registerHandler(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    public function collect(AuditEvent $event): array
    {
        $additionalData = [];

        if (isset($this->handlers[$event->getType()])) {
            $additionalData = ($this->handlers[$event->getType()])($event);
        }

        return array_merge($event->getData(), $additionalData);
    }
}

class AuditFormatter
{
    public function format(AuditEvent $event): array
    {
        return [
            'type' => $event->getType(),
            'action' => $event->getAction(),
            'data' => $event->getData(),
            'user_id' => $event->getUserId(),
            'metadata' => $event->getMetadata(),
            'timestamp' => $event->getTimestamp()
        ];
    }

    public function generateReport(array $events, ReportConfig $config): AuditReport
    {
        $report = new AuditReport();

        foreach ($events as $event) {
            $report->addEvent($event);
        }

        if ($config->shouldIncludeStats()) {
            $report->setStats($this->calculateStats($events));
        }

        if ($config->shouldIncludeSummary()) {
            $report->setSummary($this->generateSummary($events));
        }

        return $report;
    }

    public function export(array $events, string $format): string
    {
        switch ($format) {
            case 'json':
                return json_encode($events);
            case 'csv':
                return $this->convertToCsv($events);
            default:
                throw new UnsupportedFormatException($format);
        }
    }

    protected function calculateStats(array $events): array
    {
        $stats = [
            'total_events' => count($events),
            'events_by_type' => [],
            'events_by_user' => []
        ];

        foreach ($events as $event) {
            $stats['events_by_type'][$event['type']] = 
                ($stats['events_by_type'][$event['type']] ?? 0) + 1;
                
            $stats['events_by_user'][$event['user_id']] = 
                ($stats['events_by_user'][$event['user_id']] ?? 0) + 1;
        }

        return $stats;
    }

    protected function generateSummary(array $events): array
    {
        return [
            'period' => [
                'start' => min(array_column($events, 'timestamp')),
                'end' => max(array_column($events, 'timestamp'))
            ],
            'most_active_user' => $this->getMostActiveUser($events),
            'most_common_action' => $this->getMostCommonAction($events)
        ];
    }

    protected function convertToCsv(array $events): string
    {
        $output = fopen('php://temp', '