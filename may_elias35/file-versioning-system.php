// File: app/Core/File/Version/VersionManager.php
<?php

namespace App\Core\File\Version;

class VersionManager
{
    protected VersionRepository $repository;
    protected StorageManager $storage;
    protected DiffGenerator $diffGenerator;

    public function createVersion(File $file): Version
    {
        $latestVersion = $this->repository->getLatestVersion($file->getId());
        $diff = null;

        if ($latestVersion) {
            $diff = $this->diffGenerator->generate($latestVersion->getPath(), $file->getPath());
        }

        return $this->repository->create([
            'file_id' => $file->getId(),
            'version' => $this->generateVersionNumber($latestVersion),
            'path' => $file->getPath(),
            'diff' => $diff,
            'created_by' => auth()->id(),
            'created_at' => now()
        ]);
    }

    public function restore(File $file, int $versionId): File
    {
        $version = $this->repository->find($versionId);
        
        if (!$version || $version->getFileId() !== $file->getId()) {
            throw new VersionNotFoundException();
        }

        $restoredPath = $this->storage->restore($version);
        $file->setPath($restoredPath);
        
        return $file;
    }
}
