<?php

namespace App\Core\Protection;

use Illuminate\Support\Facades\Config;

class StorageConfig
{
    private array $config;
    private array $backupStrategies;
    private array $storageThresholds;
    private array $retentionPolicies;

    public function __construct()
    {
        $this->config = Config::get('storage');
        $this->backupStrategies = $this->initializeBackupStrategies();
        $this->storageThresholds = $this->initializeStorageThresholds();
        $this->retentionPolicies = $this->initializeRetentionPolicies();
    }

    public function getPrimaryStoragePath(): string
    {
        return $this->config['paths']['primary'];
    }

    public function getBackupStoragePath(): string
    {
        return $this->config['paths']['backup'];
    }

    public function getArchiveStoragePath(): string
    {
        return $this->config['paths']['archive'];
    }

    public function getTemporaryStoragePath(): string
    {
        return $this->config['paths']['temporary'];
    }

    public function getBackupStrategies(): array
    {
        return $this->backupStrategies;
    }

    public function getStorageThresholds(): array
    {
        return $this->storageThresholds;
    }

    public function getRetentionPolicies(): array
    {
        return $this->retentionPolicies;
    }

    public function getEncryptionKey(): string
    {
        return $this->config['encryption']['key'];
    }

    public function getEncryptionAlgorithm(): string
    {
        return $this->config['encryption']['algorithm'];
    }

    public function getCompressionLevel(): int
    {
        return $this->config['compression']['level'];
    }

    public function getBackupFrequency(): int
    {
        return $this->config['backup']['frequency'];
    }

    public function getMaxBackupSize(): int
    {
        return $this->config['backup']['max_size'];
    }

    public function getMaxRetentionPeriod(): int
    {
        return $this->config['retention']['max_period'];
    }

    public function getMinFreeSpace(): int
    {
        return $this->config['thresholds']['min_free_space'];
    }

    public function getCriticalSpaceThreshold(): int
    {
        return $this->config['thresholds']['critical_space'];
    }

    public function getMaxFileSize(): int
    {
        return $this->config['limits']['max_file_size'];
    }

    public function getAllowedFileTypes(): array
    {
        return $this->config['security']['allowed_types'];
    }

    public function getBlockedFileTypes(): array
    {
        return $this->config['security']['blocked_types'];
    }

    public function getVirusScanEnabled(): bool
    {
        return $this->config['security']['virus_scan'];
    }

    public function getQuarantinePath(): string
    {
        return $this->config['security']['quarantine_path'];
    }

    private function initializeBackupStrategies(): array
    {
        return [
            new BackupStrategy(
                'full',
                $this->config['backup']['strategies']['full']
            ),
            new BackupStrategy(
                'incremental',
                $this->config['backup']['strategies']['incremental']
            ),
            new BackupStrategy(
                'differential',
                $this->config['backup']['strategies']['differential']
            )
        ];
    }

    private function initializeStorageThresholds(): array
    {
        return [
            'space_usage' => [
                'warning' => 80,
                'critical' => 90
            ],
            'inode_usage' => [
                'warning' => 85,
                'critical' => 95
            ],
            'backup_size' => [
                'warning' => $this->getMaxBackupSize() * 0.8,
                'critical' => $this->getMaxBackupSize() * 0.95
            ],
            'file_count' => [
                'warning' => 1000000,
                'critical' => 2000000
            ],
            'retention_period' => [
                'warning' => $this->getMaxRetentionPeriod() * 0.8,
                'critical' => $this->getMaxRetentionPeriod() * 0.95
            ]
        ];
    }

    private function initializeRetentionPolicies(): array
    {
        return [
            new RetentionPolicy(
                'critical',
                $this->config['retention']['policies']['critical']
            ),
            new RetentionPolicy(
                'important',
                $this->config['retention']['policies']['important']
            ),
            new RetentionPolicy(
                'normal',
                $this->config['retention']['policies']['normal']
            )
        ];
    }
}

class BackupStrategy
{
    private string $name;
    private array $config;

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->config = $config;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getInterval(): int
    {
        return $this->config['interval'];
    }

    public function getRetention(): int
    {
        return $this->config['retention'];
    }

    public function getCompression(): bool
    {
        return $this->config['compression'];
    }

    public function getEncryption(): bool
    {
        return $this->config['encryption'];
    }

    public function getValidationRequired(): bool
    {
        return $this->config['validation_required'];
    }

    public function getStorageQuota(): int
    {
        return $this->config['storage_quota'];
    }
}

class RetentionPolicy
{
    private string $name;
    private array $config;

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->config = $config;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getRetentionPeriod(): int
    {
        return $this->config['retention_period'];
    }

    public function getBackupFrequency(): int
    {
        return $this->config['backup_frequency'];
    }

    public function getMinimumBackups(): int
    {
        return $this->config['minimum_backups'];
    }

    public function getMaximumBackups(): int
    {
        return $this->config['maximum_backups'];
    }

    public function getValidationFrequency(): int
    {
        return $this->config['validation_frequency'];
    }

    public function getArchiveAfter(): int
    {
        return $this->config['archive_after'];
    }

    public function getDeleteAfter(): int
    {
        return $this->config['delete_after'];
    }
}
