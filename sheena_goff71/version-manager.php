namespace App\Core\Version;

class VersionManager implements VersionInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private DiffGenerator $differ;
    private StorageManager $storage;
    private AuditLogger $logger;
    private array $config;

    public function createVersion(Content $content, array $metadata = []): Version
    {
        return $this->security->executeCriticalOperation(
            new CreateVersionOperation($content),
            function() use ($content, $metadata) {
                // Validate content
                $this->validateContent($content);
                
                // Generate version data
                $versionData = $this->generateVersionData($content);
                
                // Calculate diff from previous
                $diff = $this->calculateDiff($content);
                
                // Create version record
                $version = $this->storeVersion($content, $versionData, $diff, $metadata);
                
                // Store version files
                $this->storeVersionFiles($content, $version);
                
                // Clean old versions if needed
                $this->cleanOldVersions($content);
                
                return $version;
            }
        );
    }

    public function restoreVersion(int $versionId): Content
    {
        return $this->security->executeCriticalOperation(
            new RestoreVersionOperation($versionId),
            function() use ($versionId) {
                // Load version
                $version = $this->loadVersion($versionId);
                
                // Validate version
                $this->validateVersion($version);
                
                // Create restore point
                $restorePoint = $this->createRestorePoint($version->content);
                
                try {
                    // Restore content
                    $restored = $this->restoreContent($version);
                    
                    // Verify restoration
                    $this->verifyRestoration($restored, $version);
                    
                    // Create new version
                    $this->createVersion($restored, [
                        'restored_from' => $version->id,
                        'restore_point' => $restorePoint->id
                    ]);
                    
                    return $restored;
                    
                } catch (\Exception $e) {
                    // Rollback to restore point
                    $this->rollbackToRestorePoint($restorePoint);
                    throw $e;
                }
            }
        );
    }

    public function compareVersions(int $fromId, int $toId): VersionDiff
    {
        return $this->security->executeCriticalOperation(
            new CompareVersionsOperation($fromId, $toId),
            function() use ($fromId, $toId) {
                // Load versions
                $from = $this->loadVersion($fromId);
                $to = $this->loadVersion($toId);
                
                // Validate versions
                $this->validateVersionPair($from, $to);
                
                // Generate diff
                return $this->generateDiff($from, $to);
            }
        );
    }

    protected function validateContent(Content $content): void
    {
        if (!$this->validator->isValidContent($content)) {
            throw new InvalidContentException();
        }

        if (!$this->validator->canCreateVersion($content)) {
            throw new VersioningNotAllowedException();
        }
    }

    protected function generateVersionData(Content $content): array
    {
        return [
            'content_hash' => $this->generateHash($content),
            'content_state' => $this->serializeState($content),
            'version_number' => $this->getNextVersionNumber($content),
            'created_by' => auth()->id(),
            'created_at' => now(),
            'metadata' => $this->collectMetadata($content)
        ];
    }

    protected function calculateDiff(Content $content): array
    {
        $previous = $this->getPreviousVersion($content);
        
        if (!$previous) {
            return ['type' => 'full', 'data' => $content->toArray()];
        }
        
        return $this->differ->generate(
            $previous->content_state,
            $this->serializeState($content)
        );
    }

    protected function storeVersion(
        Content $content,
        array $versionData,
        array $diff,
        array $metadata
    ): Version {
        return DB::transaction(function() use ($content, $versionData, $diff, $metadata) {
            $version = new Version(array_merge(
                $versionData,
                ['diff' => $diff],
                $metadata
            ));
            
            $content->versions()->save($version);
            
            return $version;
        });
    }

    protected function storeVersionFiles(Content $content, Version $version): void
    {
        foreach ($content->files as $file) {
            $path = $this->generateVersionPath($version, $file);
            $this->storage->copy($file->path, $path);
            $version->files()->create([
                'original_path' => $file->path,
                'version_path' => $path,
                'file_hash' => hash_file('sha256', $file->path)
            ]);
        }
    }

    protected function cleanOldVersions(Content $content): void
    {
        if ($this->shouldCleanVersions($content)) {
            $this->removeOldVersions($content);
        }
    }

    protected function loadVersion(int $versionId): Version
    {
        $version = Version::with('content')->findOrFail($versionId);
        
        if (!$this->validator->isValidVersion($version)) {
            throw new InvalidVersionException();
        }
        
        return $version;
    }

    protected function validateVersion(Version $version): void
    {
        if (!$version->canRestore()) {
            throw new CannotRestoreVersionException();
        }

        if (!$this->security->can('restore', $version)) {
            throw new UnauthorizedVersionRestoreException();
        }
    }

    protected function validateVersionPair(Version $from, Version $to): void
    {
        if ($from->content_id !== $to->content_id) {
            throw new InvalidVersionComparisonException();
        }
    }

    protected function generateDiff(Version $from, Version $to): VersionDiff
    {
        return new VersionDiff(
            $this->differ->compare($from, $to),
            [
                'from' => $from->version_number,
                'to' => $to->version_number,
                'created_at' => now()
            ]
        );
    }

    protected function rollbackToRestorePoint(RestorePoint $point): void
    {
        DB::transaction(function() use ($point) {
            $point->content->update($point->content_state);
            foreach ($point->files as $file) {
                $this->storage->move($file->backup_path, $file->original_path);
            }
        });
    }

    protected function generateHash(Content $content): string
    {
        return hash('sha256', serialize($this->serializeState($content)));
    }

    protected function getNextVersionNumber(Content $content): int
    {
        return $content->versions()->max('version_number') + 1;
    }
}
