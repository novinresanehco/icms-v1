<?php

namespace App\Core\Config;

class ConfigurationManager implements ConfigManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private EncryptionService $encryption;
    private array $config = [];

    public function set(string $key, $value, array $options = []): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'config.set',
                'key' => $key,
            ]);

            $validated = $this->validator->validate([
                'key' => $key,
                'value' => $value,
                'options' => $options
            ], [
                'key' => 'required|string',
                'value' => 'required',
                'options' => 'array'
            ]);

            $shouldEncrypt = $options['encrypt'] ?? $this->shouldEncrypt($key);
            $processedValue = $shouldEncrypt 
                ? $this->encryption->encrypt(serialize($value))
                : serialize($value);

            $config = $this->repository->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $processedValue,
                    'encrypted' => $shouldEncrypt,
                    'metadata' => array_merge(
                        $options['metadata'] ?? [],
                        ['updated_at' => now()]
                    )
                ]
            );

            // Update runtime cache
            $this->config[$key] = $value;

            // Clear cache
            $this->cache->tags(['config'])->forget($key);
            $this->cache->tags(['config'])->forget('all_config');

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ConfigurationException('Failed to set configuration', 0, $e);
        }
    }

    public function get(string $key, $default = null) 
    {
        try {
            // Check runtime cache
            if (isset($this->config[$key])) {
                return $this->config[$key];
            }

            // Check cache
            $cached = $this->cache->tags(['config'])->get($key);
            if ($cached !== null) {
                $this->config[$key] = $cached;
                return $cached;
            }

            // Load from repository
            $config = $this->repository->findByKey($key);
            
            if (!$config) {
                return $default;
            }

            $value = $config->encrypted
                ? unserialize($this->encryption->decrypt($config->value))
                : unserialize($config->value);

            // Update caches
            $this->config[$key] = $value;
            $this->cache->tags(['config'])->put($key, $value, 3600);

            return $value;

        } catch (\Exception $e) {
            throw new ConfigurationException("Failed to get configuration: {$key}", 0, $e);
        }
    }

    public function remove(string $key): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'config.remove',
                'key' => $key
            ]);

            $this->repository->deleteByKey($key);
            
            // Clear caches
            unset($this->config[$key]);
            $this->cache->tags(['config'])->forget($key);
            $this->cache->tags(['config'])->forget('all_config');

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ConfigurationException("Failed to remove configuration: {$key}", 0, $e);
        }
    }

    public function all(): array 
    {
        try {
            return $this->cache->tags(['config'])->remember(
                'all_config',
                3600,
                function() {
                    $configs = $this->repository->all();
                    $result = [];

                    foreach ($configs as $config) {
                        $value = $config->encrypted
                            ? unserialize($this->encryption->decrypt($config->value))
                            : unserialize($config->value);
                            
                        $result[$config->key] = $value;
                    }

                    $this->config = array_merge($this->config, $result);
                    return $result;
                }
            );

        } catch (\Exception $e) {
            throw new ConfigurationException('Failed to get all configurations', 0, $e);
        }
    }

    public function has(string $key): bool 
    {
        return isset($this->config[$key]) || 
               $this->cache->tags(['config'])->has($key) ||
               $this->repository->exists($key);
    }

    public function clear(): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'config.clear'
            ]);

            $this->repository->clear();
            $this->config = [];
            $this->cache->tags(['config'])->flush();

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ConfigurationException('Failed to clear configurations', 0, $e);
        }
    }

    private function shouldEncrypt(string $key): bool 
    {
        $sensitivePatterns = [
            '/password/i',
            '/secret/i',
            '/key/i',
            '/token/i',
            '/credential/i'
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    public function export(): array 
    {
        try {
            $configs = $this->all();
            
            return [
                'data' => $configs,
                'metadata' => [
                    'exported_at' => now(),
                    'version' => config('app.version'),
                    'checksum' => $this->generateChecksum($configs)
                ]
            ];

        } catch (\Exception $e) {
            throw new ConfigurationException('Failed to export configurations', 0, $e);
        }
    }

    public function import(array $data): bool 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'config.import',
                'data' => $data
            ]);

            $this->validator->validate($data, [
                'data' => 'required|array',
                'metadata' => 'required|array'
            ]);

            if ($this->generateChecksum($data['data']) !== $data['metadata']['checksum']) {
                throw new ConfigurationException('Invalid configuration checksum');
            }

            foreach ($data['data'] as $key => $value) {
                $this->set($key, $value);
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ConfigurationException('Failed to import configurations', 0, $e);
        }
    }

    private function generateChecksum(array $data): string 
    {
        return hash('sha256', serialize($data));
    }
}
