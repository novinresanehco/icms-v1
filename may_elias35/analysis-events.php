<?php

namespace App\Core\Audit\Events;

class AnalysisStartedEvent
{
    private string $analysisId;
    private array $config;
    private array $metadata;

    public function __construct(string $analysisId, array $config, array $metadata = [])
    {
        $this->analysisId = $analysisId;
        $this->config = $config;
        $this->metadata = $metadata;
    }

    public function getAnalysisId(): string
    {
        return $this->analysisId;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class AnalysisCompletedEvent
{
    private string $analysisId;
    private array $results;
    private array $metadata;

    public function __construct(string $analysisId, array $results, array $metadata = [])
    {
        $this->analysisId = $analysisId;
        $this->results = $results;
        $this->metadata = $metadata;
    }

    public function getAnalysisId(): string
    {
        return $this->analysisId;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class AnalysisFailedEvent
{
    private string $analysisId;
    private \Throwable $exception;
    private array $context;

    public function __construct(string $analysisId, \Throwable $exception, array $context = [])
    {
        $this->analysisId = $analysisId;
        $this->exception = $exception;
        $this->context = $context;
    }

    public function getAnalysisId(): string
    {
        return $this->analysisId;
    }

    public function getException(): \Throwable
    {
        return $this->exception;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class AnomalyDetectedEvent
{
    private string $analysisId;
    private array $anomaly;
    private string $type;
    private array $context;

    public function __construct(string $analysisId, array $anomaly, string $type, array $context = [])
    {
        $this->analysisId = $analysisId;
        $this->anomaly = $anomaly;
        $this->type = $type;
        $this->context = $context;
    }

    public function getAnalysisId(): string
    {
        return $this->analysisId;
    }

    public function getAnomaly(): array
    {
        return $this->anomaly;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class PatternDetectedEvent
{
    private string $analysisId;
    private array $pattern;
    private string $type;
    private array $metadata;

    public function __construct(string $analysisId, array $pattern, string $type, array $metadata = [])
    {
        $this->analysisId = $analysisId;
        $this->pattern = $pattern;
        $this->type = $type;
        $this->metadata =