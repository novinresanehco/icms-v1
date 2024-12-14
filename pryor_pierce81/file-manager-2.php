<?php

namespace App\Core\File;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\FileException;
use Psr\Log\LoggerInterface;

class FileManager implements FileManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function storeFile(string $path, $content, array $options = []): bool
    {
        $fileId = $this->generateFileId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('file:store', [
                'path' => $path
            ]);

            $this->validateFilePath($path);
            $this->validateFileContent($content);
            $this->validateStorageOptions($options);

            $success = $this->processFileStorage($path, $content, $options);
            $this->validateStoredFile($path);

            $this->logFileOperation($fileId, 'store', $path);

            DB::commit();
            return $success;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFileFailure($fileId, 'store', $e);
            throw new FileException('File storage failed', 0, $e);
        }
    }

    public function retrieveFile(string $path): string
    {
        try {
            $this->security->validateSecureOperation('file:retrieve', [
                'path' => $path
            ]);

            $this->validateFilePath($path);
            $this->validateFileAccess($path);

            $content = $this->loadFileContent($path);
            $this->validateFileContent($content);

            $this->logFileOperation($this->generateFileId(), 'retrieve', $path);

            return $content;

        } catch (\Exception $e) {
            $this->handleFileFailure($path, 'retrieve', $e);
            throw new FileException('File retrieval failed', 0, $e);
        }
    }

    public function deleteFile(string $path): bool
    {
        $fileId = $this->generateFileId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('file:delete', [
                'path' => $path
            ]);

            $this->validateFilePath($path);
            $this->validateFileDeletion($path);

            $success = $this->processFileDeletion($path);
            $this->logFileOperation($fileId, 'delete', $path);

            DB::commit();
            return $success;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFileFailure($fileId, 'delete', $e);
            throw new FileException('File deletion failed', 0, $e);
        }
    }

    private function validateFilePath(string $path): void
    {
        if (strlen($path) > $this->config['max_path_length']) {
            throw new FileException('File path exceeds maximum length');
        }

        if (!preg_match($this->config['path_pattern'], $path)) {
            throw new FileException('Invalid file path format');
        }

        if (!$this->isPathSecure($path)) {
            throw new FileException('Insecure file path detected');
        }
    }

    private function validateFileContent($content): void
    {
        if (empty($content)) {
            throw new FileException('Empty file content');
        }

        $size = strlen($content);
        if ($size > $this->config['max_file_size']) {
            throw new FileException('File content exceeds size limit');
        }

        if (!$this->isContentSecure($content)) {
            throw new FileException('Insecure file content detected');
        }
    }

    private function processFileStorage(string $path, $content, array $options): bool
    {
        $encryptedContent = $this->encryptFileContent($content);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return file_put_contents($path, $encryptedContent, LOCK_EX) !== false;
    }

    private function handleFileFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('File operation failed', [
            'file_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->notifyFileFailure($id, $operation, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_path_length' => 255,
            'max_file_size' => 52428800,
            'path_pattern' => '/^[a-zA-Z0-9\/_-]+$/',
            'encryption_enabled' => true,
            'secure_storage' => true,
            'storage_timeout' => 30
        ];
    }
}
