<?php

namespace App\Core\Versioning;

class VersionManager
{
    private VersionRepository $repository;
    private DiffGenerator $diffGenerator;
    private MergeResolver $mergeResolver;
    private LockManager $lockManager;

    public function createVersion(Versionable $entity): Version
    {
        $lock = $this->lockManager->acquire($entity->getVersionableId());

        try {
            $previousVersion = $this->repository->getLatestVersion($entity);
            $changes = $this->diffGenerator->generateDiff($previousVersion, $entity);
            
            $version = new Version(
                $entity,
                $changes,
                $previousVersion?->getNumber() ?? 0
            );

            $this->repository->save($version);
            return $version;

        } finally {
            $this->lockManager->release($lock);
        }
    }

    public function getVersion(Versionable $entity, int $number): ?Version
    {
        return $this->repository->findVersion($entity, $number);
    }

    public function revertTo(Versionable $entity, int $number): void
    {
        $lock = $this->lockManager->acquire($entity->getVersionableId());

        try {
            $targetVersion = $this->repository->findVersion($entity, $number);
            if (!$targetVersion) {
                throw new VersionException("Version not found: $number");
            }

            $this->repository->revertTo($entity, $targetVersion);
        } finally {
            $this->lockManager->release($lock);
        }
    }

    public function merge(Version $source, Version $target): Version
    {
        $mergeResult = $this->mergeResolver->merge($source, $target);
        
        if ($mergeResult->hasConflicts()) {
            throw new MergeConflictException($mergeResult->getConflicts());
        }

        return $this->createVersion($mergeResult->getEntity());
    }
}

interface Versionable
{
    public function getVersionableId(): string;
    public function getVersionableType(): string;
    public function getVersionableData(): array;
}

class Version
{
    private string $id;
    private Versionable $entity;
    private array $changes;
    private int $number;
    private \DateTime $createdAt;

    public function __construct(Versionable $entity, array $changes, int $previousNumber)
    {
        $this->id = uniqid('ver_', true);
        $this->entity = $entity;
        $this->changes = $changes;
        $this->number = $previousNumber + 1;
        $this->createdAt = new \DateTime();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEntity(): Versionable
    {
        return $this->entity;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }
}

class DiffGenerator
{
    public function generateDiff(?Version $previous, Versionable $current): array
    {
        if (!$previous) {
            return ['type' => 'create', 'data' => $current->getVersionableData()];
        }

        $previousData = $previous->getEntity()->getVersionableData();
        $currentData = $current->getVersionableData();
        
        return [
            'type' => 'update',
            'changes' => $this->compareData($previousData, $currentData)
        ];
    }

    private function compareData(array $old, array $new): array
    {
        $changes = [];

        foreach ($new as $key => $value) {
            if (!isset($old[$key])) {
                $changes[$key] = ['type' => 'add', 'value' => $value];
            } elseif ($old[$key] !== $value) {
                $changes[$key] = [
                    'type' => 'modify',
                    'old' => $old[$key],
                    'new' => $value
                ];
            }
        }

        foreach ($old as $key => $value) {
            if (!isset($new[$key])) {
                $changes[$key] = ['type' => 'remove', 'value' => $value];
            }
        }

        return $changes;
    }
}

class MergeResolver
{
    public function merge(Version $source, Version $target): MergeResult
    {
        $sourceData = $source->getEntity()->getVersionableData();
        $targetData = $target->getEntity()->getVersionableData();
        
        $conflicts = $this->findConflicts($source, $target);
        
        if (!empty($conflicts)) {
            return new MergeResult(null, $conflicts);
        }

        $mergedData = $this->mergeData($sourceData, $targetData);
        $entity = $this->createEntityFromData($target->getEntity(), $mergedData);

        return new MergeResult($entity);
    }

    private function findConflicts(Version $source, Version $target): array
    {
        $conflicts = [];
        $sourceChanges = $source->getChanges();
        $targetChanges = $target->getChanges();

        foreach ($sourceChanges as $key => $change) {
            if (isset($targetChanges[$key]) && 
                $targetChanges[$key]['type'] === 'modify' &&
                $change['type'] === 'modify' &&
                $change['new'] !== $targetChanges[$key]['new']) {
                $conflicts[$key] = [
                    'source' => $change,
                    'target' => $targetChanges[$key]
                ];
            }
        }

        return $conflicts;
    }

    private function mergeData(array $source, array $target): array
    {
        $merged = $target;

        foreach ($source as $key => $value) {
            if (!isset($target[$key])) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}

class MergeResult
{
    private ?Versionable $entity;
    private array $conflicts;

    public function __construct(?Versionable $entity, array $conflicts = [])
    {
        $this->entity = $entity;
        $this->conflicts = $conflicts;
    }

    public function getEntity(): ?Versionable
    {
        return $this->entity;
    }

    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    public function hasConflicts(): bool
    {
        return !empty($this->conflicts);
    }
}

class VersionRepository
{
    private $connection;

    public function save(Version $version): void
    {
        $this->connection->table('versions')->insert([
            'id' => $version->getId(),
            'entity_id' => $version->getEntity()->getVersionableId(),
            'entity_type' => $version->getEntity()->getVersionableType(),
            'number' => $version->getNumber(),
            'changes' => json_encode($version->getChanges()),
            'created_at' => $version->getCreatedAt()
        ]);
    }

    public function getLatestVersion(Versionable $entity): ?Version
    {
        $row = $this->connection->table('versions')
            ->where('entity_id', $entity->getVersionableId())
            ->where('entity_type', $entity->getVersionableType())
            ->orderBy('number', 'desc')
            ->first();

        return $row ? $this->hydrateVersion($row) : null;
    }

    public function findVersion(Versionable $entity, int $number): ?Version
    {
        $row = $this->connection->table('versions')
            ->where('entity_id', $entity->getVersionableId())
            ->where('entity_type', $entity->getVersionableType())
            ->where('number', $number)
            ->first();

        return $row ? $this->hydrateVersion($row) : null;
    }

    private function hydrateVersion($row): Version
    {
        // Implementation depends on how entity is stored/retrieved
        return new Version(
            $this->hydrateEntity($row),
            json_decode($row->changes, true),
            $row->number - 1
        );
    }
}
