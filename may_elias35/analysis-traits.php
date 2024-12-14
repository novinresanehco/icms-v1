<?php

namespace App\Core\Audit\Traits;

trait ConfigurableTrait
{
    protected $config;

    public function setConfig($config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getConfig()
    {
        return $this->config;
    }

    protected function validateConfig(): void
    {
        if (!$this->config) {
            throw new ConfigurationException("Configuration not set");
        }
    }
}

trait ValidatableTrait
{
    protected array $errors = [];

    protected function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    protected function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    protected function getErrors(): array
    {
        return $this->errors;
    }

    protected function clearErrors(): void
    {
        $this->errors = [];
    }
}

trait CacheableTrait
{
    protected $cache;

    protected function cacheResult(string $key, $result, int $ttl = 3600): void
    {
        $this->cache->set($key, $result, $ttl);
    }

    protected function getCachedResult(string $key)
    {
        return $this->cache->get($key);
    }

    protected function clearCache(string $key): void
    {
        $this->cache->delete($key);
    }
}

trait LoggableTrait
{
    protected $logger;

    protected function logInfo(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    protected function logWarning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    protected function logDebug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }
}

trait MetricsCollectorTrait
{
    protected $metrics;

    protected function recordMetric(string $name, $value, array $tags = []): void
    {
        $this->metrics->record($name, $value, $tags);
    }

    protected function incrementCounter(string $name, int $increment = 1, array $tags = []): void
    {
        $this->metrics->increment($name, $increment, $tags);
    }

    protected function recordTiming(string $name, float $timing, array $tags = []): void
    {
        $this->metrics->timing($name, $timing, $tags);
    }

    protected function recordDistribution(string $name, $value, array $tags = []): void
    {
        $this->metrics->distribution($name, $value, $tags);
    }
}

trait ErrorHandlerTrait
{
    protected function handleError(\Throwable $e, array $context = []): void
    {
        $this->logError($e->getMessage(), array_merge([
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], $context));

        throw new AnalysisException(
            $e->getMessage(),
            $context,
            $e->getCode(),
            $e
        );
    }
}
