<?php

namespace App\Core\Audit;

class ProcessedData
{
    private array $data;
    private array $metadata;

    public function __construct(array $processedData)
    {
        $this->data = $processedData['data'];
        $this->metadata = $processedData['metadata'];
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getProcessingSteps(): array
    {
        return $this->metadata['processing_steps'] ?? [];
    }

    public function getTransformationsApplied(): array
    {
        return $this->metadata['transformations_applied'] ?? [];
    }

    public function getValidationRules(): array
    {
        return $this->metadata['validation_rules'] ?? [];
    }

    public function getProcessingTimestamp(): string
    {
        return $this->metadata['processing_timestamp'] ?? '';
    }

    public function slice(array $columns): self
    {
        $slicedData = array_map(function ($row) use ($columns) {
            return array_intersect_key($row, array_flip($columns));
        }, $this->data);

        return new self([
            'data' => $slicedData,
            'metadata' => array_merge($this->metadata, [
                'sliced_columns' => $columns,
                'slice_timestamp' => now()
            ])
        ]);
    }

    public function filter(callable $callback): self
    {
        $filteredData = array_filter($this->data, $callback);

        return new self([
            'data' => $filteredData,
            'metadata' => array_merge($this->metadata, [
                'filtered_count' => count($this->data) - count($filteredData),
                'filter_timestamp' => now()
            ])
        ]);
    }

    public function transform(callable $callback): self
    {
        $transformedData = array_map($callback, $this->data);

        return new self([
            'data' => $transformedData,
            'metadata' => array_merge($this->metadata, [
                'transformation_applied' => get_class($callback),
                'transform