<?php

namespace App\Core\Notification\Analytics\Enricher;

class DataEnricher
{
    private array $enrichers = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_depth' => 5,
            'timeout' => 30,
            'cache_ttl' => 3600
        ], $config);
    }

    public function addEnricher(string $name, EnricherInterface $enricher): void
    {
        $this->enrichers[$name] = $enricher;
    }

    public function enrich(array $data, array $options = []): array
    {
        $startTime = microtime(true);
        $enriched = $data;

        try {
            foreach ($this->enrichers as $name => $enricher) {
                $enriched = $enricher->enrich($enriched, array_merge($this->config, $options));
                $this->recordMetrics($name, count($enriched), microtime(true) - $startTime, true);
            }

            return $enriched;
        } catch (\Exception $e) {
            $this->recordMetrics('enrich', count($data), microtime(true) - $startTime, false);
            throw new EnrichmentException('Enrichment failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $enricher, int $itemCount, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$enricher])) {
            $this->metrics[$enricher] = [
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'total_duration' => 0,
                'items_processed' => 0
            ];
        }

        $metrics = &$this->metrics[$enricher];
        $metrics['processed']++;
        $metrics[$success ? 'successful' : 'failed']++;
        $metrics['total_duration'] += $duration;
        $metrics['items_processed'] += $itemCount;
    }
}

interface EnricherInterface
{
    public function enrich(array $data, array $options = []): array;
}

class LocationEnricher implements EnricherInterface
{
    private GeocodeService $geocoder;
    private array $cache;

    public function __construct(GeocodeService $geocoder)
    {
        $this->geocoder = $geocoder;
        $this->cache = [];
    }

    public function enrich(array $data, array $options = []): array
    {
        return array_map(function($item) use ($options) {
            if (isset($item['ip_address'])) {
                $item['location'] = $this->getLocationData($item['ip_address']);
            }
            return $item;
        }, $data);
    }

    private function getLocationData(string $ip): array
    {
        if (isset($this->cache[$ip])) {
            return $this->cache[$ip];
        }

        $location = $this->geocoder->lookup($ip);
        $this->cache[$ip] = $location;
        return $location;
    }
}

class UserEnricher implements EnricherInterface
{
    private UserService $userService;
    private array $cache;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
        $this->cache = [];
    }

    public function enrich(array $data, array $options = []): array
    {
        return array_map(function($item) use ($options) {
            if (isset($item['user_id'])) {
                $item['user_data'] = $this->getUserData($item['user_id']);
            }
            return $item;
        }, $data);
    }

    private function getUserData(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $userData = $this->userService->getUserDetails($userId);
        $this->cache[$userId] = $userData;
        return $userData;
    }
}

class DeviceEnricher implements EnricherInterface
{
    private DeviceDetector $deviceDetector;

    public function __construct(DeviceDetector $deviceDetector)
    {
        $this->deviceDetector = $deviceDetector;
    }

    public function enrich(array $data, array $options = []): array
    {
        return array_map(function($item) use ($options) {
            if (isset($item['user_agent'])) {
                $item['device_info'] = $this->deviceDetector->detect($item['user_agent']);
            }
            return $item;
        }, $data);
    }
}

class ContentEnricher implements EnricherInterface
{
    private ContentService $contentService;
    private array $cache;

    public function __construct(ContentService $contentService)
    {
        $this->contentService = $contentService;
        $this->cache = [];
    }

    public function enrich(array $data, array $options = []): array
    {
        return array_map(function($item) use ($options) {
            if (isset($item['content_id'])) {
                $item['content_data'] = $this->getContentData($item['content_id']);
            }
            return $item;
        }, $data);
    }

    private function getContentData(int $contentId): array
    {
        if (isset($this->cache[$contentId])) {
            return $this->cache[$contentId];
        }

        $contentData = $this->contentService->getContentDetails($contentId);
        $this->cache[$contentId] = $contentData;
        return $contentData;
    }
}

class EnrichmentException extends \Exception {}

// Mock service classes for demonstration
class GeocodeService {
    public function lookup(string $ip): array {
        return ['country' => 'Unknown', 'city' => 'Unknown', 'coordinates' => [