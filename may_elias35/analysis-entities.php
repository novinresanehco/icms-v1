<?php

namespace App\Core\Audit\Entities;

class Analysis
{
    private string $id;
    private string $requestHash;
    private array $config;
    private array $metadata;
    private \DateTime $createdAt;
    private \DateTime $updatedAt;
    private Collection $results;
    private string $status;

    public function __construct(string $id, string $requestHash, array $config = [])
    {
        $this->id = $id;
        $this->requestHash = $requestHash;
        $this->config = $config;
        $this->metadata = [];
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->results = new ArrayCollection();
        $this->status = 'pending';
    }

    public function addResult(AnalysisResult $result): void
    {
        $this->results->add($result);
        $this->updatedAt = new \DateTime();
    }

    public function updateStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
    }

    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
        $this->updatedAt = new \DateTime();
    }
}

class AnalysisResult
{
    private string $id;
    private Analysis $analysis;
    private array $data;
    private array $metrics;
    private Collection $findings;
    private \DateTime $createdAt;

    public function __construct(string $id, Analysis $analysis, array $data = [])
    {
        $this->id = $id;
        $this->analysis = $analysis;
        $this->data = $data;
        $this->metrics = [];
        $this->findings = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function addFinding(Finding $finding): void
    {
        $this->findings->add($finding);
    }

    public function addMetric(string $name, $value): void
    {
        $this->metrics[$name] = $value;
    }
}

class Finding
{
    private string $id;
    private AnalysisResult $result;
    private string $type;
    private string $description;
    private array $context;
    private float $confidence;
    private \DateTime $createdAt;

    public function __construct(
        string $id,
        AnalysisResult $result,
        string $type,
        string $description,
        array $context = [],
        float $confidence = 0.0
    ) {
        $this->id = $id;
        $this->result = $result;
        $this->type = $type;
        $this->description = $description;
        $this->context = $context;
        $this->confidence = $confidence;
        $this->createdAt = new \DateTime();
    }
}

class Anomaly
{
    private string $id;
    private string $type;
    private string $severity;
    private float $confidence;
    private array $data;
    private array $context;
    private \DateTime $detectedAt;

    public function __construct(
        string $id,
        string $type,
        string $severity,
        float $confidence,
        array $data,
        array $context = []
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->severity = $severity;
        $this->confidence = $confidence;
        $this->data = $data;
        $this->context = $context;
        $this->detectedAt = new \DateTime();
    }
}

class Pattern
{
    private string $id;
    private string $type;
    private array $sequence;
    private float $confidence;
    private array $metadata;
    private \DateTime $detectedAt;

    public function __construct(
        string $id,
        string $type,
        array $sequence,
        float $confidence,
        array $metadata = []
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->sequence = $sequence;
        $this->confidence = $confidence;
        $this->metadata = $metadata;
        $this->detectedAt = new \DateTime();
    }
}
