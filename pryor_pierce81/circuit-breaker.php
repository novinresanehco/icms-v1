<?php

namespace App\Core\Resilience;

class CircuitBreaker
{
    private string $name;
    private CircuitState $state;
    private FailureDetector $failureDetector;
    private StateStore $stateStore;
    private array $options;

    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        $this->options = array_merge([
            'failureThreshold' => 5,
            'resetTimeout' => 60,
            'halfOpenMax' => 1
        ], $options);
        
        $this->failureDetector = new FailureDetector($this->options['failureThreshold']);
        $this->stateStore = new StateStore();
        $this->state = $this->stateStore->getState($name) ?? new ClosedState();
    }

    public function execute(callable $operation)
    {
        if (!$this->state->canPass()) {
            throw new CircuitBreakerOpenException($this->name);
        }

        try {
            $result = $operation();
            $this->onSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->onFailure($e);
            throw $e;
        }
    }

    private function onSuccess(): void
    {
        $this->failureDetector->recordSuccess();
        
        if ($this->state instanceof HalfOpenState) {
            $this->transitionTo(new ClosedState());
        }
    }

    private function onFailure(\Exception $e): void
    {
        $this->failureDetector->recordFailure($e);
        
        if ($this->failureDetector->hasExceededThreshold()) {
            $this->transitionTo(new OpenState($this->options['resetTimeout']));
        }
    }

    private function transitionTo(CircuitState $newState): void
    {
        $this->state = $newState;
        $this->stateStore->saveState($this->name, $newState);
    }
}

abstract class CircuitState
{
    protected int $timestamp;

    public function __construct()
    {
        $this->timestamp = time();
    }

    abstract public function canPass(): bool;
    abstract public function getName(): string;
}

class ClosedState extends CircuitState
{
    public function canPass(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'closed';
    }
}

class OpenState extends CircuitState
{
    private int $resetTimeout;

    public function __construct(int $resetTimeout)
    {
        parent::__construct();
        $this->resetTimeout = $resetTimeout;
    }

    public function canPass(): bool
    {
        if (time() - $this->timestamp >= $this->resetTimeout) {
            return true;
        }
        return false;
    }

    public function getName(): string
    {
        return 'open';
    }
}

class HalfOpenState extends CircuitState
{
    private int $maxAttempts;
    private int $attempts = 0;

    public function __construct(int $maxAttempts)
    {
        parent::__construct();
        $this->maxAttempts = $maxAttempts;
    }

    public function canPass(): bool
    {
        if ($this->attempts >= $this->maxAttempts) {
            return false;
        }
        $this->attempts++;
        return true;
    }

    public function getName(): string
    {
        return 'half-open';
    }
}

class FailureDetector
{
    private int $threshold;
    private int $failures = 0;
    private array $errors = [];

    public function __construct(int $threshold)
    {
        $this->threshold = $threshold;
    }

    public function recordSuccess(): void
    {
        $this->failures = 0;
        $this->errors = [];
    }

    public function recordFailure(\Exception $e): void
    {
        $this->failures++;
        $this->errors[] = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'time' => time()
        ];
    }

    public function hasExceededThreshold(): bool
    {
        return $this->failures >= $this->threshold;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class StateStore
{
    private $connection;

    public function getState(string $name): ?CircuitState
    {
        $state = $this->connection->table('circuit_breaker_states')
            ->where('name', $name)
            ->first();

        if (!$state) {
            return null;
        }

        return $this->hydrateState(
            $state->state,
            json_decode($state->metadata, true)
        );
    }

    public function saveState(string $name, CircuitState $state): void
    {
        $this->connection->table('circuit_breaker_states')->updateOrInsert(
            ['name' => $name],
            [
                'state' => $state->getName(),
                'metadata' => json_encode($this->dehydrateState($state)),
                'updated_at' => now()
            ]
        );
    }

    private function hydrateState(string $state, array $metadata): CircuitState
    {
        switch ($state) {
            case 'open':
                return new OpenState($metadata['resetTimeout']);
            case 'half-open':
                return new HalfOpenState($metadata['maxAttempts']);
            default:
                return new ClosedState();
        }
    }

    private function dehydrateState(CircuitState $state): array
    {
        switch ($state->getName()) {
            case 'open':
                return ['resetTimeout' => $state->getResetTimeout()];
            case 'half-open':
                return ['maxAttempts' => $state->getMaxAttempts()];
            default:
                return [];
        }
    }
}

class CircuitBreakerOpenException extends \Exception {}
