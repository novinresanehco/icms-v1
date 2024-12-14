<?php

namespace App\Core\Api;

class CriticalApiController
{
    private $security;
    private $cms;
    private $monitor;

    public function handle(Request $request): JsonResponse
    {
        $operationId = $this->monitor->startOperation();

        try {
            // Security validation
            $this->security->validateRequest($request);

            // Rate limiting
            $this->enforceRateLimit($request);

            // Process request
            $result = $this->cms->handleRequest($request);

            $this->monitor->trackSuccess($operationId);
            return new JsonResponse($result);

        } catch (SecurityException $e) {
            $this->handleSecurityFailure($e, $operationId);
            throw $e;
        } catch (ValidationException $e) {
            $this->handleValidationFailure($e, $operationId);
            throw $e;
        } catch (\Exception $e) {
            $this->handleSystemFailure($e, $operationId);
            throw $e;
        }
    }

    private function enforceRateLimit(Request $request): void
    {
        if (!$this->security->checkRateLimit($request)) {
            throw new RateLimitException();
        }
    }
}
