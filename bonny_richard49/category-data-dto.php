<?php

namespace App\Core\Category\DTO;

use App\Core\Shared\DTO\DataTransferObject;
use JsonSerializable;

class CategoryData extends DataTransferObject implements JsonSerializable
{
    public string $name;
    public ?string $slug;
    public ?string $description;
    public ?int $parentId;
    public ?int $order;
    public ?array $meta;
    public bool $isActive;
    public ?string $template;
    public ?array $settings;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->slug = $data['slug'] ?? null;
        $this->description = $data['description'] ?? null;
        $this->parentId = $data['parent_id'] ?? null;
        $this->order = $data['order'] ?? null;
        $this->meta = $data['meta'] ?? [];
        $this->isActive = $data['is_active'] ?? true;
        $this->template = $data['template'] ?? null;
        $this->settings = $data['settings'] ?? [];
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors['name'] = 'Category name is required';
        }

        if (strlen($this->name) > 100) {
            $errors['name'] = 'Category name cannot exceed 100 characters';
        }

        if ($this->description && strlen($this->description) > 1000) {
            $errors['description'] = 'Category description cannot exceed 1000 characters';
        }

        if ($this->parentId === $this->id) {
            $errors['parent_id'] = 'Category cannot be its own parent';
        }

        return $errors;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'parent_id' => $this->parentId,
            'order' => $this->order,
            'meta' => $this->meta,
            'is_active' => $this->isActive,
            'template' => $this->template,
            'settings' => $this->settings,
        ];
    }
}
