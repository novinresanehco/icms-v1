<?php

namespace App\Core\Auth\DTO;

use App\Core\Shared\DTO\DataTransferObject;
use JsonSerializable;

class PermissionData extends DataTransferObject implements JsonSerializable
{
    public string $name;
    public string $displayName;
    public ?string $description;
    public string $group;
    public ?array $settings;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->displayName = $data['display_name'];
        $this->description = $data['description'] ?? null;
        $this->group = $data['group'];
        $this->settings = $data['settings'] ?? [];
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors['name'] = 'Permission name is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $this->name)) {
            $errors['name'] = 'Permission name can only contain letters, numbers, underscores, dashes and dots';
        }

        if (empty($this->displayName)) {
            $errors['display_name'] = 'Display name is required';
        }

        if ($this->description && strlen($this->description) > 500) {
            $errors['description'] = 'Description cannot exceed 500 characters';
        }

        if (empty($this->group)) {
            $errors['group'] = 'Permission group is required';
        }

        return $errors;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'group' => $this->group,
            'settings' => $this->settings,
        ];
    }
}
