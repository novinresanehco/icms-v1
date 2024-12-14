<?php

namespace App\Core\Template\DTO;

use App\Core\Shared\DTO\DataTransferObject;
use JsonSerializable;

class TemplateData extends DataTransferObject implements JsonSerializable
{
    public string $name;
    public string $slug;
    public string $content;
    public string $type;
    public ?string $description;
    public ?int $parentId;
    public bool $isActive;
    public bool $isDefault;
    public ?array $settings;
    public ?array $variables;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->slug = $data['slug'] ?? \Str::slug($data['name']);
        $this->content = $data['content'];
        $this->type = $data['type'];
        $this->description = $data['description'] ?? null;
        $this->parentId = $data['parent_id'] ?? null;
        $this->isActive = $data['is_active'] ?? true;
        $this->isDefault = $data['is_default'] ?? false;
        $this->settings = $data['settings'] ?? [];
        $this->variables = $data['variables'] ?? [];
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors['name'] = 'Template name is required';
        }

        if (empty($this->content)) {
            $errors['content'] = 'Template content is required';
        }

        if (empty($this->type)) {
            $errors['type'] = 'Template type is required';
        }

        if ($this->description && strlen($this->description) > 500) {
            $errors['description'] = 'Description cannot exceed 500 characters';
        }

        if ($this->isDefault && !$this->isActive) {
            $errors['is_default'] = 'Default template must be active';
        }

        return $errors;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'content' => $this->content,
            'type' => $this->type,
            'description' => $this->description,
            'parent_id' => $this->parentId,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
            'settings' => $this->settings,
            'variables' => $this->variables,
        ];
    }
}
