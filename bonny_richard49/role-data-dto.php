<?php

namespace App\Core\Auth\DTO;

use App\Core\Shared\DTO\DataTransferObject;
use JsonSerializable;

class RoleData extends DataTransferObject implements JsonSerializable
{
    public string $name;
    public string $displayName;
    public ?string $description;
    public ?int $parentId;
    public array $permissions;
    public ?array $settings;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->displayName = $data['display_name'];
        $this->description = $data['description'] ?? null;
        $this->parentId = $data['parent_id'] ?? null;
        $this->permissions = $data['permissions'] ?? [];
        $this->settings = $data['settings'] ?? [];
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors['name'] = 'Role name is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $this->name)) {
            $errors['name'] = 'Role name can only contain letters, numbers, underscores and dashes';
        }

        if (empty($this->displayName)) {
            $errors['display_name'] = 'Display name is required';
        }

        if ($this->description && strlen($this->description) > 500) {
            $errors['description'] = 'Description cannot exceed 500 characters';
        }

        return $errors;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'parent_id' => $this->parentId,
            'permissions' => $this->permissions,
            'settings' => $this->settings,
        ];
    }
}
