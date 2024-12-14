<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Contracts\EncryptionInterface;

class MetricsStore
{
    private EncryptionInterface $encryption;
    private SecurityManager $security;
    
    private const CACHE_PREFIX = 'metrics_';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        EncryptionInterface $encryption,
        SecurityManager $security
    ) {
        $this->encryption = $encryption;
        $this->security = $security;
    }

    public function store(string $metricsId, array $metrics, int $ttl = null): void
    {
        DB::beginTransaction();
        
        try {
            // Encrypt sensitive metrics
            $encryptedMetrics = $this->encryptMetrics($metrics);
            
            // Store in database
            $this->storeInDatabase($metricsId, $encryptedMetrics);
            
            // Cache for quick access
            $this->cacheMetrics($metricsId, $metrics, $ttl);
            
            // Update aggregations
            $this->updateAggregations($metrics);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MetricsStoreException('Failed to store metrics: ' . $e->getMessage(), $e);
        }
    }

    public function retrieve(string $metricsId): array
    {
        // Try cache first
        if ($cached = $this->retrieveFromCache($metricsId)) {
            return $cached;
        }
        
        // Load from database
        $stored = $this->retrieveFromDatabase($metricsId);
        if (!$stored) {
            throw new MetricsNotFoundException("Metrics not found: {$metricsId}");
        }
        
        // Decrypt and verify
        $metrics = $this->decryptMetrics($stored['metrics']);
        $this->verifyMetrics($metrics, $stored['signature']);
        
        // Cache for subsequent requests
        $this->cacheMetrics($metricsId, $metrics);
        
        return $metrics;
    }

    public function getAggregations(string $type, array $filters = []): array
    {
        $cacheKey = $this->getAggregationCacheKey($type, $filters);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($type, $filters) {
            return $this->loadAggregations($type, $filters);
        });
    }

    private function encryptMetrics(array $metrics): array
    {
        $sensitiveFields = config('metrics.sensitive_fields', []);
        
        foreach ($sensitiveFields as $field) {
            if (isset($metrics[$field])) {
                $metrics[$field] = $this->encryption->encrypt(json_encode($metrics[$field]));
            }
        }
        
        return $metrics;
    }

    private function decryptMetrics(array $metrics): array
    {
        $sensitiveFields = config('metrics.sensitive_fields', []);
        
        foreach ($sensitiveFields as $field) {
            if (isset($metrics[$field])) {
                $metrics[$field] = json_decode($this->encryption->decrypt($metrics[$field]), true);
            }
        }
        
        return $metrics;
    }

    private function storeInDatabase(string $metricsId, array $metrics): void
    {
        $signature = $this->generateSignature($metrics);
        
        DB::table('metrics')->insert([
            'metrics_id' => $metricsId,
            'metrics' => json_encode($metrics),
            'signature' => $signature,
            'created_at' => now(),
            'expires_at' => now()->addDays(config('metrics.retention_days', 30))
        ]);
    }

    private function cacheMetrics(string $metricsId, array $metrics, ?int $ttl = null): void
    {
        $cacheKey = self::CACHE_PREFIX . $metricsId;
        $ttl = $ttl ?? self::CACHE_TTL;
        
        Cache::put($cacheKey, $metrics, $ttl);
    }

    private function retrieveFromCache(string $metricsId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $metricsId;
        return Cache::get($cacheKey);
    }

    private function retrieveFromDatabase(string $metricsId): ?array
    {
        return DB::table('metrics')
            ->where('metrics_id', $metricsId)
            ->where('expires_at', '>', now())
            ->first(['metrics', 'signature']);
    }

    private function updateAggregations(array $metrics): void
    {
        $timestamp = floor($metrics['timestamp'] / 300) * 300; // 5-minute buckets
        
        DB::table('metrics_aggregations')
            ->where('timestamp', $timestamp)
            ->updateOrInsert(
                ['timestamp' => $timestamp],
                $this->calculateAggregations($metrics)
            );
    }

    private function calculateAggregations(array $metrics): array
    {
        return [
            'avg_response_time' => $metrics['performance']['response_time']['avg'],
            'max_response_time' => $metrics['performance']['response_time']['max'],
            'total_requests' => $metrics['performance']['throughput']['requests_per_second'],
            'error_rate' => $metrics['performance']['error_rate']['error_percentage'],
            'memory_usage' => $metrics['system']['memory'],
            'cpu_usage' => max($metrics['system']['cpu']),
            'updated_at' => now()
        ];
    }

    private function loadAggregations(string $type, array $filters): array
    {
        $query = DB::table('metrics_aggregations')
            ->where('timestamp', '>=', now()->subHours(24));
            
        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }
        
        return $query->get()->toArray();
    }

    private function generateSignature(array $metrics): string
    {
        return hash_hmac(
            'sha256',
            json_encode($metrics),
            config('metrics.signature_key')
        );
    }

    private function verifyMetrics(array $metrics, string $signature): void
    {
        $calculated = $this->generateSignature($metrics);
        
        if (!hash_equals($calculated, $signature)) {
            throw new MetricsIntegrityException('Metrics signature verification failed');
        }
    }

    private function getAggregationCacheKey(string $type, array $filters): string
    {
        return sprintf(
            'metrics_agg_%s_%s',
            $type,
            md5(json_encode($filters))
        );
    }
}
