namespace App\Core\Input\CircuitBreaker;

class InputCircuitBreaker
{
    private StateStore $stateStore;
    private FailureDetector $failureDetector;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        StateStore $stateStore,
        FailureDetector $failureDetector,
        MetricsCollector $metrics,
        LoggerInterface $logger,
        array $config
    ) {
        $this->stateStore = $stateStore;
        $this->failureDetector = $failureDetector;
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function execute(string $key, callable $operation): mixed
    {
        $state = $this->stateStore->getState($key);

        if (!$this->canExecute($state)) {
            throw new CircuitOpenException("Circuit breaker is open for $key");
        }

        try {
            $result = $this->tryExecute($key, $operation);
            $this->recordSuccess($key);
            return $result;
        } catch (\Exception $e) {
            $this->handleFailure($key, $e);
            throw $e;
        }
    }

    private function canExecute(CircuitState $state): bool
    {
        return match($state->getStatus()) {
            CircuitStatus::CLOSED => true,
            CircuitStatus::OPEN => $this->shouldAttemptReset($state),
            CircuitStatus::HALF_OPEN => true
        };
    }

    private function tryExecute(string $key, callable $operation): mixed
    {
        $this->metrics->incrementAttempts($key);
        return $operation();
    }

    private function recordSuccess(string $key): void
    {
        $this->metrics->incrementSuccess($key);
        $state = $this->stateStore->getState($key);

        if ($state->getStatus() === CircuitStatus::HALF_OPEN) {
            $this->resetCircuit($key);
        }
    }

    private function handleFailure(string $key, \Exception $e): void
    {
        $this->metrics->incrementFailure($key);
        $state = $this->stateStore->getState($key);

        if ($this->failureDetector->shouldTrip($state, $this->metrics->getMetrics($key))) {
            $this->tripCircuit($key);
        }

        $this->logger->error('Circuit breaker operation failed', [
            'key' => $key,
            'error' => $e->getMessage(),
            'state' => $state->getStatus()->value
        ]);
    }

    private function shouldAttemptReset(CircuitState $state): bool
    {
        return $state->getLastStateChange() + $this->config['reset_timeout'] < time();
    }

    private function resetCircuit(string $key): void
    {
        $this->stateStore->setState($key, new CircuitState(
            status: CircuitStatus::CLOSED,
            lastStateChange: time()
        ));
    }

    private function tripCircuit(string $key): void
    {
        $this->stateStore->setState($key, new CircuitState(
            status: CircuitStatus::OPEN,
            lastStateChange: time()
        ));
    }
}

class FailureDetector
{
    private array $thresholds;

    public function shouldTrip(CircuitState $state, array $metrics): bool
    {
        return match($state->getStatus()) {
            CircuitStatus::CLOSED => $this->exceedsFailureThreshold($metrics),
            CircuitStatus::HALF_OPEN => true,
            CircuitStatus::OPEN => false
        };
    }

    private function exceedsFailureThreshold(array $metrics): bool
    {
        $failureRate = $metrics['failures'] / ($metrics['attempts'] ?: 1);
        return $failureRate >= $this->thresholds['failure_rate'];
    }
}

class StateStore
{
    private CacheInterface $cache;

    public function getState(string $key): CircuitState
    {
        $data = $this->cache->get($this->getCacheKey($key));

        if (!$data) {
            return new CircuitState(
                status: CircuitStatus::CLOSED,
                lastStateChange: time()
            );
        }

        return $this->deserializeState($data);
    }

    public function setState(string $key, CircuitState $state): void
    {
        $this->cache->set(
            $this->getCacheKey($key),
            $this->serializeState($state)
        );
    }

    private function getCacheKey(string $key): string
    {
        return "circuit_breaker:$key";
    }
}

class MetricsCollector
{
    private array $metrics = [];

    public function incrementAttempts(string $key): void
    {
        $this->initializeMetrics($key);
        $this->metrics[$key]['attempts']++;
    }

    public function incrementSuccess(string $key): void
    {
        $this->initializeMetrics($key);
        $this->metrics[$key]['successes']++;
    }

    public function incrementFailure(string $key): void
    {
        $this->initializeMetrics($key);
        $this->metrics[$key]['failures']++;
    }

    public function getMetrics(string $key): array
    {
        $this->initializeMetrics($key);
        return $this->metrics[$key];
    }

    private function initializeMetrics(string $key): void
    {
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = [
                'attempts' => 0,
                'successes' => 0,
                'failures' => 0
            ];
        }
    }
}

class CircuitState
{
    public function __construct(
        private CircuitStatus $status,
        private int $lastStateChange
    ) {}

    public function getStatus(): CircuitStatus
    {
        return $this->status;
    }

    public function getLastStateChange(): int
    {
        return $this->lastStateChange;
    }
}

enum CircuitStatus: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';
}

class CircuitOpenException extends \RuntimeException {}
