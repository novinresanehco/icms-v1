<?php

namespace App\Core\Tag\Services\Actions\DTOs;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;

class TagActionResponse
{
    public bool $success;
    public ?string $message;
    public ?string $error;
    public array $data;
    public array $metadata;

    public function __construct(
        bool $success,
        ?string $message = null,
        ?string $error = null,
        array $data = [],
        array $metadata = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->error = $error;
        $this->data = $data;
        $this->metadata = $metadata;
    }

    public static function success(
        string $message, 
        array $data = [], 
        array $metadata = []
    ): self {
        return new self(true, $message, null, $data, $metadata);
    }

    public static function error(
        string $error, 
        array $data = [], 
        array $metadata = []
    ): self {
        return new self(false, null, $error, $data, $metadata);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'error' => $this->error,
            'data' => $this->data,
            'metadata' => $this->metadata
        ];
    }
}

class TagBulkActionResponse
{
    public bool $success;
    public array $results;
    public array $failures;
    public array $metadata;

    public function __construct(
        bool $success,
        array $results = [],
        array $failures = [],
        array $metadata = []
    ) {
        $this->success = $success;
        $this->results = $results;
        $this->failures = $failures;
        $this->metadata = $metadata;
    }

    public function addResult(int $tagId, bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->results[$tagId] = true;
        } else {
            $this->failures[$tagId] = $error ?? 'Operation failed';
        }
    }

    public function isCompleteSuccess(): bool
    {
        return empty($this->failures);
    }

    public function getSuccessCount(): int
    {
        return count($this->results);
    }

    public function getFailureCount(): int
    {
        return count($this->failures);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'results' => $this->results,
            'failures' => $this->failures,
            'metadata' => $this->metadata,
            'stats' => [
                'total' => $this->getSuccessCount() + $this->getFailureCount(),
                'successful' => $this->getSuccessCount(),
                'failed' => $this->getFailureCount()
            ]
        ];
    }
}

class TagCreateData
{
    public string $name;
    public ?string $description;
    public ?string $metaTitle;
    public ?string $metaDescription;
    public array $relationships;
    public array $metadata;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->description = $data['description'] ?? null;
        $this->metaTitle = $data['meta_title'] ?? null;
        $this->metaDescription = $data['meta_description'] ?? null;
        $this->relationships = $data['relationships'] ?? [];
        $this->metadata = $data['metadata'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'relationships' => $this->relationships,
            'metadata' => $this->metadata
        ];
    }
}

class TagUpdateData
{
    public int $id;
    public ?string $name;
    public ?string $description;
    public ?string $metaTitle;
    public ?string $metaDescription;
    public array $relationships;
    public array $metadata;

    public function __construct(int $id, array $data)
    {
        $this->id = $id;
        $this->name = $data['name'] ?? null;
        $this->description = $data['description'] ?? null;
        $this->metaTitle = $data['meta_title'] ?? null;
        $this->metaDescription = $data['meta_description'] ?? null;
        $this->relationships = $data['relationships'] ?? [];
        $this->metadata = $data['metadata'] ?? [];
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'relationships' => $this->relationships,
            'metadata' => $this->metadata
        ]);
    }
}
