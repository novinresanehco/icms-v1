<?php

namespace App\Core\Analytics\Services;

use App\Core\Analytics\Models\Event;
use App\Core\Analytics\Repositories\AnalyticsRepository;

class AnalyticsService
{
    public function __construct(
        private AnalyticsRepository $repository,
        private AnalyticsValidator $validator,
        private AnalyticsProcessor $processor
    ) {}

    public function track(string $event, array $properties = []): Event
    {
        $this->validator->validate($event, $properties);

        $event = $this->repository->create([
            'name' => $event,
            'properties' => $properties,
            'user_id' => auth()->id(),
            'session_id' => session()->getId(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        $this->processor->process($event);
        return $event;
    }

    public function identify(int $userId, array $traits = []): void
    {
        $this->validator->validateTraits($traits);
        $this->repository->updateUserTraits($userId, $traits);
    }

    public function page(string $name, array $properties = []): void
    {
        $this->track('page_viewed', array_merge([
            'page_name' => $name
        ], $properties));
    }

    public function getEvents(array $filters = []): Collection
    {
        return $this->repository->getEvents($filters);
    }

    public function getMetrics(string $event, string $metric, array $filters = []): array
    {
        return $this->repository->getMetrics($event, $metric, $filters);
    }

    public function getFunnels(array $steps, array $filters = []): array
    {
        return $this->repository->getFunnels($steps, $filters);
    }

    public function getRetention(array $criteria, array $filters = []): array
    {
        return $this->repository->getRetention($criteria, $filters);
    }

    public function export(array $filters = []): string
    {
        return $this->processor->exportEvents($filters);
    }

    public function getStats(): array
    {
        return $this->repository->getStats();
    }

    public function cleanup(int $days = 90): int
    {
        return $this->repository->cleanup($days);
    }
}
