<?php

namespace App\Core\Interfaces;

interface MonitoringServiceInterface
{
    public function trackMetric(string $name, float $value, array $tags = []): void;
    public function trackPerformance(string $operation, float $duration, array $context = []): void;
    public function trackResourceUsage(): void;
    public function trackError(\Throwable $error, array $context = []): void;
    public function getMetrics(string $name, array $criteria = []): array;
    public function flushMetrics(?string $key = null): void;
}
