<?php

namespace App\Core\Settings\DTO;

use App\Core\Shared\DTO\DataTransferObject;
use JsonSerializable;

class SettingData extends DataTransferObject implements JsonSerializable
{
    public string $key;
    public mixed $value;
    public string $group;
    public bool $isSchema;
    public ?array $schema;
    public ?array $metadata;

    public function __construct(array $data)
    {
        $this->key = $data['key'];
        $this->value = $data['value'];
        $this->group = $data['group'];
        $this->isSchema = $data['is_schema'] ?? false;
        $this->schema = $data['schema'] ?? null;
        $this->metadata = $data['metadata'] ?? null;
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->key)) {
            $errors['key'] = 'Setting key is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $this->key)) {
            $errors['key'] = 'Setting key can only contain letters, numbers, underscores, dashes and dots';
        }

        if ($this->value === null) {
            $errors['value'] = 'Setting value is required';
        }

        if (empty($this->group)) {
            $errors['group'] = 'Setting group is required';
        }

        if ($this->isSchema && empty($this->schema)) {
            $errors['schema'] = 'Schema is required when setting is marked as schema';
        }

        return $errors;
    }

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'group' => $this->group,
            'is_schema' => $this->isSchema,
            'schema' => $this->schema,
            'metadata' => $this->metadata,
        ];
    }
}
