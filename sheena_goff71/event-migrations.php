<?php

namespace App\Core\Events\Migration;

class EventMigrationManager
{
    private array $migrations = [];
    private EventStore $eventStore;
    private MigrationLogger $logger;
    private SchemaRegistry $schemaRegistry;
    
    public function __construct(
        EventStore $eventStore,
        MigrationLogger $logger,
        SchemaRegistry $schemaRegistry
    ) {
        $this->eventStore = $eventStore;
        $this->logger = $logger;
        $this->schemaRegistry = $schemaRegistry;
    }

    public function registerMigration(string $eventType, EventMigration $migration): void
    {
        $this->migrations[$eventType][] = $migration;
    }

    public function migrateEvents(MigrationOptions $options): MigrationResult
    {
        $result = new MigrationResult();
        $events = $this->eventStore->getEventsByType($options->getEventType());

        foreach ($events as $event) {
            try {
                $migratedEvent = $this->migrateEvent($event);
                $this->eventStore->updateEvent($event->getId(), $migratedEvent);
                $result->addSuccess($event);
            } catch (\Exception $e) {
                $result->addFailure($event, $e);
                if (!$options->shouldContinueOnError()) {
                    break;
                }
            }
        }

        $this->logger->logMigrationComplete($result);
        return $result;
    }

    private function migrateEvent(Event $event): Event
    {
        $eventType = get_class($event);
        if (!isset($this->migrations[$eventType])) {
            return $event;
        }

        $migratedEvent = $event;
        foreach ($this->migrations[$eventType] as $migration) {
            $migratedEvent = $migration->migrate($migratedEvent);
            $this->validateSchema($migratedEvent);
        }

        return $migratedEvent;
    }

    private function validateSchema(Event $event): void
    {
        $schema = $this->schemaRegistry->getSchema(get_class($event));
        if (!$schema->validate($event)) {
            throw new SchemaMigrationException("Event fails schema validation");
        }
    }
}

interface EventMigration
{
    public function migrate(Event $event): Event;
    public function getVersion(): string;
}

class MigrationOptions
{
    private string $eventType;
    private bool $continueOnError = false;
    private ?\DateTimeInterface $cutoffDate = null;

    public function __construct(string $eventType)
    {
        $this->eventType = $eventType;
    }

    public function setContinueOnError(bool $continue): self
    {
        $this->continueOnError = $continue;
        return $this;
    }

    public function setCutoffDate(\DateTimeInterface $date): self
    {
        $this->cutoffDate = $date;
        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function shouldContinueOnError(): bool
    {
        return $this->continueOnError;
    }

    public function getCutoffDate(): ?\DateTimeInterface
    {
        return $this->cutoffDate;
    }
}

class MigrationResult
{
    private array $successful = [];
    private array $failed = [];

    public function addSuccess(Event $event): void
    {
        $this->successful[] = $event;
    }

    public function addFailure(Event $event, \Exception $exception): void
    {
        $this->failed[] = [
            'event' => $event,
            'exception' => $exception
        ];
    }

    public function getSuccessCount(): int
    {
        return count($this->successful);
    }

    public function getFailureCount(): int
    {
        return count($this->failed);
    }

    public function getSuccessful(): array
    {
        return $this->successful;
    }

    public function getFailed(): array
    {
        return $this->failed;
    }
}

class SchemaRegistry
{
    private array $schemas = [];

    public function registerSchema(string $eventType, EventSchema $schema): void
    {
        $this->schemas[$eventType] = $schema;
    }

    public function getSchema(string $eventType): EventSchema
    {
        if (!isset($this->schemas[$eventType])) {
            throw new SchemaNotFoundException("No schema registered for event type: {$eventType}");
        }
        return $this->schemas[$eventType];
    }
}

class EventSchema
{
    private array $fields;
    private string $version;

    public function __construct(array $fields, string $version)
    {
        $this->fields = $fields;
        $this->version = $version;
    }

    public function validate(Event $event): bool
    {
        $data = $event->getData();
        foreach ($this->fields as $field => $rules) {
            if (!$this->validateField($data[$field] ?? null, $rules)) {
                return false;
            }
        }
        return true;
    }

    private function validateField($value, array $rules): bool
    {
        foreach ($rules as $rule => $constraint) {
            if (!$this->validateRule($value, $rule, $constraint)) {
                return false;
            }
        }
        return true;
    }

    private function validateRule($value, string $rule, $constraint): bool
    {
        return match($rule) {
            'type' => $this->validateType($value, $constraint),
            'required' => $constraint ? $value !== null : true,
            'min' => is_numeric($value) ? $value >= $constraint : true,
            'max' => is_numeric($value) ? $value <= $constraint : true,
            default => true
        };
    }

    private function validateType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => true
        };
    }
}

class MigrationLogger
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function logMigrationStart(MigrationOptions $options): void
    {
        $this->logger->info('Starting event migration', [
            'event_type' => $options->getEventType(),
            'cutoff_date' => $options->getCutoffDate()?->format('Y-m-d H:i:s')
        ]);
    }

    public function logMigrationComplete(MigrationResult $result): void
    {
        $this->logger->info('Event migration completed', [
            'successful' => $result->getSuccessCount(),
            'failed' => $result->getFailureCount(),
            'duration' => $this->calculateDuration()
        ]);

        if ($result->getFailureCount() > 0) {
            foreach ($result->getFailed() as $failure) {
                $this->logger->error('Migration failure', [
                    'event_id' => $failure['event']->getId(),
                    'error' => $failure['exception']->getMessage()
                ]);
            }
        }
    }

    private function calculateDuration(): float
    {
        // Implementation for duration calculation
        return 0.0;
    }
}

class SchemaMigrationException extends \Exception {}
class SchemaNotFoundException extends \Exception {}

interface LoggerInterface
{
    public function info(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}
