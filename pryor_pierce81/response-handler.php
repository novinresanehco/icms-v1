<?php

namespace App\Core\Http;

class CriticalResponseHandler
{
    private $security;
    private $monitor;

    public function handle($data): Response
    {
        $this->monitor->startResponse();

        try {
            // Sanitize response data
            $sanitized = $this->security->sanitizeOutput($data);

            // Add security headers
            $headers = $this->getSecurityHeaders();

            // Create response
            $response = new Response($sanitized, 200, $headers);

            // Track metrics
            $this->monitor->trackResponse($response);

            return $response;

        } catch (\Exception $e) {
            return $this->handleResponseFailure($e);
        }
    }

    private function getSecurityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin'
        ];
    }

    private function handleResponseFailure(\Exception $e): Response
    {
        $this->monitor->logResponseFailure($e);
        return new Response(['error' => 'Response Error'], 500);
    }
}
