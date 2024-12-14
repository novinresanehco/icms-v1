<?php

namespace App\Core\Integration;

use App\Core\Security\SecurityManager;
use App\Core\Monitor\SystemMonitor;

final class IntegrationManager
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private ValidationService $validator;
    private ProtocolHandler $protocol;

    public function handleRequest(Request $request): Response
    {
        $operationId = $this->monitor->startOperation();
        
        try {
            $this->validateRequest($request);
            $result = $this->processSecureRequest($request);
            return $this->createResponse($result);
        } catch (\Throwable $e) {
            $this->handleFailure($e, $operationId);
            throw $e;
        }
    }

    private function processSecureRequest(Request $request): mixed
    {
        return $this->security->executeProtected(function() use ($request) {
            $data = $this->protocol->decode($request->getData());
            $processed = $this->processData($data);
            return $this->protocol->encode($processed);
        });
    }

    private function processData(array $data): array
    {
        $this->validator->validateData($data);
        return $this->executeOperation($data);
    }
}

final class ProtocolHandler
{
    private EncryptionService $encryption;
    private array $protocols = [];

    public function registerProtocol(string $type, Protocol $protocol): void
    {
        $this->protocols[$type] = $protocol;
    }

    public function decode(string $data): array
    {
        $decrypted = $this->encryption->decrypt($data);
        return json_decode($decrypted, true);
    }

    public function encode(array $data): string
    {
        $json = json_encode($data);
        return $this->encryption->encrypt($json);
    }
}

final class ValidationService
{
    private array $rules = [];

    public function validateData(array $data): void
    {
        foreach ($this->rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException("Invalid field: $field");
            }
        }
    }

    private function validateField($value, string $rule): bool
    {
        return match($rule) {
            'required' => !is_null($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'numeric' => is_numeric($value),
            default => $this->validateCustomRule($value, $rule)
        };
    }
}

interface Protocol
{
    public function validate(array $data): bool;
    public function process(array $data): array;
    public function format(array $result): array;
}

class ValidationException extends \Exception {}
