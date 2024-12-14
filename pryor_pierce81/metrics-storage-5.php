<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\StorageInterface;
use App\Core\Exceptions\{StorageException, IntegrityException};

class MetricsStore implements StorageInterface
{
    private EncryptionService $encryption;
    private IntegrityVerifier $verifier;
    private CompressionService $compression;
    private AlertSystem $alerts;

    public function __construct(
        EncryptionService $encryption,
        IntegrityVerifier $verifier,
        CompressionService $compression,
        AlertSystem $alerts
    ) {
        $this->encryption = $encryption;
        $this->verifier = $verifier;
        $this->compression = $compression;
        $this->alerts = $alerts;
    }

    public function store(string $metricsId, array $metrics, int $ttl): bool
    {
        DB::beginTransaction();

        try {
            // Verify data integrity
            $this->verifyIntegrity($metrics);
            
            // Prepare data for storage
            $prepared = $this->prepareForStorage($metrics);
            
            // Store with redundancy
            $this->storeWithRedundancy($metricsId, $prepared, $ttl);
            
            // Verify storage success
            $this->verifyStorage($metricsId, $metrics);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleStorageFailure($e, $metricsId, $metrics);
            throw new StorageException('Metrics storage failed', 0, $e);
        }
    }

    public function retrieve(string $metricsId): array
    {
        try {
            // Get from storage
            $data = $this->fetchFromStorage($metricsId);
            
            // Verify integrity
            $this->verifyIntegrity($data);
            
            // Decrypt and decompress
            return $this->prepareForRetrieval($data);

        } catch (\Exception $e) {
            $this->handleRetrievalFailure($e, $metricsId);
            throw new StorageException('Metrics retrieval failed', 0, $e);
        }
    }

    private function verifyIntegrity(array $data): void
    {
        if (!$this->verifier->verifyChecksum($data)) {
            throw new IntegrityException('Data integrity verification failed');
        }

        if (!$this->verifier->verifyStructure($data)) {
            throw new IntegrityException('Data structure verification failed');
        }
    }

    private function prepareForStorage(array $metrics): array
    {
        // Add metadata
        $prepared = $this->addMetadata($metrics);
        
        // Compress data
        $compressed = $this->compression->compress($prepared);
        
        // Encrypt data
        $encrypted = $this->encryption->encrypt($compressed);
        
        return [
            'data' => $encrypted,
            'checksum' => $this->verifier->generateChecksum($metrics),
            'metadata' => $this->generateMetadata($metrics)
        ];
    }

    private function storeWithRedundancy(string $metricsId, array $data, int $ttl): void
    {
        // Primary storage
        $this->storePrimary($metricsId, $data, $ttl);
        
        // Backup storage
        $this->storeBackup($metricsId, $data, $ttl);
        
        // Archive if needed
        if ($this->shouldArchive($data)) {
            $this->archiveMetrics($metricsId, $data);
        }
    }

    private function verifyStorage(string $metricsId, array $originalData): void
    {
        $stored = $this->fetchFromStorage($metricsId);
        
        if (!$this->verifier->compareData($originalData, $stored)) {
            throw new StorageException('Storage verification failed');
        }
    }

    private function addMetadata(array $metrics): array
    {
        return array_merge($metrics, [
            'stored_at' => now(),
            'version' => config('metrics.version'),
            'node_id' => config('app.node_id')
        ]);
    }

    private function generateMetadata(array $metrics): array
    {
        return [
            'size' => strlen(json_encode($metrics)),
            'checksum' => $this->verifier->generateChecksum($metrics),
            'compression_ratio' => $this->compression->getCompressionRatio(),
            'encryption_method' => $this->encryption->getMethod()
        ];
    }

    private function storePrimary(string $metricsId, array $data, int $ttl): void
    {
        DB::table('metrics_store')->insert([
            'metrics_id' => $metricsId,
            'data' => $data['data'],
            'metadata' => json_encode($data['metadata']),
            'checksum' => $data['checksum'],
            'expires_at' => now()->addSeconds($ttl)
        ]);
    }

    private function storeBackup(string $metricsId, array $data, int $ttl): void
    {
        DB::table('metrics_backup')->insert([
            'metrics_id' => $metricsId,
            'data' => $data['data'],
            'metadata' => json_encode($data['metadata']),
            'checksum' => $data['checksum'],
            'expires_at' => now()->addSeconds($ttl * 2)
        ]);
    }

    private function handleStorageFailure(\Exception $e, string $metricsId, array $metrics): void
    {
        $this->alerts->sendStorageAlert([
            'metrics_id' => $metricsId,
            'error' => $e->getMessage(),
            'metrics_size' => strlen(json_encode($metrics)),
            'timestamp' => now()
        ]);

        Log::error('Metrics storage failed', [
            'metrics_id' => $metricsId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleRetrievalFailure(\Exception $e, string $metricsId): void
    {
        $this->alerts->sendRetrievalAlert([
            'metrics_id' => $metricsId,
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);

        Log::error('Metrics retrieval failed', [
            'metrics_id' => $metricsId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
