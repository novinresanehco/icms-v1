<?php

namespace App\Core\Events\Replay;

class EventReplayManager
{
    private EventStore $eventStore;
    private array $projectors = [];
    private ProgressTracker $progressTracker;
    private ReplayMetrics $metrics;

    public function __construct(
        EventStore $eventStore,
        ProgressTracker $progressTracker,
        ReplayMetrics $metrics
    ) {
        $this->eventStore = $eventStore;
        $this->progressTracker = $progressTracker;
        $this->metrics = $metrics;
    }

    public function registerProjector(string $name, EventProjector $projector): void
    {
        $this->projectors[$name] = $projector;
    }

    public function replay(ReplayOptions $options): ReplayResult
    {
        $startTime = microtime(true);
        $processedEvents = 0;
        $errors = [];

        try {
            foreach ($this->loadEvents($options) as $event) {
                try {
                    $this->replayEvent($event);
                    $processedEvents++;
                    $this->progressTracker->track($processedEvents);
                } catch (\Exception $e) {
                    $errors[] = $this->handleError($e, $event);
                    if (!$options->shouldContinueOnError()) {
                        break;
                    }
                }
            }

            return new ReplayResult(
                processedEvents: $processedEvents,
                errors: $errors,
                duration: microtime(true) - $startTime
            );
        } finally {
            $this->metrics->recordReplayComplete($processedEvents, count($errors));
        }
    }

    private function loadEvents(ReplayOptions $options): \Generator
    {
        return $this->eventStore->streamEvents(
            $options->getStartDate(),
            $options->getEndDate(),
            $options->getEventTypes()
        );
    }

    private function replayEvent(Event $event): void
    {
        foreach ($this->projectors as $projector) {
            if ($projector->supports($event)) {
                $projector->project($event);
            }
        }
    }

    private function handleError(\Exception $e, Event $event): ReplayError
    {
        $error = new ReplayError($event, $e);
        $this->metrics->recordReplayError($error);
        return $error;
    }
}

class ReplayOptions
{
    private ?\DateTimeInterface $startDate = null;
    private ?\DateTimeInterface $endDate = null;
    private array $eventTypes = [];
    private bool $continueOnError = false;

    public function setDateRange(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): self {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        return $this;
    }

    public function setEventTypes(array $eventTypes): self
    {
        $this->eventTypes = $eventTypes;
        return $this;
    }

    public function setContinueOnError(bool $continue): self
    {
        $this->continueOnError = $continue;
        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function getEventTypes(): array
    {
        return $this->eventTypes;
    }

    public function shouldContinueOnError(): bool
    {
        return $this->continueOnError;
    }
}

class ReplayResult
{
    public function __construct(
        private int $processedEvents,
        private array $errors,
        private float $duration
    ) {}

    public function getProcessedEvents(): int
    {
        return $this->processedEvents;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}

interface EventProjector
{
    public function supports(Event $event): bool;
    public function project(Event $event): void;
    public function reset(): void;
}

class ProgressTracker
{
    private int $total;
    private int $current = 0;
    private ?\Closure $progressCallback = null;

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }

    public function onProgress(\Closure $callback): void
    {
        $this->progressCallback = $callback;
    }

    public function track(int $current): void
    {
        $this->current = $current;
        if ($this->progressCallback) {
            ($this->progressCallback)($this->current, $this->total);
        }
    }

    public function getProgress(): float
    {
        if ($this->total === 0) {
            return 0;
        }
        return ($this->current / $this->total) * 100;
    }
}

class ReplayMetrics
{
    private MetricsCollector $collector;

    public function __construct(MetricsCollector $collector)
    {
        $this->collector = $collector;
    }

    public function recordReplayStart(): void
    {
        $this->collector->increment('event_replay.started');
    }

    public function recordReplayComplete(int $processed, int $errors): void
    {
        $this->collector->increment('event_replay.completed');
        $this->collector->gauge('event_replay.processed', $processed);
        $this->collector->gauge('event_replay.errors', $errors);
    }

    public function recordReplayError(ReplayError $error): void
    {
        $this->collector->increment('event_replay.errors', [
            'event_type' => get_class($error->getEvent()),
            'error_type' => get_class($error->getException())
        ]);
    }
}
