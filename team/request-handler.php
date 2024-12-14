<?php

namespace App\Core\Http;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Response\ResponseHandler;
use Illuminate\Http\Request;

abstract class CriticalRequestHandler
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected ResponseHandler $response;

    public function handleRequest(Request $request, string $operation): mixed
    {
        try {
            // Validate request
            $validated = $this->validateRequest($request, $operation);
            
            // Execute operation
            $result = $this->executeOperation($validated, $operation);
            
            // Build response
            return $this->response->success($result);
            
        } catch (\Throwable $e) {
            return $this->handleRequestFailure($e);
        }
    }

    protected function validateRequest(Request $request, string $operation): array
    {
        // Get validation rules
        $rules = $this->getValidationRules($operation);
        
        // Validate input
        $validated = $this->validator->validate($request->all(), $rules);
        
        // Security validation
        $this->security->validateRequest($validated, $operation);
        
        return $validated;
    }

    abstract protected function executeOperation(array $data, string $operation): mixed;

    abstract protected function getValidationRules(string $operation): array;

    protected function handleRequestFailure(\Throwable $e): mixed
    {
        if ($e instanceof ValidationException) {
            return $this->response->validationError($e->getMessage());
        }

        if ($e instanceof SecurityException) {
            return $this->response->securityError($e->getMessage());
        }

        if ($e instanceof BusinessException) {
            return $this->response->businessError($e->getMessage());
        }

        // Log system error
        $this->security->logError($e);

        return $this->response->systemError('Internal system error');
    }
}
