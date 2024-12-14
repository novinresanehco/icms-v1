```php
namespace App\Core\Version;

class VersionManager implements VersionInterface
{
    private SecurityManager $security;
    private StorageManager $storage;
    private DiffGenerator $differ;
    private AuditLogger $audit;

    public function createVersion(Model $model): Version
    {
        return $this->security->executeProtected(function() use ($model) {
            // Create version snapshot
            $data = $this->createSnapshot($model);
            
            // Store with security checks
            $version = new Version([
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'data' => $data,
                'hash' => $this->generateHash($data),
                'created_by' => auth()->id()
            ]);

            $this->storage->storeVersion($version);
            $this->audit->logVersionCreated($version);
            
            return $version;
        });
    }

    private function createSnapshot(Model $model): array
    {
        return [
            'attributes' => $model->getAttributes(),
            'relations' => $this->snapshotRelations($model),
            'metadata' => $this->collectMetadata($model)
        ];
    }

    public function restore(Version $version): Model
    {
        return $this->security->executeProtected(function() use ($version) {
            // Validate version integrity
            $this->validateVersion($version);
            
            // Create restore point
            $restorePoint = $this->createRestorePoint();
            
            try {
                $model = $this->performRestore($version);
                $this->audit->logVersionRestored($version);
                return $model;
            } catch (\Exception $e) {
                $this->rollbackToPoint($restorePoint);
                throw $e;
            }
        });
    }

    private function validateVersion(Version $version): void
    {
        if (!$this->verifyHash($version)) {
            throw new VersionIntegrityException();
        }
    }
}

class DiffGenerator
{
    private SecurityManager $security;
    
    public function generateDiff(array $old, array $new): array
    {
        return $this->security->executeProtected(function() use ($old, $new) {
            return [
                'added' => $this->findAdded($old, $new),
                'removed' => $this->findRemoved($old, $new),
                'modified' => $this->findModified($old, $new)
            ];
        });
    }

    private function findModified(array $old, array $new): array
    {
        $modified = [];
        foreach ($old as $key => $value) {
            if (isset($new[$key]) && $new[$key] !== $value) {
                $modified[$key] = [
                    'from' => $value,
                    'to' => $new[$key]
                ];
            }
        }
        return $modified;
    }
}

class Version extends Model
{
    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime'
    ];

    public function versionable()
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getDiff(Version $other): array
    {
        return app(DiffGenerator::class)->generateDiff(
            $this->data,
            $other->data
        );
    }
}

trait Versionable
{
    public function versions()
    {
        return $this->morphMany(Version::class, 'versionable');
    }

    public function createVersion(): Version
    {
        return app(VersionManager::class)->createVersion($this);
    }

    public function restoreVersion(Version $version): self
    {
        return app(VersionManager::class)->restore($version);
    }
    
    protected static function bootVersionable()
    {
        static::created(function($model) {
            $model->createVersion();
        });

        static::updated(function($model) {
            $model->createVersion();
        });
    }
}
```
