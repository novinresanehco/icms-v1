<?php

namespace App\Core\Audit\Contracts;

interface AuditServiceInterface
{
    public function log(string $action, string $entity, array $data = []): void;
    public function logBatch(array $entries): void;
    public function getAuditLog(array $filters = []): Collection;
    public function getEntityHistory(string $entity, string $entityId): Collection;
    public function search(array $criteria): Collection;
}

namespace App\Core\Audit\Services;

class AuditService implements AuditServiceInterface
{
    protected AuditLogger $logger;
    protected AuditRepository $repository;
    protected ContextBuilder $contextBuilder;
    protected AuditFormatter $formatter;

    public function __construct(
        AuditLogger $logger,
        AuditRepository $repository,
        ContextBuilder $contextBuilder,
        AuditFormatter $formatter
    ) {
        $this->logger = $logger;
        $this->repository = $repository;
        $this->contextBuilder = $contextBuilder;
        $this->formatter = $formatter;
    }

    public function log(string $action, string $entity, array $data = []): void
    {
        $context = $this->contextBuilder->build();
        
        $entry = new AuditEntry([
            'id' => Str::uuid(),
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $data['id'] ?? null,
            'data' => $this->formatter->format($data),
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
            'timestamp' => now()
        ]);

        $this->logger->log($entry);
        $this->repository->save($entry);
    }

    public function logBatch(array $entries): void
    {
        $context = $this->contextBuilder->build();
        
        $formattedEntries = array_map(function ($entry) use ($context) {
            return new AuditEntry([
                'id' => Str::uuid(),
                'action' => $entry['action'],
                'entity_type' => $entry['entity'],
                'entity_id' => $entry['data']['id'] ?? null,
                'data' => $this->formatter->format($entry['data']),
                'user_id' => $context->getUserId(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'timestamp' => now()
            ]);
        }, $entries);

        $this->logger->logBatch($formattedEntries);
        $this->repository->saveBatch($formattedEntries);
    }

    public function getAuditLog(array $filters = []): Collection
    {
        return $this->repository->getAuditLog($filters);
    }

    public function getEntityHistory(string $entity, string $entityId): Collection
    {
        return $this->repository->getEntityHistory($entity, $entityId);
    }

    public function search(array $criteria): Collection
    {
        return $this->repository->search($criteria);
    }
}

namespace App\Core\Audit\Services;

class AuditLogger
{
    protected array $handlers;
    protected array $processors;
    protected LoggerInterface $logger;

    public function log(AuditEntry $entry): void
    {
        $processedEntry = $this->processEntry($entry);
        
        foreach ($this->handlers as $handler) {
            $handler->handle($processedEntry);
        }

        $this->logger->info('Audit log entry created', [
            'entry' => $processedEntry->toArray()
        ]);
    }

    public function logBatch(array $entries): void
    {
        $processedEntries = array_map(
            fn($entry) => $this->processEntry($entry),
            $entries
        );

        foreach ($this->handlers as $handler) {
            $handler->handleBatch($processedEntries);
        }

        $this->logger->info('Batch audit log entries created', [
            'count' => count($entries)
        ]);
    }

    protected function processEntry(AuditEntry $entry): AuditEntry
    {
        foreach ($this->processors as $processor) {
            $entry = $processor->process($entry);
        }

        return $entry;
    }
}

namespace App\Core\Audit\Services;

class ContextBuilder
{
    protected Request $request;
    protected AuthManager $auth;

    public function build(): AuditContext
    {
        return new AuditContext([
            'user_id' => $this->getUserId(),
            'ip_address' => $this->getIpAddress(),
            'user_agent' => $this->getUserAgent(),
            'session_id' => $this->getSessionId(),
            'additional' => $this->getAdditionalContext()
        ]);
    }

    protected function getUserId(): ?string
    {
        return $this->auth->id();
    }

    protected function getIpAddress(): string
    {
        return $this->request->ip();
    }

    protected function getUserAgent(): string
    {
        return $this->request->userAgent();
    }

    protected function getSessionId(): string
    {
        return session()->getId();
    }

    protected function getAdditionalContext(): array
    {
        return [
            'url' => $this->request->fullUrl(),
            'method' => $this->request->method(),
            'headers' => $this->getRelevantHeaders(),
            'referrer' => $this->request->header('referer'),
            'environment' => app()->environment()
        ];
    }

    protected function getRelevantHeaders(): array
    {
        return array_filter($this->request->headers->all(), function ($header) {
            return !in_array(strtolower($header), [
                'authorization',
                'cookie',
                'x-xsrf-token'
            ]);
        });
    }
}

namespace App\Core\Audit\Models;

class AuditEntry
{
    protected string $id;
    protected string $action;
    protected string $entityType;
    protected ?string $entityId;
    protected array $data;
    protected ?string $userId;
    protected string $ipAddress;
    protected string $userAgent;
    protected Carbon $timestamp;

    public function __construct(array $attributes)
    {
        $this->id = $attributes['id'];
        $this->action = $attributes['action'];
        $this->entityType = $attributes['entity_type'];
        $this->entityId = $attributes['entity_id'];
        $this->data = $attributes['data'];
        $this->userId = $attributes['user_id'];
        $this->ipAddress = $attributes['ip_address'];
        $this->userAgent = $attributes['user_agent'];
        $this->timestamp = $attributes['timestamp'];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'data' => $this->data,
            'user_id' => $this->userId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'timestamp' => $this->timestamp->toIso8601String()
        ];
    }
}

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->auditCreate();
        });

        static::updated(function ($model) {
            $model->auditUpdate();
        });

        static::deleted(function ($model) {
            $model->auditDelete();
        });
    }

    protected function auditCreate(): void
    {
        $this->audit('create', $this->getAuditData());
    }

    protected function auditUpdate(): void
    {
        if ($this->wasChanged()) {
            $this->audit('update', [
                'old' => array_intersect_key($this->getOriginal(), $this->getDirty()),
                'new' => $this->getDirty()
            ]);
        }
    }

    protected function auditDelete(): void
    {
        $this->audit('delete', $this->getAuditData());
    }

    protected function audit(string $action, array $data = []): void
    {
        app(AuditServiceInterface::class)->log(
            $action,
            static::class,
            array_merge($data, ['id' => $this->getKey()])
        );
    }

    protected function getAuditData(): array
    {
        return $this->toArray();
    }
}

