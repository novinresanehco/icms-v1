<?php

namespace App\Core\Audit\Strategies;

class ProcessingStrategy
{
    private array $steps;
    private ProcessingContext $context;
    private LoggerInterface $logger;

    public function __construct(array $steps, LoggerInterface $logger)
    {
        $this->steps = $steps;
        $this->context = new ProcessingContext();
        $this->logger = $logger;
    }

    public function process(array $data): array
    {
        foreach ($this->steps as $step) {
            try {
                $data = $step->execute($data, $this->context);
            } catch (\Exception $e) {
                $this->logger->error('Processing step failed', [
                    'step' => get_class($step),
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        return $data;
    }
}

class CacheStrategy
{
    private CacheInterface $cache;
    private string $prefix;
    private array $config;

    public function __construct(CacheInterface $cache, string $prefix, array $config)
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
        $this->config = $config;
    }

    public function get(string $key)
    {
        return $this->cache->get($this->generateKey($key));
    }

    public function set(string $key, $value): void
    {
        $this->cache->set(
            $this->generateKey($key),
            $value,
            $this->getTtl($key)
        );
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->generateKey($key));
    }

    public function delete(string $key): void
    {
        $this->cache->delete($this->generateKey($key));
    }

    private function generateKey(string $key): string
    {
        return sprintf('%s:%s', $this->prefix, $key);
    }

    private function getTtl(string $key): int
    {
        return $this->config['ttl'][$key] ?? $this->config['default_ttl'] ?? 3600;
    }
}

class RetryStrategy
{
    private int $maxAttempts;
    private int $delay;
    private array $retryableExceptions;
    private LoggerInterface $logger;

    public function __construct(
        int $maxAttempts,
        int $delay,
        array $retryableExceptions,
        LoggerInterface $logger
    ) {
        $this->maxAttempts = $maxAttempts;
        $this->delay = $delay;
        $this->retryableExceptions = $retryableExceptions;
        $this->logger = $logger;
    }

    public function execute(callable $operation): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $operation();
            } catch (\Exception $e) {
                if (!$this->shouldRetry($e, $attempt)) {
                    throw $e;
                }

                $this->logger->warning('Operation failed, retrying', [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'error' => $e->getMessage()
                ]);

                $this->wait($attempt);
                $attempt++;
            }
        }
    }

    private function shouldRetry(\Exception $e, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        foreach ($this->retryableExceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }

        return false;
    }

    private function wait(int $attempt): void
    {
        $delay = $this->delay * pow(2, $attempt - 1);
        usleep($delay * 1000);
    }
}

class ValidationStrategy
{
    private array $validators;
    private array $config;

    public function __construct(array $validators, array $config = [])
    {
        $this->validators = $validators;
        $this->config = $config;
    }

    public function validate($data): ValidationResult
    {
        $errors = [];

        foreach ($this->validators as $validator) {
            if ($validator->supports($data)) {
                $result = $validator->validate($data, $this->config);
                if (!$result->isValid()) {
                    $errors = array_merge($errors, $result->getErrors());
                    
                    if ($this->shouldFailFast() && !empty($errors)) {
                        break;
                    }
                }
            }
        }

        return new ValidationResult(empty($errors), $errors);
    }

    private function shouldFailFast(): bool
    {
        return $this->config['fail_fast'] ?? false;
    }
}

class LoadBalancingStrategy
{
    private array $nodes;
    private array $weights;
    private HealthChecker $healthChecker;
    private LoadMetrics $metrics;

    public function __construct(
        array $nodes,
        array $weights,
        HealthChecker $healthChecker,
        LoadMetrics $metrics
    ) {
        $this->nodes = $nodes;
        $this->weights = $weights;
        $this->healthChecker = $healthChecker;
        $this->metrics = $metrics;
    }

    public function selectNode(): Node
    {
        $availableNodes = array_filter(
            $this->nodes,
            fn($node) => $this->healthChecker->isHealthy($node)
        );

        if (empty($availableNodes)) {
            throw new NoAvailableNodesException('No healthy nodes available');
        }

        $selectedNode = $this->selectNodeByStrategy($availableNodes);
        $this->metrics->recordNodeSelection($selectedNode);

        return $selectedNode;
    }

    private function selectNodeByStrategy(array $nodes): Node
    {
        $strategy = $this->determineStrategy();
        
        return match($strategy) {
            'round_robin' => $this->roundRobin($nodes),
            'least_connections' => $this->leastConnections($nodes),
            'weighted' => $this->weighted($nodes),
            default => $this->random($nodes)
        };
    }

    private function determineStrategy(): string
    {
        $load = $this->metrics->getCurrentLoad();
        
        if ($load > 0.8) {
            return 'least_connections';
        }
        
        return 'weighted';
    }
}
