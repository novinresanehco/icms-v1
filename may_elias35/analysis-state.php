<?php

namespace App\Core\Audit\State;

class StateManager
{
    private array $state = [];
    private array $history = [];
    private array $observers = [];

    public function setState(string $key, $value): void
    {
        $oldValue = $this->state[$key] ?? null;
        $this->state[$key] = $value;
        
        if ($oldValue !== $value) {
            $this->recordHistory($key, $oldValue, $value);
            $this->notifyObservers($key, $oldValue, $value);
        }
    }

    public function getState(string $key)
    {
        return $this->state[$key] ?? null;
    }

    public function getHistory(string $key): array
    {
        return $this->history[$key] ?? [];
    }

    public function subscribe(StateObserver $observer): void
    {
        $this->observers[] = $observer;
    }

    private function recordHistory(string $key, $oldValue, $newValue): void
    {
        if (!isset($this->history[$key])) {
            $this->history[$key] = [];
        }

        $this->history[$key][] = [
            'old' => $oldValue,
            'new' => $newValue,
            'timestamp' => time()
        ];
    }

    private function notifyObservers(string $key, $oldValue, $newValue): void
    {
        foreach ($this->observers as $observer) {
            $observer->onStateChanged($key, $oldValue, $newValue);
        }
    }
}

class AnalysisState
{
    private string $status;
    private array $data;
    private array $metrics;
    private \DateTime $startedAt;
    private ?\DateTime $completedAt = null;

    public function __construct(array $data = [])
    {
        $this->status = 'pending';
        $this->data = $data;
        $this->metrics = [];
        $this->startedAt = new \DateTime();
    }

    public function start(): void
    {
        $this->status = 'running';
        $this->startedAt = new \DateTime();
    }

    public function complete(): void
    {
        $this->status = 'completed';
        $this->completedAt = new \DateTime();
    }

    public function fail(string $reason): void
    {
        $this->status = 'failed';
        $this->completedAt = new \DateTime();
        $this->data['failure_reason'] = $reason;
    }

    public function addMetric(string $key, $value): void
    {
        $this->metrics[$key] = $value;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDuration(): ?int
    {
        if (!$this->completedAt) {
            return null;
        }

        return $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'data' => $this->data,
            'metrics' => $this->metrics,
            'started_at' => $this->startedAt->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
            'duration' => $this->getDuration()
        ];
    }
}
