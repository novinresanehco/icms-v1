<?php

namespace App\Core\Logging\Health;

class HealthStatus
{
    private Carbon $timestamp;
    private array $metrics;
    private array $checks;
    private array $diagnostics;
    private array $performance;
    private float $healthScore;
    private array $recommendations = [];
    private array $issues = [];

    public function __construct(array $data)
    {
        $this->timestamp = $data['timestamp'];
        $this->metrics = $data['metrics'];
        $this->checks = $data['checks'];
        $this->diagnostics = $data['diagnostics'];
        $this->performance = $data['performance'];
    }

    public function setHealthScore(float $score): void
    {
        $this->healthScore = $score;
    }

    public function setRecommendations(array $recommendations): void
    {
        $this->recommendations = $recommendations;
    }

    public function addIssue(HealthIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    public function isHealthy(): bool
    {
        return $this->healthScore >= 0.8 && empty($this->issues);
    }

    public function requiresAction(): bool
    {
        return $this->healthScore < 0.8 || !empty($this->issues);
    }

    public function requiresAlert(): bool
    {
        return $this->healthScore < 0.6 || $this->hasCriticalIssues();
    }

    public function requiresAutomatedAction(): bool
    {
        return $this->healthScore < 0.4 || $this->hasSystematicIssues();
    }

    public function hasPerformanceIssues(): bool
    {
        return isset($this->performance['issues']) && !empty($this->performance['issues']);
    }

    public function hasResourceIssues(): bool
    {
        return $this->metrics['storage_usage'] > 80 || 
               $this->metrics['memory_usage'] > 80;
    }

    public function hasSystemIssues(): bool
    {
        return $this->hasCriticalIssues() || $this->hasSystematicIssues();
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getChecks(): array
    {
        return $this->checks;
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    public function getPerformance(): array
    {
        return $this->performance;
    }

    public function getHealthScore(): float
    {
        return $this->healthScore;
    }

    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    public function getIssues(): array
    {
        return $this->issues;
    }

    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp->toIso8601String(),
            'health_score' => $this->healthScore,
            'is_healthy' => $this->isHealthy(),
            'metrics' => $this->metrics,
            'checks' => $this->checks,
            'diagnostics' => $this->diagnostics,
            'performance' => $this->performance,
            'recommendations' => $this->recommendations,
            'issues' => array_map(fn($issue) => $issue->toArray(), $this->issues)
        ];
    }

    protected function hasCriticalIssues(): bool
    {
        return collect($this->issues)
            ->contains(fn($issue) => $issue->isCritical());
    }

    protected function hasSystematicIssues(): bool
    {
        return collect($this->issues)
            ->contains(fn($issue) => $issue->isSystematic());
    }
}
