<?php

namespace App\Core\Settings;

class SettingsManager
{
    private SettingsRepository $repository;
    private CacheManager $cache;
    private Validator $validator;
    private array $definitions = [];

    public function get(string $key, $default = null)
    {
        return $this->cache->remember("setting.$key", function() use ($key, $default) {
            $setting = $this->repository->findByKey($key);
            return $setting ? $setting->getValue() : $default;
        });
    }

    public function set(string $key, $value): void
    {
        $this->validateSetting($key, $value);
        $this->repository->save(new Setting($key, $value));
        $this->cache->forget("setting.$key");
    }

    public function registerDefinition(SettingDefinition $definition): void
    {
        $this->definitions[$definition->getKey()] = $definition;
    }

    private function validateSetting(string $key, $value): void
    {
        if (isset($this->definitions[$key])) {
            $definition = $this->definitions[$key];
            $this->validator->validate($value, $definition->getRules());
        }
    }
}

class Setting
{
    private string $key;
    private $value;
    private array $metadata;
    private \DateTime $updatedAt;

    public function __construct(string $key, $value, array $metadata = [])
    {
        $this->key = $key;
        $this->value = $value;
        $this->metadata = $metadata;
        $this->updatedAt = new \DateTime();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }
}

class SettingsRepository
{
    private $connection;

    public function findByKey(string $key): ?Setting
    {
        $row = $this->connection->table('settings')
            ->where('key', $key)
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(Setting $setting): void
    {
        $this->connection->table('settings')->updateOrInsert(
            ['key' => $setting->getKey()],
            [
                'value' => json_encode($setting->getValue()),
                'metadata' => json_encode($setting->getMetadata()),
                'updated_at' => $setting->getUpdatedAt()
            ]
        );
    }

    public function delete(string $key): void
    {
        $this->connection->table('settings')
            ->where('key', $key)
            ->delete();
    }

    private function hydrate($row): Setting
    {
        return new Setting(
            $row->key,
            json_decode($row->value, true),
            json_decode($row->metadata, true)
        );
    }
}

class SettingDefinition
{
    private string $key;
    private string $type;
    private array $rules;
    private $default;
    private bool $encrypted;

    public function __construct(
        string $key,
        string $type,
        array $rules = [],
        $default = null,
        bool $encrypted = false
    ) {
        $this->key = $key;
        $this->type = $type;
        $this->rules = $rules;
        $this->default = $default;
        $this->encrypted = $encrypted;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function getDefault()
    {
        return $this->default;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }
}

class SettingsGroup
{
    private string $name;
    private array $settings;
    private array $metadata;

    public function __construct(string $name, array $settings = [], array $metadata = [])
    {
        $this->name = $name;
        $this->settings = $settings;
        $this->metadata = $metadata;
    }

    public function addSetting(SettingDefinition $definition): void
    {
        $this->settings[$definition->getKey()] = $definition;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class SettingsExporter
{
    private SettingsManager $manager;

    public function export(array $keys = []): array
    {
        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = $this->manager->get($key);
        }
        return $settings;
    }

    public function import(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->manager->set($key, $value);
        }
    }
}

class SettingsValidator
{
    public function validate($value, array $rules): void
    {
        foreach ($rules as $rule) {
            if (!$this->evaluateRule($rule, $value)) {
                throw new SettingsValidationException("Validation failed for rule: {$rule}");
            }
        }
    }

    private function evaluateRule(string $rule, $value): bool
    {
        switch ($rule) {
            case 'required':
                return !empty($value);
            case 'integer':
                return is_int($value);
            case 'string':
                return is_string($value);
            case 'boolean':
                return is_bool($value);
            case 'array':
                return is_array($value);
            default:
                if (preg_match('/^min:(\d+)$/', $rule, $matches)) {
                    return $value >= $matches[1];
                }
                if (preg_match('/^max:(\d+)$/', $rule, $matches)) {
                    return $value <= $matches[1];
                }
                return true;
        }
    }
}
