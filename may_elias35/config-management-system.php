<?php

namespace App\Core\Config;

class ConfigurationManager implements ConfigInterface
{
    private SecurityManager $security;
    private ConfigStore $store;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function get(string $key, $default = null): mixed
    {
        return $this->security->executeCriticalOperation(
            new GetConfigOperation(
                $key,
                $default,
                $this->store,
                $this->encryption
            )
        );
    }

    public function set(string $key, $value): void
    {
        $this->security->executeCriticalOperation(
            new SetConfigOperation(
                $key,
                $value,
                $this->store,
                $this->encryption,
                $this->validator
            )
        );
    }
}

class GetConfigOperation implements CriticalOperation
{
    private string $key;
    private $default;
    private ConfigStore $store;
    private EncryptionService $encryption;

    public function execute(): mixed
    {
        $value = $this->store->get($this->key);
        
        if ($value === null) {
            return $this->default;
        }

        if ($value instanceof EncryptedValue) {
            return $this->encryption->decrypt($value);
        }

        return $value;
    }

    public function getRequiredPermissions(): array
    {
        return ['config.read'];
    }
}

class SetConfigOperation implements CriticalOperation
{
    private string $key;
    private $value;
    private ConfigStore $store;
    private EncryptionService $encryption;
    private ValidationService $validator;

    public function execute(): void
    {
        $this->validateConfig($this->key, $this->value);
        
        if ($this->shouldEncrypt($this->key)) {
            $value = $this->encryption->encrypt($this->value);
        } else {
            $value = $this->value;
        }

        $this->store->set($this->key, $value);
    }

    private function validateConfig(string $key, $value): void
    {
        if (!$this->validator->validateConfigKey($key)) {
            throw new ConfigurationException('Invalid configuration key');
        }

        if (!$this->validator->validateConfigValue($value)) {
            throw new ConfigurationException('Invalid configuration value');
        }
    }

    private function shouldEncrypt(string $key): bool
    {
        return in_array($key, [
            'security.keys',
            'auth.secrets',
            'api.tokens',
            'database.credentials'
        ]);
    }
}

class ConfigStore
{
    private Database $db;
    private CacheManager $cache;
    private array $runtime = [];

    public function get(string $key)
    {
        if (isset($this->runtime[$key])) {
            return $this->runtime[$key];
        }

        return $this->cache->remember(
            "config.$key",
            fn() => $this->db->table('configurations')
                ->where('key', $key)
                ->value('value')
        );
    }

    public function set(string $key, $value): void
    {
        $this->runtime[$key] = $value;

        DB::transaction(function() use ($key, $value) {
            $this->db->table('configurations')
                ->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value]
                );
            
            $this->cache->forget("config.$key");
        });
    }
}

class EncryptedValue
{
    private string $value;
    private string $iv;
    private string $tag;

    public function __construct(string $value, string $iv, string $tag)
    {
        $this->value = $value;
        $this->iv = $iv;
        $this->tag = $tag;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getIv(): string
    {
        return $this->iv;
    }

    public function getTag(): string
    {
        return $this->tag;
    }
}

class ConfigLoader
{
    private ConfigStore $store;
    private FileSystem $files;
    private ValidationService $validator;

    public function loadFromFile(string $path): void
    {
        if (!$this->files->exists($path)) {
            throw new ConfigurationException("Configuration file not found: $path");
        }

        $config = require $path;
        
        if (!is_array($config)) {
            throw new ConfigurationException("Invalid configuration format");
        }

        $this->validateAndLoad($config);
    }

    private function validateAndLoad(array $config): void
    {
        foreach ($config as $key => $value) {
            if (!$this->validator->validateConfig($key, $value)) {
                throw new ConfigurationException("Invalid configuration: $key");
            }

            $this->store->set($key, $value);
        }
    }
}

class ConfigurationSchema
{
    private array $schema = [];

    public function define(string $key, array $rules): void
    {
        $this->schema[$key] = $rules;
    }

    public function validate(string $key, $value): bool
    {
        if (!isset($this->schema[$key])) {
            return false;
        }

        foreach ($this->schema[$key] as $rule => $constraint) {
            if (!$this->validateRule($value, $rule, $constraint)) {
                return false;
            }
        }

        return true;
    }

    private function validateRule($value, string $rule, $constraint): bool
    {
        return match($rule) {
            'type' => $this->validateType($value, $constraint),
            'pattern' => $this->validatePattern($value, $constraint),
            'range' => $this->validateRange($value, $constraint),
            default => true
        };
    }
}
