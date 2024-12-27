<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface 
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private ValidationConfig $config;
    private AuditLogger $audit;

    public function validate(array $data, array $rules): array
    {
        $monitorId = $this->metrics->startOperation('validation');
        
        try {
            // Pre-validation security check
            $this->security->validateDataAccess($data);
            
            // Execute validation
            $validated = $this->executeValidation($data, $rules);
            
            // Post-validation security check
            $this->security->validateDataIntegrity($validated);
            
            $this->metrics->recordSuccess($monitorId);
            return $validated;
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            $this->handleValidationFailure($e, $data, $rules);
            throw $e;
        }
    }

    public function validateRequest(Request $request): bool
    {
        return DB::transaction(function() use ($request) {
            // Validate request integrity
            $this->validateRequestIntegrity($request);
            
            // Validate headers
            $this->validateHeaders($request);
            
            // Validate body
            $this->validateBody($request);
            
            // Validate source
            $this->validateSource($request);
            
            return true;
        });
    }

    private function executeValidation(array $data, array $rules): array
    {
        $validated = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            
            // Required field check
            if (str_contains($rule, 'required') && is_null($value)) {
                throw new ValidationException("The {$field} field is required");
            }
            
            // Type validation
            if ($type = $this->extractType($rule)) {
                $this->validateType($value, $type, $field);
            }
            
            // Format validation
            if ($format = $this->extractFormat($rule)) {
                $this->validateFormat($value, $format, $field);
            }
            
            // Security validation
            if ($security = $this->extractSecurityRules($rule)) {
                $this->validateSecurity($value, $security, $field);
            }
            
            $validated[$field] = $value;
        }
        
        return $validated;
    }

    private function validateRequestIntegrity(Request $request): void
    {
        // Validate signature
        if (!$this->security->verifyRequestSignature($request)) {
            throw new SecurityException('Invalid request signature');
        }

        // Validate timestamp
        if (!$this->isValidTimestamp($request->timestamp)) {
            throw new SecurityException('Invalid request timestamp');
        }

        // Validate request format
        if (!$this->isValidFormat($request)) {
            throw new ValidationException('Invalid request format');
        }
    }

    private function validateHeaders(Request $request): void
    {
        $required = ['Authorization', 'Content-Type', 'Accept'];
        
        foreach ($required as $header) {
            if (!$request->hasHeader($header)) {
                throw new ValidationException("Missing required header: {$header}");
            }
        }

        // Validate authorization
        $this->validateAuthorization($request->header('Authorization'));

        // Validate content type
        $this->validateContentType($request->header('Content-Type'));
    }

    private function validateBody(Request $request): void
    {
        $body = $request->getContent();
        
        // Validate body size
        if (strlen($body) > $this->config->get('max_body_size')) {
            throw new ValidationException('Request body too large');
        }

        // Validate body format
        if (!$this->isValidBodyFormat($body)) {
            throw new ValidationException('Invalid body format');
        }

        // Validate body content
        if (!$this->isValidBodyContent($body)) {
            throw new ValidationException('Invalid body content');
        }
    }

    private function validateSource(Request $request): void
    {
        // Validate IP
        if (!$this->security->isAllowedIP($request->ip())) {
            throw new SecurityException('Invalid request source IP');
        }

        // Validate user agent
        if (!$this->isValidUserAgent($request->userAgent())) {
            throw new SecurityException('Invalid user agent');
        }

        // Validate origin
        if (!$this->security->isAllowedOrigin($request->header('Origin'))) {
            throw new SecurityException('Invalid request origin');
        }
    }

    private function handleValidationFailure(\Exception $e, array $data, array $rules): void
    {
        // Log validation failure
        $this->audit->log('validation.failure', [
            'error' => $e->getMessage(),
            'data' => $this->security->maskSensitiveData($data),
            'rules' => $rules
        ]);

        // Update failure metrics
        $this->metrics->incrementCounter('validation.failures');

        // Execute failure protocols
        $this->security->handleValidationFailure($e);
    }

    private function isValidTimestamp(string $timestamp): bool
    {
        $time = strtotime($timestamp);
        $now = time();
        
        return $time && abs($now - $time) <= $this->config->get('timestamp_tolerance');
    }

    private function isValidFormat(Request $request): bool
    {
        return $request->isJson() && $this->isValidJson($request->getContent());
    }

    private function isValidJson(string $content): bool
    {
        json_decode($content);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function validateAuthorization(string $auth): void
    {
        if (!str_starts_with($auth, 'Bearer ')) {
            throw new ValidationException('Invalid authorization format');
        }

        $token = substr($auth, 7);
        if (!$this->security->verifyToken($token)) {
            throw new SecurityException('Invalid authorization token');
        }
    }
}
