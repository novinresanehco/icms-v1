<?php

namespace App\Core\Http\Middleware;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use Illuminate\Http\{Request, Response};
use Symfony\Component\HttpKernel\Exception\HttpException;

class CMSMiddleware
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $this->validateRequest($request);
        $this->enforceSecurityHeaders();
        $this->validateContentType($request);
        $this->validateInputSize($request);
        
        try {
            $response = $next($request);
            return $this->processResponse($response);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->handleException($e);
        } finally {
            $this->cleanup();
        }
    }

    protected function validateRequest(Request $request): void
    {
        // IP whitelist check
        if (!empty($this->config['ip_whitelist'])) {
            if (!in_array($request->ip(), $this->config['ip_whitelist'])) {
                throw new HttpException(403, 'Access denied');
            }
        }

        // Rate limiting
        if (!$this->checkRateLimit($request)) {
            throw new HttpException(429, 'Too many requests');
        }

        // Content length
        if ($request->header('Content-Length') > $this->config['max_content_length']) {
            throw new HttpException(413, 'Content too large');
        }

        // Required headers
        foreach ($this->config['required_headers'] as $header) {
            if (!$request->hasHeader($header)) {
                throw new HttpException(400, "Missing required header: {$header}");
            }
        }
    }

    protected function enforceSecurityHeaders(): void
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => $this->buildCSP(),
            'Permissions-Policy' => $this->buildPermissionsPolicy(),
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'
        ];

        foreach ($headers as $key => $value) {
            header("{$key}: {$value}");
        }
    }

    protected function validateContentType(Request $request): void
    {
        $contentType = $request->header('Content-Type');
        
        if ($contentType && !in_array($contentType, $this->config['allowed_content_types'])) {
            throw new HttpException(415, 'Unsupported Media Type');
        }
    }

    protected function validateInputSize(Request $request): void
    {
        foreach ($request->all() as $key => $value) {
            if (is_string($value) && strlen($value) > $this->config['max_input_length']) {
                throw new HttpException(413, "Input field '{$key}' exceeds maximum length");
            }
        }
    }

    protected function processResponse(Response $response): Response
    {
        // Remove sensitive headers
        foreach ($this->config['sensitive_headers'] as $header) {
            $response->headers->remove($header);
        }

        // Add security headers
        foreach ($this->getSecurityHeaders() as $header => $value) {
            $response->headers->set($header, $value);
        }

        // Validate response size
        if ($response->headers->get('Content-Length') > $this->config['max_response_size']) {
            throw new HttpException(500, 'Response too large');
        }

        return $response;
    }

    protected function handleException(\Exception $e): Response
    {
        $statusCode = $e instanceof HttpException ? $e->getStatusCode() : 500;

        return response()->json([
            'error' => [
                'message' => $this->config['debug'] ? $e->getMessage() : 'Internal Server Error',
                'code' => $statusCode
            ]
        ], $statusCode);
    }

    protected function cleanup(): void
    {
        if (session()->has('temp_data')) {
            session()->remove('temp_data');
        }
    }

    protected function checkRateLimit(Request $request): bool
    {
        $key = 'rate_limit:' . $request->ip();
        $limit = $this->config['rate_limit'];
        $window = $this->config['rate_limit_window'];

        $current = cache()->increment($key);
        if ($current === 1) {
            cache()->expire($key, $window);
        }

        return $current <= $limit;
    }

    protected function buildCSP(): string
    {
        $policies = [
            "default-src 'self'",
            "script-src 'self' 'strict-dynamic'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "block-all-mixed-content"
        ];

        return implode('; ', $policies);
    }

    protected function buildPermissionsPolicy(): string
    {
        $policies = [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=()',
            'usb=()',
            'magnetometer=()',
            'accelerometer=()',
            'gyroscope=()'
        ];

        return implode(', ', $policies);
    }

    protected function getSecurityHeaders(): array
    {
        return [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => $this->buildCSP(),
            'Permissions-Policy' => $this->buildPermissionsPolicy(),
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'
        ];
    }
}
