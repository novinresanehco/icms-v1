<?php

namespace App\Core\Backup;

use Illuminate\Support\Facades\Storage;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\BackupManagerInterface;

class BackupManager implements BackupManagerInterface
{
    private SecurityManager $security;
    private EncryptionService $encryption;
    private IntegrityVerifier $verifier;
    private StorageManager $storage;
    private array $config;

    public function __construct(
        SecurityManager $security,
        EncryptionService $encryption,
        IntegrityVerifier $verifier,
        StorageManager $storage,
        array $config
    ) {
        $this->security = $security;
        $this->encryption = $encryption;
        $this->verifier = $verifier;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function createBackup(BackupRequest $request, SecurityContext $context): BackupResult
    {
        return $this->security->executeCriticalOperation(
            new CreateBackupOperation(
                $request,
                $this->encryption,
                $this->verifier,
                $this->storage,
                $this->config
            ),
            $context
        );
    }

    public function restore(
        string $backupId,
        RestoreOptions $options,
        SecurityContext $context
    ): RestoreResult {
        return $this->security->executeCriticalOperation(
            new RestoreOperation(
                $backupId,
                $options,
                $this->encryption,
                $this->verifier,
                $this->storage
            ),
            $context
        );
    }

    public function verify(string $backupId, SecurityContext $context): VerificationResult
    {
        return $this->security->executeCriticalOperation(
            new VerifyBackupOperation(
                $backupId,
                $this->verifier,
                $this->storage
            ),
            $context
        );
    }
}

class CreateBackupOperation implements CriticalOperation
{
    private BackupRequest $request;
    private EncryptionService $encryption;
    private IntegrityVerifier $verifier;
    private StorageManager $storage;
    private array $config;

    public function execute(): BackupResult
    {
        $backupId = $this->generateBackupId();
        $manifest = $this->createManifest($backupId);

        DB::beginTransaction();

        try {
            foreach ($this->request->getDataSources() as $source) {
                $data = $this->fetchData($source);
                $encrypted = $this->encryption->encrypt($data);
                $checksum = $this->verifier->generateChecksum($encrypted);
                
                $path = $this->storage->store(
                    $backupId,
                    $source->getName(),
                    $encrypted
                );

                $manifest->addFile($path, [
                    'source' => $source->getName(),
                    'checksum' => $checksum,
                    'size' => strlen($encrypted)
                ]);
            }

            $this->storeManifest($manifest);
            $this->verifyBackup($backupId);
            
            DB::commit();
            return new BackupResult($backupId, $manifest);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->cleanup($backupId);
            throw $e;
        }
    }

    private function generateBackupId(): string
    {
        return date('Ymd_His') . '_' . bin2hex(random_bytes(8));
    }

    private function createManifest(string $backupId): BackupManifest
    {
        return new BackupManifest([
            'id' => $backupId,
            'created_at' => time(),
            'type' => $this->request->getType(),
            'files' => []
        ]);
    }

    private function fetchData(DataSource $source): string
    {
        return match($source->getType()) {
            'database' => $this->fetchDatabaseData($source),
            'files' => $this->fetchFileData($source),
            default => throw new \InvalidArgumentException('Invalid source type')
        };
    }

    private function fetchDatabaseData(DataSource $source): string
    {
        $tables = $source->getTables();
        $options = $source->getOptions();

        return DB::connection($source->getConnection())
            ->dump($tables, $options);
    }

    private function fetchFileData(DataSource $source): string
    {
        $files = $source->getFiles();
        $archive = new ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'backup');

        $archive->open($tempFile, ZipArchive::CREATE);
        
        foreach ($files as $file) {
            $archive->addFile($file->getPath(), $file->getRelativePath());
        }
        
        $archive->close();
        return file_get_contents($tempFile);
    }

    private function storeManifest(BackupManifest $manifest): void
    {
        $encrypted = $this->encryption->encrypt($manifest->toJson());
        
        $this->storage->store(
            $manifest->getId(),
            'manifest.json',
            $encrypted
        );
    }

    private function verifyBackup(string $backupId): void
    {
        $manifest = $this->loadManifest($backupId);
        
        foreach ($manifest->getFiles() as $file) {
            $data = $this->storage->retrieve($backupId, $file['path']);
            $checksum = $this->verifier->generateChecksum($data);
            
            if ($checksum !== $file['checksum']) {
                throw new BackupVerificationException(
                    "Checksum mismatch for {$file['path']}"
                );
            }
        }
    }

    private function cleanup(string $backupId): void
    {
        $this->storage->deleteBackup($backupId);
    }
}

class RestoreOperation implements CriticalOperation
{
    private string $backupId;
    private RestoreOptions $options;
    private EncryptionService $encryption;
    private IntegrityVerifier $verifier;
    private StorageManager $storage;

    public function execute(): RestoreResult
    {
        $manifest = $this->loadManifest();
        $this->verifyBackup($manifest);

        DB::beginTransaction();

        try {
            foreach ($manifest->getFiles() as $file) {
                if (!$this->shouldRestore($file)) {
                    continue;
                }

                $encrypted = $this->storage->retrieve(
                    $this->backupId,
                    $file['path']
                );

                $data = $this->encryption->decrypt($encrypted);
                $this->restoreData($file['source'], $data);
            }

            DB::commit();
            return new RestoreResult($manifest);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function loadManifest(): BackupManifest
    {
        $encrypted = $this->storage->retrieve(
            $this->backupId,
            'manifest.json'
        );

        $data = $this->encryption->decrypt($encrypted);
        return BackupManifest::fromJson($data);
    }

    private function verifyBackup(BackupManifest $manifest): void
    {
        foreach ($manifest->getFiles() as $file) {
            $data = $this->storage->retrieve(
                $this->backupId,
                $file['path']
            );

            $checksum = $this->verifier->generateChecksum($data);
            
            if ($checksum !== $file['checksum']) {
                throw new RestoreException(
                    "Backup integrity check failed for {$file['path']}"
                );
            }
        }
    }

    private function shouldRestore(array $file): bool
    {
        if (empty($this->options->getSources())) {
            return true;
        }

        return in_array($file['source'], $this->options->getSources());
    }

    private function restoreData(string $source, string $data): void
    {
        match($this->getSourceType($source)) {
            'database' => $this->restoreDatabase($source, $data),
            'files' => $this->restoreFiles($source, $data),
            default => throw new \InvalidArgumentException('Invalid source type')
        };
    }
}
