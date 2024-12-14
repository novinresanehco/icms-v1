```php
namespace App\Core\Security;

class DataFlowSecurity implements SecurityInterface
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $audit;
    
    public function secureDataFlow(array $data, string $context): SecureResult
    {
        $this->validator->validateDataContext($context);
        
        $secured = $this->encryptSensitiveData($data);
        $this->audit->logDataFlow($context, $data);
        
        return new SecureResult($secured);
    }

    private function encryptSensitiveData(array $data): array
    {
        $result = [];
        
        foreach ($data as $key => $value) {
            $result[$key] = $this->processSensitiveField($key, $value);
        }
        
        return $result;
    }

    private function processSensitiveField(string $key, $value): mixed
    {
        if ($this->isSensitive($key)) {
            return $this->encryption->encrypt($value);
        }
        return $value;
    }

    private function isSensitive(string $key): bool
    {
        return in_array($key, config('security.sensitive_fields'));
    }
}
```
