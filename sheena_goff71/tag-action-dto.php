<?php

namespace App\Core\Tag\Services\Actions\DTOs;

class TagCreateData
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?array $metadata = [],
        public readonly ?int $parentId = null
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'parent_id' => $this->parentId
        ];
    }
}

class TagUpdateData
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?array $metadata = [],
        public readonly ?int $parentId = null
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'parent_id' => $this->parentId
        ]);
    }
}

class TagActionResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $data = [],
        public readonly array $errors = [],
        public readonly array $meta = []
    ) {}

    public static function success(
        string $message,
        array $data = [],
        array $meta = []
    ): self {
        return new self(true, $message, $data, [], $meta);
    }

    public static function error(
        string $message,
        array $errors = [],
        array $meta = []
    ): self {
        return new self(false, $message, [], $errors, $meta);
    }
}

class TagBulkActionResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly array $results,
        public readonly array $errors = [],
        public readonly array $meta = []
    ) {}
}
