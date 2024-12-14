<?php

namespace App\Core\Audit\Services;

class AnalysisService
{
    private AnalysisEngine $engine;
    private ValidatorInterface $validator;
    private CacheManager $cache;
    private EventDispatcher $dispatcher;
    private LoggerInterface $logger;

    public function __construct(
        AnalysisEngine $engine,
        ValidatorInterface $validator,
        CacheManager $cache,
        EventDispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        $this->engine = $engine;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public function analyze(AnalysisRequest $request): AnalysisResult
    {
        $analysisId = $this->generateAnalysisId($request);
        
        try {
            $this->dispatcher->dispatch(new AnalysisStartedEvent($analysisId, $request->getConfig()));
            
            if ($cached = $this->checkCache($request)) {
                return $cached;
            }
            
            $this->validator->validate($request);
            
            $result = $this->engine->analyze($request);
            
            $this->cacheResult($request, $result);
            
            $this->dispatcher->dispatch(new AnalysisCompletedEvent($analysisId, $result->toArray()));
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleAnalysisError($analysisId, $e, $request);
            throw $e;
        }
    }

    public function batchAnalyze(array $requests): array
    {
        $results = [];
        $errors = [];

        foreach ($requests as $index => $request) {
            try {
                $results[$index] = $this->analyze($request);
            } catch (\Throwable $e) {
                $errors[$index] = $e;
                $this->logger->error('Batch analysis error', [
                    'index' => $index,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if (!empty($errors)) {
            throw new BatchAnalysisException($results, $errors);
        }

        return $results;
    }

    public function scheduleAnalysis(AnalysisRequest $request, ScheduleConfig $config): string
    {
        $jobId = $this->generateJobId($request);
        
        $job = new AnalysisJob($jobId, $request, $config);
        
        $this->dispatcher->dispatch(new AnalysisScheduledEvent($jobId, $job));
        
        return $jobId;
    }

    private function generateAnalysisId(AnalysisRequest $request): string
    {
        return hash('sha256', serialize([
            'data' => $request->getData(),
            'config' => $request->getConfig(),
            'timestamp' => time()
        ]));
    }

    private function checkCache(AnalysisRequest $request): ?AnalysisResult
    {
        $cacheKey = $this->generateCacheKey($request);
        
        if ($cached = $this->cache->get($cacheKey)) {
            $this->logger->info('Cache hit', ['key' => $cacheKey]);
            return $cached;
        }
        
        return null;
    }

    private function cacheResult(AnalysisRequest $request, AnalysisResult $result): void
    {
        $cacheKey = $this->generateCacheKey($request);
        $ttl = $this->calculateCacheTTL($request);
        
        $this->cache->set($cacheKey, $result, $ttl);
    }

    private function handleAnalysisError(string $analysisId, \Throwable $e, AnalysisRequest $request): void
    {
        $this->logger->error('Analysis failed', [
            'analysis_id' => $analysisId,
            'error' => $e->getMessage(),
            'request' => $request
        ]);

        $this->dispatcher->dispatch(new AnalysisFailedEvent($analysisId, $e));
    }
}

class ValidationService
{
    private array $validators;
    private LoggerInterface $logger;

    public function __construct(array $validators, LoggerInterface $logger)
    {
        $this->validators = $validators;
        $this->logger = $logger;
    }

    public function validate(array $data, array $rules): ValidationResult
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                $errors[$field] = $this->getErrorMessage($rule);
            }
        }

        return new ValidationResult([
            'is_valid' => empty($errors),
            'errors' => $errors
        ]);
    }

    private function validateField($value, $rule): bool
    {
        foreach ($this->validators as $validator) {
            if ($validator->canHandle($rule) && !$validator->validate($value, $rule)) {
                return false;
            }
        }
        return true;
    }

    private function getErrorMessage($rule): string
    {
        foreach ($this->validators as $validator) {
            if ($validator->canHandle($rule)) {
                return $validator->getErrorMessage();
            }
        }
        return 'Validation failed';
    }
}

class CacheService
{
    private CacheManager $cache;
    private CacheKeyGenerator $keyGenerator;
    private array $defaultConfig;

    public function __construct(
        CacheManager $cache,
        CacheKeyGenerator $keyGenerator,
        array $defaultConfig = []
    ) {
        $this->cache = $cache;
        $this->keyGenerator = $keyGenerator;
        $this->defaultConfig = $defaultConfig;
    }

    public function remember(string $key, $data, ?int $ttl = null): mixed
    {
        $cacheKey = $this->keyGenerator->generate($key);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }
        
        $result = is_callable($data) ? $data() : $data;
        
        $this->cache->set(
            $cacheKey, 
            $result, 
            $ttl ?? $this->defaultConfig['ttl'] ?? 3600
        );
        
        return $result;
    }

    public function flush(string $pattern): void
    {
        $this->cache->deletePattern($this->keyGenerator->generate($pattern));
    }

    public function tags(array $tags): self
    {
        return new self(
            $this->cache->tags($tags),
            $this->keyGenerator,
            $this->defaultConfig
        );
    }
}

class NotificationService
{
    private array $channels;
    private NotificationFormatter $formatter;
    private LoggerInterface $logger;

    public function __construct(
        array $channels,
        NotificationFormatter $formatter,
        LoggerInterface $logger
    ) {
        $this->channels = $channels;
        $this->formatter = $formatter;
        $this->logger = $logger;
    }

    public function send(Notification $notification, array $channels = null): void
    {
        $channels = $channels ?? array_keys($this->channels);
        
        $formatted = $this->formatter->format($notification);
        
        foreach ($channels as $channel) {
            try {
                $this->channels[$channel]->send($formatted);
            } catch (\Throwable $e) {
                $this->logger->error('Notification failed', [
                    'channel' => $channel,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    public function sendBatch(array $notifications, array $channels = null): void
    {
        foreach ($notifications as $notification) {
            $this->send($notification, $channels);
        }
    }
}
