<?php

namespace App\Core\Versioning\Contracts;

interface VersionManagerInterface
{
    public function createVersion(Versionable $entity, array $data = []): Version;
    public function revertToVersion(string $versionId): bool;
    public function getVersions(Versionable $entity): Collection;
    public function compareVersions(string $versionId1, string $versionId2): array;
    public function getVersion(string $versionId): ?Version;
}

interface Versionable
{
    public function getCurrentVersion(): ?Version;
    public function getVersions(): Collection;
    public function toVersionedArray(): array;
}

namespace App\Core\Versioning\Services;

class VersionManager implements VersionManagerInterface
{
    protected VersionRepository $repository;
    protected DiffGenerator $diffGenerator;
    protected VersionSerializer $serializer;
    protected VersionValidator $validator;

    public function __construct(
        VersionRepository $repository,
        DiffGenerator $diffGenerator,
        VersionSerializer $serializer,
        VersionValidator $validator
    ) {
        $this->repository = $repository;
        $this->diffGenerator = $diffGenerator;
        $this->serializer = $serializer;
        $this->validator = $validator;
    }

    public function createVersion(Versionable $entity, array $data = []): Version
    {
        // Get current version
        $currentVersion = $entity->getCurrentVersion();

        // Create new version
        $version = new Version([
            'id' => Str::uuid(),
            'entity_type' => get_class($entity),
            'entity_id' => $entity->getKey(),
            'data' => $this->serializer->serialize($entity->toVersionedArray()),
            'metadata' => array_merge([
                'created_by' => auth()->id(),
                'created_at' => now()
            ], $data),
            'parent_id' => $currentVersion?->id
        ]);

        // Generate diff if there's a previous version
        if ($currentVersion) {
            $version->diff = $this->diffGenerator->generate(
                $currentVersion->data,
                $version->data
            );
        }

        // Validate version
        $this->validator->validate($version);

        // Save version
        $this->repository->save($version);

        return $version;
    }

    public function revertToVersion(string $versionId): bool
    {
        $version = $this->getVersion($versionId);
        
        if (!$version) {
            throw new VersionNotFoundException("Version {$versionId} not found");
        }

        // Create new version with reverted data
        $entity = $this->loadEntity($version->entity_type, $version->entity_id);
        
        return DB::transaction(function () use ($entity, $version) {
            // Update entity with version data
            $entity->fill($this->serializer->unserialize($version->data));
            $entity->save();

            // Create new version marking it as a revert
            $this->createVersion($entity, [
                'reverted_from' => $version->id,
                'type' => 'revert'
            ]);

            return true;
        });
    }

    public function getVersions(Versionable $entity): Collection
    {
        return $this->repository->getVersions(get_class($entity), $entity->getKey());
    }

    public function compareVersions(string $versionId1, string $versionId2): array
    {
        $version1 = $this->getVersion($versionId1);
        $version2 = $this->getVersion($versionId2);

        if (!$version1 || !$version2) {
            throw new VersionNotFoundException("One or both versions not found");
        }

        return $this->diffGenerator->compare(
            $version1->data,
            $version2->data
        );
    }

    public function getVersion(string $versionId): ?Version
    {
        return $this->repository->find($versionId);
    }

    protected function loadEntity(string $type, string $id)
    {
        return app($type)->findOrFail($id);
    }
}

namespace App\Core\Versioning\Services;

class DiffGenerator
{
    public function generate(array $oldData, array $newData): array
    {
        $diff = [];

        foreach ($newData as $key => $value) {
            if (!isset($oldData[$key])) {
                $diff[$key] = [
                    'type' => 'added',
                    'value' => $value
                ];
            } elseif ($oldData[$key] !== $value) {
                $diff[$key] = [
                    'type' => 'modified',
                    'old' => $oldData[$key],
                    'new' => $value
                ];
            }
        }

        foreach ($oldData as $key => $value) {
            if (!isset($newData[$key])) {
                $diff[$key] = [
                    'type' => 'deleted',
                    'value' => $value
                ];
            }
        }

        return $diff;
    }

    public function compare(array $data1, array $data2): array
    {
        return [
            'additions' => $this->findAdditions($data1, $data2),
            'deletions' => $this->findDeletions($data1, $data2),
            'modifications' => $this->findModifications($data1, $data2)
        ];
    }

    protected function findAdditions(array $data1, array $data2): array
    {
        return array_diff_key($data2, $data1);
    }

    protected function findDeletions(array $data1, array $data2): array
    {
        return array_diff_key($data1, $data2);
    }

    protected function findModifications(array $data1, array $data2): array
    {
        $modifications = [];

        foreach ($data1 as $key => $value) {
            if (isset($data2[$key]) && $data2[$key] !== $value) {
                $modifications[$key] = [
                    'old' => $value,
                    'new' => $data2[$key]
                ];
            }
        }

        return $modifications;
    }
}

namespace App\Core\Versioning\Models;

class Version extends Model
{
    protected $fillable = [
        'id',
        'entity_type',
        'entity_id',
        'data',
        'metadata',
        'diff',
        'parent_id'
    ];

    protected $casts = [
        'data' => 'array',
        'metadata' => 'array',
        'diff' => 'array'
    ];

    public function entity()
    {
        return $this->morphTo();
    }

    public function parent()
    {
        return $this->belongsTo(Version::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Version::class, 'parent_id');
    }
}

trait Versionable
{
    protected static function bootVersionable()
    {
        static::created(function ($model) {
            $model->createInitialVersion();
        });

        static::saved(function ($model) {
            if ($model->shouldCreateVersion()) {
                $model->createVersion();
            }
        });
    }

    public function versions()
    {
        return $this->morphMany(Version::class, 'entity');
    }

    public function getCurrentVersion(): ?Version
    {
        return $this->versions()->latest()->first();
    }

    public function getVersions(): Collection
    {
        return $this->versions()->orderBy('created_at', 'desc')->get();
    }

    public function createVersion(array $metadata = []): Version
    {
        return app(VersionManagerInterface::class)->createVersion($this, $metadata);
    }

    protected function createInitialVersion(): void
    {
        $this->createVersion(['type' => 'initial']);
    }

    protected function shouldCreateVersion(): bool
    {
        return $this->isDirty($this->getVersionedAttributes());
    }

    public function toVersionedArray(): array
    {
        return array_intersect_key(
            $this->toArray(),
            array_flip($this->getVersionedAttributes())
        );
    }

    protected function getVersionedAttributes(): array
    {
        return $this->versionedAttributes ?? ['*'];
    }
}
