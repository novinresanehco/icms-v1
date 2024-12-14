<?php

namespace App\Core\Backup\DTO;

use App\Core\Shared\DTO\DataTransferObject;
use JsonSerializable;

class BackupData extends DataTransferObject implements JsonSerializable
{
    public string $name;
    public string $type;
    public ?string $description;
    public array $includes;
    public array $excludes;
    public array $options;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->type = $data['type'];
        $this->description = $data['description'] ?? null;
        $this->includes = $data['includes'] ?? [];
        $this->excludes = $data['excludes'] ?? [];
        $this->options = $data['options'] ?? [];
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors['name'] = 'Backup name is required';
        }

        if (empty($this->type)) {
            $errors['type'] = 'Backup type is required';
        } elseif (!in_array($this->type, ['full', 'database', 'files', 'custom'])) {
            $errors['type'] = 'Invalid backup type';
        }

        if ($this->type === 'custom' && empty($this->includes)) {
            $errors['includes'] = 'Custom backup must specify included items';
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
            'type' => $this->type,
            'description' => $this->description,
            'includes' => $this->includes,
            'excludes' => $this->excludes,
            'options' => $this->options,
        ];
    }
}
