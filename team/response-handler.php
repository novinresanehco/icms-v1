<?php

namespace App\Core\Response;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Monitoring\MonitoringService;

class ResponseHandler
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    
    private const RESPONSE_CODES = [
        'success' => 200,
        'validation_error' => 422,
        'security_error' => 403,
        'system_error' => 500
    ];

    public function handleResponse($data, string $type = 'success'): array
    {
        $operationId = $this->monitor->startOperation('response');
        
        try {
            // Validate response data
            $validated = $this->validator->validateRequest($data);
            
            // Apply security measures
            $secured = $this->security->secureResponse($validated);
            
            // Build response
            $response = $this->buildResponse($secured, $type);
            
            // Validate final response
            $this->validateResponse($response);
            
            return $response;
            
        } catch (\Throwable $e) {
            return $this->handleResponseError($e);
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function buildResponse(array $data, string $type): array
    {
        return [
            'status' => self::RESPONSE_CODES[$type] ?? 500,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
            'request_id' => request()->id()
        ];
    }

    private function validateResponse(array $response): void
    {
        if (!isset($response['status']) || 
            !isset($response['data']) || 
            !isset($response['timestamp'])) {
            throw new ResponseException('Invalid response structure');
        }

        if (!is_int($response['status']) || 
            $response['status'] < 100 || 
            $response['status'] > 599) {
            throw new ResponseException('Invalid status code');
        }
    }

    private function handleResponseError(\Throwable $e): array
    {
        $this->monitor->recordFailure('response', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return [
            'status' => 500,
            'error' => 'Internal system error',
            'timestamp' => now()->toIso8601String(),
            'request_id' => request()->id()
        ];
    }
}
