<?php

namespace App\Core\Backup\Services;

use App\Core\Backup\Models\Backup;
use App\Core\Backup\Repositories\BackupRepository;
use Illuminate\Support\Facades\{Storage, DB};

class BackupService
{
    public function __construct(
        private BackupRepository $repository,
        private BackupValidator $validator,
        private BackupProcessor $processor
    ) {}

    public function create(array $data): Backup
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($data) {
            $backup = $this->repository->create([
                'name' => $data['name'],
                'type' => $data['type'],
                'status' => 'pending',
                'options' => $data['options'] ?? []
            ]);

            $this->processor->process($backup);
            return $backup;
        });
    }

    public function download(Backup $backup): string
    {
        if (!$backup->isCompleted()) {
            throw new BackupException('Backup is not ready for download');
        }

        return Storage::disk($backup->disk)->temporaryUrl(
            $backup->file_path,
            now()->addMinutes(30)
        );
    }

    public function delete(Backup $backup): bool
    {
        return DB::transaction(function () use ($backup) {
            if ($backup->file_path && Storage::disk($backup->disk)->exists($backup->file_path)) {
                Storage::disk($backup->disk)->delete($backup->file_path);
            }

            return $this->repository->delete($backup);
        });
    }

    public function restore(Backup $backup): bool
    {
        if (!$backup->isCompleted()) {
            throw new BackupException('Cannot restore incomplete backup');
        }

        return $this->processor->restore($backup);
    }

    public function listBackups(array $filters = []): Collection
    {
        return $this->repository->getWithFilters($filters);
    }

    public function getLatestBackup(string $type = null): ?Backup
    {
        return $this->repository->getLatestBackup($type);
    }

    public function cleanupOldBackups(int $keepLast = 10): int
    {
        $oldBackups = $this->repository->getOldBackups($keepLast);
        $count = 0;

        foreach ($oldBackups as $backup) {
            if ($this->delete($backup)) {
                $count++;
            }
        }

        return $count;
    }

    public function verify(Backup $backup): bool
    {
        return $this->processor->verify($backup);
    }

    public function getStats(): array
    {
        return $this->repository->getStats();
    }
}
