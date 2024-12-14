<?php

namespace App\Core\Audit;

class AuditManager
{
    private AuditRepository $repository;
    private array $handlers = [];
    private array $filters = [];
    private array $enrichers = [];

    public function log(AuditEvent $event): void
    {
        $enrichedEvent = $this->enrichEvent($event);
        
        if ($this->shouldLog($enrichedEvent)) {
            $this->repository->save($enrichedEvent);
            $this->notifyHandlers($enrichedEvent);
        }
    }

    public function registerHandler(string $type, AuditHandler $handler): void
    {
        $this->handlers[$type][] = $handler;
    }

    public function addFilter(AuditFilter $filter): void
    {
        $this->filters[] = $filter;
    }

    public function addEnricher(AuditEnricher $enricher): void
    {
        $this->enrichers[] = $enricher;
    }

    private function shouldLog(AuditEvent $event): bool
    {
        foreach ($this->filters as $filter) {
            if (!$filter->shouldLog($event)) {
                return false;
            }
        }
        return true;
    }

    private function enrichEvent(AuditEvent $event): AuditEvent
    {
        $enrichedEvent = $event;
        foreach ($this->enrichers as $enricher) {
            $enrichedEvent = $enricher->enrich($enrichedEvent);
        }
        return $enrichedEvent;
    }

    private function notifyHandlers(AuditEvent $event): void
    {
        $handlers = $this->handlers[$event->getType()] ?? [];
        foreach ($handlers as $handler) {
            $handler->handle($event);
        }
    }
}

class AuditEvent
{
    private string $id;
    private string $type;
    private string $action;
    private array $data;
    private array $metadata;
    private \DateTime $timestamp;
    private ?string $userId;

    public function __construct(
        string $type,
        string $action,
        array $data = [],
        array $metadata = []
    ) {
        $this->id = uniqid('audit_', true);
        $this->type = $type;
        $this->action = $action;
        $this->data = $data;
        $this->metadata = $metadata;
        $this->timestamp = new \DateTime();
        $this->userId = auth()->id();
    }

    public function getId(): string
    {
        return $this->id;
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

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getTimestamp(): \DateTime
    {
        return $this->timestamp;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function withMetadata(array $metadata): self
    {
        $clone = clone $this;
        $clone->metadata = array_merge($clone->metadata, $metadata);
        return $clone;
    }
}

class AuditRepository
{
    private $connection;

    public function save(AuditEvent $event): void
    {
        $this->connection->table('audit_logs')->insert([
            'id' => $event->getId(),
            'type' => $event->getType(),
            'action' => $event->getAction(),
            'data' => json_encode($event->getData()),
            'metadata' => json_encode($event->getMetadata()),
            'user_id' => $event->getUserId(),
            'timestamp' => $event->getTimestamp()
        ]);
    }

    public function findByType(string $type, array $options = []): array
    {
        $query = $this->connection->table('audit_logs')
            ->where('type', $type);

        if (isset($options['from'])) {
            $query->where('timestamp', '>=', $options['from']);
        }

        if (isset($options['to'])) {
            $query->where('timestamp', '<=', $options['to']);
        }

        return $query->get()->map(fn($row) => $this->hydrate($row))->toArray();
    }

    public function findByUserId(string $userId, array $options = []): array
    {
        return $this->connection->table('audit_logs')
            ->where('user_id', $userId)
            ->get()
            ->map(fn($row) => $this->hydrate($row))
            ->toArray();
    }

    private function hydrate($row): AuditEvent
    {
        return new AuditEvent(
            $row->type,
            $row->action,
            json_decode($row->data, true),
            json_decode($row->metadata, true)
        );
    }
}

interface AuditHandler
{
    public function handle(AuditEvent $event): void;
}

interface AuditFilter
{
    public function shouldLog(AuditEvent $event): bool;
}

interface AuditEnricher
{
    public function enrich(AuditEvent $event): AuditEvent;
}

class DatabaseAuditHandler implements AuditHandler
{
    private AuditRepository $repository;

    public function handle(AuditEvent $event): void
    {
        $this->repository->save($event);
    }
}

class RequestEnricher implements AuditEnricher
{
    public function enrich(AuditEvent $event): AuditEvent
    {
        return $event->withMetadata([
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl()
        ]);
    }
}

class SensitiveDataFilter implements AuditFilter
{
    private array $sensitiveFields = ['password', 'token', 'secret'];

    public function shouldLog(AuditEvent $event): bool
    {
        foreach ($this->sensitiveFields as $field) {
            if (isset($event->getData()[$field])) {
                return false;
            }
        }
        return true;
    }
}
