<?php

namespace App\Core\Notification\Analytics\Enrichment;

class DataEnricher
{
    private array $enrichers = [];
    private array $cache = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'cache_ttl' => 3600,
            'batch_size' => 100,
            'parallel_processing' => true
        ], $config);
    }

    public function registerEnricher(string $name, callable $enricher): void
    {
        $this->enrichers[$name] = $enricher;
    }

    public function enrich(array $data, array $enrichers = []): array
    {
        $enrichers = empty($enrichers) ? array_keys($this->enrichers) : $enrichers;
        $result = $data;

        foreach ($enrichers as $enricher) {
            if (!isset($this->enrichers[$enricher])) {
                continue;
            }

            $result = $this->applyEnricher($result, $enricher);
        }

        return $result;
    }

    public function batchEnrich(array $items, array $enrichers = []): array
    {
        $batches = array_chunk($items, $this->config['batch_size']);
        $result = [];

        foreach ($batches as $batch) {
            $result = array_merge($result, $this->enrich($batch, $enrichers));
        }

        return $result;
    }

    private function applyEnricher(array $data, string $enricher): array
    {
        $cacheKey = $this->generateCacheKey($data, $enricher);
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $enriched = ($this->enrichers[$enricher])($data);
        $this->cache[$cacheKey] = $enriched;

        return $enriched;
    }

    private function generateCacheKey(array $data, string $enricher): string
    {
        return md5($enricher . serialize($data));
    }
}

class EnrichmentPipeline
{
    private array $steps = [];
    private array $results = [];

    public function addStep(string $name, callable $processor): self
    {
        $this->steps[$name] = $processor;
        return $this;
    }

    public function process(array $data): array
    {
        $result = $data;
        $this->results = [];

        foreach ($this->steps as $name => $processor) {
            try {
                $start = microtime(true);
                $result = $processor($result);
                $duration = microtime(true) - $start;

                $this->results[$name] = [
                    'success' => true,
                    'duration' => $duration,
                    'items_processed' => count($result)
                ];
            } catch (\Exception $e) {
                $this->results[$name] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'duration' => microtime(true) - $start
                ];

                throw new EnrichmentException(
                    "Enrichment step '{$name}' failed: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $result;
    }

    public function getResults(): array
    {
        return $this->results;
    }
}

class GeoEnricher
{
    private array $geoData;
    private array $cache = [];

    public function __construct(array $geoData)
    {
        $this->geoData = $geoData;
    }

    public function enrich(array $data): array
    {
        if (!isset($data['ip_address'])) {
            return $data;
        }

        $geoInfo = $this->getGeoInfo($data['ip_address']);
        if ($geoInfo) {
            $data['geo'] = $geoInfo;
        }

        return $data;
    }

    private function getGeoInfo(string $ip): ?array
    {
        if (isset($this->cache[$ip])) {
            return $this->cache[$ip];
        }

        // Mock implementation - replace with actual geo lookup
        $geoInfo = [
            'country' => 'Unknown',
            'city' => 'Unknown',
            'latitude' => 0,
            'longitude' => 0
        ];

        $this->cache[$ip] = $geoInfo;
        return $geoInfo;
    }
}

class UserEnricher
{
    private array $userData;
    private array $cache = [];

    public function __construct(array $userData)
    {
        $this->userData = $userData;
    }

    public function enrich(array $data): array
    {
        if (!isset($data['user_id'])) {
            return $data;
        }

        $userInfo = $this->getUserInfo($data['user_id']);
        if ($userInfo) {
            $data['user'] = $userInfo;
        }

        return $data;
    }

    private function getUserInfo(int $userId): ?array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        // Mock implementation - replace with actual user data lookup
        $userInfo = [
            'segment' => 'Unknown',
            'registration_date' => null,
            'engagement_score' => 0
        ];

        $this->cache[$userId] = $userInfo;
        return $userInfo;
    }
}

class DeviceEnricher
{
    private array $deviceData;
    private array $cache = [];

    public function __construct(array $deviceData)
    {
        $this->deviceData = $deviceData;
    }

    public function enrich(array $data): array
    {
        if (!isset($data['user_agent'])) {
            return $data;
        }

        $deviceInfo = $this->getDeviceInfo($data['user_agent']);
        if ($deviceInfo) {
            $data['device'] = $deviceInfo;
        }

        return $data;
    }

    private function getDeviceInfo(string $userAgent): ?array
    {
        if (isset($this->cache[$userAgent])) {
            return $this->cache[$userAgent];
        }

        // Mock implementation - replace with actual device detection
        $deviceInfo = [
            'type' => 'Unknown',
            'os' => 'Unknown',
            'browser' => 'Unknown'
        ];

        $this->cache[$userAgent] = $deviceInfo;
        return $deviceInfo;
    }
}

