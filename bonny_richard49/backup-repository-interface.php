<?php

namespace App\Core\Backup\Repository;

use App\Core\Backup\Models\Backup;
use App\Core\Backup\DTO\BackupData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface BackupRepositoryInterface extends RepositoryInterface
{
    /**
     * Create a new backup.
     *
     * @param BackupData $data
     * @return Backup
     */
    public function createBackup(BackupData $data): Backup;

    /**
     * Restore from backup.
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool;

    /**
     * Get backups by type.
     *
     * @param string $type
     * @return Collection
     */
    public function getByType(string $type): Collection;

    /**
     * Get latest backup.
     *
     * @param string|null $type
     * @return Backup|null
     */
    public function getLatest(?string $type = null): ?Backup;

    /**
     * Delete old backups.
     *
     * @param int $keepLast Number of recent backups to keep
     * @return int Number of backups deleted
     */
    public function cleanOldBackups(int $keepLast): int;

    /**
     * Download backup file.
     *
     * @param int $id
     * @return string File path
     */
    public function download(int $id): string;

    /**
     * Verify backup integrity.
     *
     * @param int $id
     * @return array Verification results
     */
    public function verifyIntegrity(int $id): array;

    /**
     * Get backup manifest.
     *
     * @param int $id
     * @return array
     */
    public function getManifest(int $id): array;

    /**
     * Schedule automatic backup.
     *
     * @param BackupData $data
     * @param string $schedule
     * @return bool
     */
    public function scheduleBackup(BackupData $data, string $schedule): bool;

    /**
     * Cancel scheduled backup.
     *
     * @param int $id
     * @return bool
     */
    public function cancelScheduledBackup(int $id): bool;

    /**
     * Get backup size.
     *
     * @param int $id
     * @return int Size in bytes
     */
    public function getBackupSize(int $id): int;

    /**
     * Get backup statistics.
     *
     * @return array
     */
    public function getStatistics(): array;
}
