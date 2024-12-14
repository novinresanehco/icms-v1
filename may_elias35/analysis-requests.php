<?php

namespace App\Core\Audit\Requests;

class AnalysisRequest
{
    private array $data;
    private array $config;
    private array $parameters;
    private array $metadata;

    public function __construct(
        array $data,
        array $config = [],
        array $parameters = [],
        array $metadata = []
    ) {
        $this->data = $data;
        $this->config = $config;
        $this->parameters = $parameters;
        $this->metadata = $metadata;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function withConfig(array $config): self
    {
        $clone = clone $this;
        $clone->config = array_merge($this->config, $config);
        return $clone;
    }

    public function withParameters(array $parameters): self
    {
        $clone = clone $this;
        $clone->parameters = array_merge($this->parameters, $parameters);
        return $clone;
    }

    public function withMetadata(array $metadata): self
    {
        $clone = clone $this;
        $clone->metadata = array_merge($this->metadata, $metadata);
        return $clone;
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'config' => $this->config,
            'parameters' => $this->parameters,
            'metadata' => $this->metadata
        ];
    }
}

class BatchAnalysisRequest
{
    private array $requests;
    private array $config;

    public function __construct(array $requests, array $config = [])
    {
        $this->requests = $requests;
        $this->config = $config;
    }

    public function getRequests(): array
    {
        return $this->requests;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function withConfig(array $config): self
    {
        $clone = clone $this;
        $clone->config = array_merge($this->config, $config);
        return $clone;
    }
}

class AnalysisScheduleRequest
{
    private AnalysisRequest $request;
    private ScheduleConfig $schedule;
    private array $metadata;

    public function __construct(
        AnalysisRequest $request,
        ScheduleConfig $schedule,
        array $metadata = []
    ) {
        $this->request = $request;
        $this->schedule = $schedule;
        $this->metadata = $metadata;
    }

    public function getRequest(): AnalysisRequest
    {
        return $this->request;
    }

    public function getSchedule(): ScheduleConfig
    {
        return $this->schedule;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
