<?php

namespace App\Core\Tag\DTO;

use App\Core\Shared\DTO\DataTransferObject;
use JsonSerializable;

class TagData extends DataTransferObject implements JsonSerializable
{
    public string $name;
    public ?string $slug;
    public ?string $description;
    public ?array $meta;
    public bool $isActive;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->slug = $data['slug'] ?? null;
        $this->description = $data['description'] ?? null;
        $this->meta = $data['meta'] ?? [];
        $this->isActive = $data['is_active'] ?? true;
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors['name'] = 'Tag name is required';
        }

        if (strlen($this->name) > 50) {
            $errors['name'] = 'Tag name cannot exceed 50 characters';
        }

        if ($this->description && strlen($this->description) > 500) {
            $errors['description'] = 'Tag description cannot exceed 500 characters';
        }

        return $errors;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'meta' => $this->meta,
            'is_active' => $this->isActive,
        ];
    }
}
