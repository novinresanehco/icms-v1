<?php

namespace App\Core\Events\Versioning;

class EventVersionManager
{
    private SchemaVersionStore $versionStore;
    private array $versionedSchemas = [];
    private EventUpgrader $upgrader;

    public function __construct(
        SchemaVersionStore $versionStore,
        EventUpgrader $upgrader
    ) {
        $this->versionStore = $versionStore;
        $this->upgrader = $upgrader;
    }

    public function registerSchema(string $eventType, string $version, array $schema): void
    {
        if (!isset($this->versionedSchemas[$eventType])) {
            $this->versionedSchemas[$eventType] = [];
        }
        $this->versionedSchemas[$eventType][$version] = $schema;
    }

    public function validateEvent(Event $event): bool
    {
        $schema = $this->getCurrentSchema(get_class($event));
        return $schema->validate($event);
    }

    public function upgradeEvent(Event $event): Event
    {
        $currentVersion = $event->getSchemaVersion();
        $latestVersion = $this->getLatestVersion(get_class($event));

        if ($currentVersion === $latestVersion) {
            return $event;
        }

        return $this->upgrader->upgrade(
            $event,
            $currentVersion,
            $latestVersion,
            $this->versionedSchemas[get_class($event)]
        );
    }

    private function getCurrentSchema(string $eventType): EventSchema
    {
        $version = $this->getLatestVersion($eventType);
        return new EventSchema(
            $this->versionedSchemas[$eventType][$version],
            $version
        );
    }

    private function getLatestVersion(string $eventType): string
    {
        if (!isset($this->versionedSchemas[$eventType])) {
            throw new SchemaNotFoundException("No schema found for event type: {$eventType}");
        }

        return array_key_last($this->versionedSchemas[$eventType]);
    }
}

class SchemaVersionStore
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function saveVersion(string $eventType, string $version, array $schema): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_schemas (event_type, version, schema, created_at) 
             VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $eventType,
            $version,
            json_encode($schema),
            date('Y-m-d H:i:s')
        ]);
    }

    public function getVersions(string $eventType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT version, schema FROM event_schemas 
             WHERE event_type = ? 
             ORDER BY created_at ASC'
        );

        $stmt->execute([$eventType]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

class EventUpgrader
{
    private array $upgradeStrategies = [];

    public function registerStrategy(string $fromVersion, string $toVersion, UpgradeStrategy $strategy): void
    {
        $this->upgradeStrategies["{$fromVersion}->{$toVersion}"] = $strategy;
    }

    public function upgrade(Event $event, string $fromVersion, string $toVersion, array $schemas): Event
    {
        $path = $this->findUpgradePath($fromVersion, $toVersion);
        $upgradedEvent = $event;

        foreach ($path as $step) {
            $strategy = $this->getStrategy($step['from'], $step['to']);
            $upgradedEvent = $strategy->upgrade($upgradedEvent, $schemas[$step['to']]);
        }

        return $upgradedEvent;
    }

    private function findUpgradePath(string $fromVersion, string $toVersion): array
    {
        // Implementation for finding optimal upgrade path
        // This could use a graph algorithm to find shortest path
        return [];
    }

    private function getStrategy(string $fromVersion, string $toVersion): UpgradeStrategy
    {
        $key = "{$fromVersion}->{$toVersion}";
        if (!isset($this->upgradeStrategies[$key])) {
            throw new UpgradeStrategyNotFoundException(
                "No upgrade strategy found for {$fromVersion} to {$toVersion}"
            );
        }
        return $this->upgradeStrategies[$key];
    }
}

interface UpgradeStrategy
{
    public function upgrade(Event $event, array $targetSchema): Event;
}

class UpgradeStrategyNotFoundException extends \Exception {}
