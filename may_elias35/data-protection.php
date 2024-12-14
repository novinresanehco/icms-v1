<?php

namespace App\Core\Security;

use App\Core\Monitoring\SystemMonitor;
use App\Core\Cache\CacheManager;

class DataProtectionManager implements DataProtectionInterface
{
    private EncryptionManager $encryption;
    private SystemMonitor $monitor;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        EncryptionManager $encryption,
        SystemMonitor $monitor,
        CacheManager $cache,
        array $config
    ) {
        $this->encryption = $encryption;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function protectData(array $data, string $context): array
    {
        $monitoringId = $this->monitor->startOperation('data_protection');
        
        try {
            $this->validateData($data);
            $rules = $this->getProtectionRules($context);
            
            $protected = [];
            foreach ($data as $key => $value) {
                $protected[$key] = $this->protectField($value, $rules[$key] ?? []);
            }

            $this->monitor->recordSuccess($monitoringId);
            
            return $protected;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new DataProtectionException('Data protection failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function unprotectData(array $protected, string $context): array
    {
        $monitoringId = $this->monitor->startOperation('data_unprotection');
        
        try {
            $rules = $this->getProtectionRules($context);
            
            $data = [];
            foreach ($protected as $key => $value) {
                $data[$key] = $this->unprotectField($value, $rules[$key] ?? []);
            }

            $this->monitor->recordSuccess($monitoringId);
            
            return $data;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new DataProtectionException('Data unprotection failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function protectField($value, array $rules): mixed
    {
        if (empty($rules)) {
            return $value;
        }

        return match($rules['type']) {
            'encrypt' => $this->encryption->encrypt($value, $rules),
            'mask' => $this->maskValue($value, $rules),
            'hash' => $this->hashValue($value, $rules),
            default => $value
        };
    }

    private function unprotectField($value, array $rules): mixed
    {
        if (empty($rules)) {
            return $value;
        }

        return match($rules['type']) {
            'encrypt' => $this->encryption->decrypt($value),
            'mask' => $this->unmaskValue($value, $rules),
            'hash' => throw new DataProtectionException('Cannot unprotect hashed value'),
            default => $value
        };
    }

    private function maskValue(string $value, array $rules): string
    {
        $pattern = $rules['pattern'] ?? 'X';
        $keepStart = $rules['keep_start'] ?? 0;
        $keepEnd = $rules['keep_end'] ?? 0;

        $length = strlen($value);
        $maskLength = $length - $keepStart - $keepEnd;

        if ($maskLength <= 0) {
            return $value;
        }

        $start = substr($value, 0, $keepStart);
        $middle = str_repeat($pattern, $maskLength);
        $end = substr($value, -$keepEnd);

        return $start . $middle . $end;
    }

    private function unmaskValue(string $masked, array $rules): string
    {
        $cacheKey = "unmasked:{$masked}";
        return $this->cache->get($cacheKey);
    }

    private function hashValue(string $value, array $rules): string
    {
        $algorithm = $rules['algorithm'] ?? PASSWORD_DEFAULT;
        $options = $rules['options'] ?? [];

        return password_hash($value, $algorithm, $options);
    }

    private function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->isValidDataType($value)) {
                throw new DataProtectionException("Invalid data type for field: {$key}");
            }
        }
    }

    private function isValidDataType($value): bool
    {
        return is_scalar($value) || 
               is_array($value) || 
               $value instanceof \Stringable;
    }

    private function getProtectionRules(string $context): array
    {
        return $this->config['protection_rules'][$context] ?? [];
    }
}
