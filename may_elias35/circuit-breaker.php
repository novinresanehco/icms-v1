<?php

namespace App\Core\Resilience;

class CircuitBreaker
{
    private StateStore $stateStore;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        StateStore $stateStore,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->stateStore = $stateStore;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function canExecute(Service $service): bool
    {
        $state = $this->getState($service);

        switch ($state['status']) {
            case CircuitStatus::OPEN:
                if ($this->shouldAttemptReset($state)) {
                    $this->transitionToHalfOpen($service);
                    return true;
                }
                return false;

            case CircuitStatus::HALF_OPEN:
                return $state['test_requests'] < $this->config['test_request_limit'];

            case CircuitStatus::CLOSED:
                return true;
        }
    }

    public function recordSuccess(Service $service): void
    {
        $state = $this->getState($service);

        switch ($state['status']) {
            case CircuitStatus::HALF_OPEN:
                $state['success_count']++;
                if ($this->canCloseBreakerAfterSuccess($state)) {
                    $this->transitionToClosed($service);
                } else {
                    $state['test_requests']++;
                    $this->stateStore->save($service->getId(), $state);
                }
                break;

            case CircuitStatus::CLOSED:
                $state['failure_count'] = 0;
                $this->stateStore->save($service->getId(), $state);
                break;
        }

        $this->metrics->recordSuccess($service);
    }

    public function recordFailure(Service $service): void
    {
        $state = $this->getState($service);

        switch ($state['status']) {
            case CircuitStatus::CLOSED:
                $state['failure_count']++;
                if ($this->shouldOpenBreaker($state)) {
                    $this->transitionToOpen($service);
                } else {
                    $this->stateStore->save($service->getId(), $state);
                }
                break;

            case CircuitStatus::HALF_OPEN:
                $this->transitionToOpen($service);
                break;
        }

        $this->metrics->recordFailure($service);
    }

    protected function getState(Service $service): array
    {
        return $this->stateStore->get($service->getId()) ?? [
            'status' => CircuitStatus::CLOSED,
            'failure_count' => 0,
            'success_count' => 0,
            'last_failure_time' => null,
            'test_requests' => 0
        ];
    }

    protected function shouldOpenBreaker(array $state): bool
    {
        return $state['failure_count'] >= $this->config['failure_threshold'];
    }

    protected function shouldAttemptReset(array $state): bool
    {
        $resetTimeout = $this->config['reset_timeout'];
        return time() - $state['last_failure_time'] >= $resetTimeout;
    }

    protected function canCloseBreakerAfterSuccess(array $state): bool
    {
        return $state['success_count'] >= $this->config['success_threshold'];
    }

    protected function transitionToOpen(Service $service): void
    {
        $state = [
            'status' => CircuitStatus::OPEN,
            'failure_count' => 0,
            'success_count' => 0,
            'last_failure_time' => time(),
            'test_requests' => 0
        ];

        $this->stateStore->save($service->getId(), $state);
        $this->metrics->recordStateTransition($service, CircuitStatus::OPEN);
    }

    protected function transitionToHalfOpen(Service $service): void
    {
        $state = [
            'status' => CircuitStatus::HALF_OPEN,
            'failure_count' => 0,
            'success_count' => 0,
            'last_failure_time' => null,
            'test_requests' => 0
        ];

        $this->stateStore->save($service->getId(), $state);
        $this->metrics->recordStateTransition($service, CircuitStatus::HALF_OPEN);
    }

    protected function transitionToClosed(Service $service): void
    {
        $state = [
            'status' => CircuitStatus::CLOSED,
            'failure_count' => 0,
            'success_count' => 0,
            'last_failure_time' => null,
            'test_requests' => 0
        ];

        $this->stateStore->save($service->getId(), $state);
        $this->metrics->recordStateTransition($service, CircuitStatus::CLOSED);
    }
}
