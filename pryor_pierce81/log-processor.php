<?php

namespace App\Core\Monitoring\LogProcessing;

class LogProcessor {
    private LogReader $reader;
    private LogNormalizer $normalizer;
    private LogEnricher $enricher;
    private LogWriter $writer;
    private LogCache $cache;

    public function __construct(
        LogReader $reader,
        LogNormalizer $normalizer,
        LogEnricher $enricher,
        LogWriter $writer,
        LogCache $cache
    ) {
        $this->reader = $reader;
        $this->normalizer = $normalizer;
        $this->enricher = $enricher;
        $this->writer = $writer;
        $this->cache = $cache;
    }

    public function process(LogBatch $batch): ProcessingResult 
    {
        $logs = $this->reader->read($batch);
        
        $normalized = $this->normalizer->normalize($logs);
        
        $enriched = $this->enricher->enrich($normalized);
        
        $this->cache->store($enriched);
        
        $this->writer->write($enriched);

        return new ProcessingResult($enriched);
    }
}

class LogBatch {
    private string $source;
    private array $logs;
    private array $metadata;

    public function __construct(string $source, array $logs, array $metadata = []) 
    {
        $this->source = $source;
        $this->logs = $logs;
        $this->metadata = $metadata;
    }

    public function getSource(): string 
    {
        return $this->source;
    }

    public function getLogs(): array 
    {
        return $this->logs;
    }

    public function getMetadata(): array 
    {
        return $this->metadata;
    }
}

class LogNormalizer {
    private array $normalizers;
    private array $filters;

    public function normalize(array $logs): array 
    {
        $normalized = [];

        foreach ($logs as $log) {
            foreach ($this->normalizers as $normalizer) {
                if ($normalizer->canNormalize($log)) {
                    $normalized = $normalizer->normalize($log);
                    if ($this->shouldInclude($normalized)) {
                        $normalized[] = $normalized;
                    }
                    break;
                }
            }
        }

        return $normalized;
    }

    private function shouldInclude(NormalizedLog $log): bool 
    {
        foreach ($this->filters as $filter) {
            if (!$filter->accept($log)) {
                return false;
            }
        }
        return true;
    }
}

class LogEnricher {
    private array $enrichers;

    public function enrich(array $logs): array 
    {
        $enriched = [];

        foreach ($logs as $log) {
            $enrichedLog = $log;
            foreach ($this->enrichers as $enricher) {
                $enrichedLog = $enricher->enrich($enrichedLog);
            }
            $enriched[] = $enrichedLog;
        }

        return $enriched;
    }
}

interface LogEnricherInterface {
    public function enrich(NormalizedLog $log): EnrichedLog;
}

class ContextEnricher implements LogEnricherInterface {
    private ContextProvider $contextProvider;

    public function enrich(NormalizedLog $log): EnrichedLog 
    {
        $context = $this->contextProvider->getContext($log);
        return new EnrichedLog($log, $context);
    }
}

class GeoEnricher implements LogEnricherInterface {
    private GeoResolver $geoResolver;

    public function enrich(NormalizedLog $log): EnrichedLog 
    {
        if ($ip = $log->getIp()) {
            $geoData = $this->geoResolver->resolve($ip);
            return new EnrichedLog($log, ['geo' => $geoData]);
        }
        return new EnrichedLog($log);
    }
}

class UserEnricher implements LogEnricherInterface {
    private UserResolver $userResolver;

    public function enrich(NormalizedLog $log): EnrichedLog 
    {
        if ($userId = $log->getUserId()) {
            $userData = $this->userResolver->resolve($userId);
            return new EnrichedLog($log, ['user' => $userData]);
        }
        return new EnrichedLog($log);
    }
}

class LogCache {
    private CacheInterface $cache;
    private int $ttl;

    public function store(array $logs): void 
    {
        foreach ($logs as $log) {
            $key = $this->generateKey($log);
            $this->cache->set($key, $log, $this->ttl);
        }
    }

    private function generateKey(EnrichedLog $log): string 
    {
        return sprintf(
            'log:%s:%s',
            $log->getSource(),
            $log->getId()
        );
    }
}

class LogWriter {
    private array $writers;
    private array $formatters;

    public function write(array $logs): void 
    {
        foreach ($this->writers as $writer) {
            $formatter = $this->formatters[$writer->getFormat()] ?? null;
            if ($formatter) {
                $formatted = $formatter->format($logs);
                $writer->write($formatted);
            }
        }
    }
}

class ProcessingResult {
    private array $logs;
    private float $timestamp;
    private array $metrics;

    public function __construct(array $logs) 
    {
        $this->logs = $logs;
        $this->timestamp = microtime(true);
        $this->metrics = $this->calculateMetrics();
    }

    private function calculateMetrics(): array 
    {
        return [
            'total' => count($this->logs),
            'types' => $this->countByType(),
            'sources' => $this->countBySource(),
            'processing_time' => microtime(true) - $this->timestamp
        ];
    }

    private function countByType(): array 
    {
        $counts = [];
        foreach ($this->logs as $log) {
            $type = $log->getType();
            $counts[$type] =