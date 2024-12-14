<?php

namespace App\Core\Deployment;

final class VersionControl
{
    private SecurityManager $security;
    private ValidationService $validator;
    private FileSystem $filesystem;
    private AuditService $audit;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        FileSystem $filesystem,
        AuditService $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->filesystem = $filesystem;
        $this->audit = $audit;
    }

    public function isCompatibleRelease(Release $release): bool
    {
        // Get current version
        $currentVersion = $this->getCurrentVersion();
        
        // Check version compatibility
        if (!$this->validator->isVersionCompatible($currentVersion, $release->getVersion())) {
            return false;
        }

        // Check database compatibility
        if (!$this->validator->isDatabaseCompatible($release->getDatabaseVersion())) {
            return false;
        }

        // Check dependency compatibility
        if (!$this->validator->areDependenciesCompatible($release->getDependencies())) {
            return false;
        }

        return true;
    }

    public function updateCodebase(ReleaseCode $code): void
    {
        // Start code update transaction
        $transactionId = $this->startCodeTransaction();
        
        try {
            // Verify code integrity
            if (!$this->verifyCodeIntegrity($code)) {
                throw new IntegrityException('Code integrity check failed');
            }

            // Create backup
            $backupId = $this->backupCurrentCode();

            try {
                // Update files
                $this->updateFiles($code->getFiles());
                
                // Update dependencies
                $this->updateDependencies($code->getDependencies());
                
                // Verify file system
                $this->verifyFileSystem();
                
            } catch (\Throwable $e) {
                // Restore from backup
                $this->restoreCodeBackup($backupId);
                throw $e;
            }

            // Complete transaction
            $this->completeCodeTransaction($transactionId);
            
        } catch (\Throwable $e) {
            // Rollback transaction
            $this->rollbackCodeTransaction($transactionId);
            throw $e;
        }
    }

    public function updateVersion(Version $version): void
    {
        // Validate version
        if (!$this->validator->validateVersion($version)) {
            throw new ValidationException('Invalid version format');
        }

        // Update version file
        $this->filesystem->put('version.json', json_encode([
            'version' => $version->toString(),
            'updated_at' => time(),
            'hash' => $this->calculateCodeHash()
        ]));

        // Log version update
        $this->audit->logVersionUpdate($version);
    }

    private function verifyCodeIntegrity(ReleaseCode $code): bool
    {
        // Verify code signature
        if (!$this->security->verifyCodeSignature($code)) {
            return false;
        }

        // Verify file checksums
        if (!$this->verifyFileChecksums($code->getFiles())) {
            return false;
        }

        // Verify dependencies
        if (!$this->verifyDependencies($code->getDependencies())) {
            return false;
        }

        return true;
    }

    private function verifyFileChecksums(array $files): bool
    {
        foreach ($files as $file) {
            if (!$this->verifyFileChecksum($file)) {
                return false;
            }
        }
        return true;
    }

    private function verifyFileChecksum(File $file): bool
    {
        return hash_equals(
            $file->getChecksum(),
            hash_file('sha256', $file->getPath())
        );
    }

    private function backupCurrentCode(): string
    {
        $backupId = uniqid('backup_', true);
        
        // Create backup directory
        $backupPath = storage_path("backups/code/{$backupId}");
        $this->filesystem->makeDirectory($backupPath, 0755, true);
        
        // Copy current codebase
        $this->filesystem->copyDirectory(base_path(), $backupPath);
        
        return $backupId;
    }

    private function restoreCodeBackup(string $backupId): void
    {
        $backupPath = storage_path("backups/code/{$backupId}");
        
        // Verify backup exists
        if (!$this->filesystem->exists($backupPath)) {
            throw new BackupException('Backup not found');
        }

        // Restore files
        $this->filesystem->cleanDirectory(base_path());
        $this->filesystem->copyDirectory($backupPath, base_path());
    }

    private function verifyFileSystem(): void
    {
        // Verify file permissions
        if (!$this->validator->verifyFilePermissions()) {
            throw new SecurityException('File permission verification failed');
        }

        // Verify file ownership
        if (!$this->validator->verifyFileOwnership()) {
            throw new SecurityException('File ownership verification failed');
        }

        // Verify directory structure
        if (!$this->validator->verifyDirectoryStructure()) {
            throw new FileSystemException('Directory structure verification failed');
        }
    }
}
