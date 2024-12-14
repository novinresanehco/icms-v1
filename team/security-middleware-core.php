<?php

namespace App\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{
    ValidationService,
    RateLimiterService,
    TokenService
};
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityMiddleware
{
    private SecurityManager $security;
    private ValidationService $validator;
    private RateLimiterService $rateLimiter;
    private TokenService $token;

    private const CACHE_TTL = 300; // 5 minutes
    private const MAX_REQUEST_SIZE = 10485760; // 10MB
    private const RATE_LIMIT = 1000; // per minute
    private const SECURE_HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'",
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Permissions-Policy' => 'geolocation=(), camera=(), microphone=()'
    ];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        RateLimiterService $rateLimiter,
        TokenService $token
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->rateLimiter = $rateLimiter;
        $this->token = $token;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            // Pre-processing security checks
            $this->performSecurityChecks($request);

            // Process request
            $response = $next($request);

            // Post-processing security measures
            return $this->secureResponse($response);

        } catch (\Exception $e) {
            $this->handleSecurityException($e, $request);
            throw $e;
        }
    }

    protected function performSecurityChecks(Request $request): void
    {
        // Verify request size
        if ($request->server('CONTENT_LENGTH') > self::MAX_REQUEST_SIZE) {
            throw new SecurityException('Request size exceeds limit');
        }

        // Check rate limiting
        $this->checkRateLimit($request);

        // Validate request
        $this->validateRequest($request);

        // Verify authentication
        $this->verifyAuthentication($request);

        // Check for suspicious patterns
        $this->detectSuspiciousActivity($request);

        // Validate CSRF token for non-read operations
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            $this->validateCsrfToken($request);
        }
    }

    protected function checkRateLimit(Request $request): void
    {
        $key = $this->getRateLimitKey($request);
        
        if (!$this->rateLimiter->attempt($key, self::RATE_LIMIT)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    protected function validateRequest(Request $request): void
    {
        // Validate headers
        $this->validateHeaders($request);

        // Validate query parameters
        if ($request->query->count() > 0) {
            $this->validateQueryParameters($request);
        }

        // Validate request body
        if ($request->isMethod('POST') || $request->isMethod('PUT')) {
            $this->validateRequestBody($request);
        }
    }

    protected function validateHeaders(Request $request): void
    {
        $requiredHeaders = [
            'Host',
            'User-Agent',
            'Accept',
            'Accept-Language',
            'Accept-Encoding'
        ];

        foreach ($requiredHeaders as $header) {
            if (!$request->headers->has($header)) {
                throw new ValidationException("Missing required header: {$header}");
            }
        }

        // Validate Content-Type for non-GET requests
        if (!$request->isMethod('GET') && !$request->headers->has('Content-Type')) {
            throw new ValidationException('Missing Content-Type header');
        }
    }

    protected function validateQueryParameters(Request $request): void
    {
        foreach ($request->query->all() as $key => $value) {
            if (!$this->validator->validateQueryParameter($key, $value)) {
                throw new ValidationException("Invalid query parameter: {$key}");
            }
        }
    }

    protected function validateRequestBody(Request $request): void
    {
        if ($request->isJson()) {
            $this->validateJsonBody($request);
        } else if ($request->hasFile('*')) {
            $this->validateFileUploads($request);
        } else {
            $this->validateFormData($request);
        }
    }

    protected function validateJsonBody(Request $request): void
    {
        $data = $request->json()->all();
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException('Invalid JSON body');
        }

        $this->validator->validateData($data);
    }

    protected function validateFileUploads(Request $request): void
    {
        foreach ($request->allFiles() as $file) {
            if (!$file->isValid()) {
                throw new ValidationException('Invalid file upload');
            }

            if ($file->getSize() > self::MAX_REQUEST_SIZE) {
                throw new ValidationException('File size exceeds limit');
            }
        }
    }

    protected function validateFormData(Request $request): void
    {
        foreach ($request->all() as $key => $value) {
            if (!$this->validator->validateFormField($key, $value)) {
                throw new ValidationException("Invalid form field: {$key}");
            }
        }
    }

    protected function verifyAuthentication(Request $request): void
    {
        if ($this->requiresAuthentication($request)) {
            $token = $request->bearerToken();
            
            if (!$token) {
                throw new SecurityException('Authentication required');
            }

            if (!$this->token->verify($token)) {
                throw new SecurityException('Invalid authentication token');
            }
        }
    }

    protected function detectSuspiciousActivity(Request $request): void
    {
        $suspicious = $this->security->detectSuspiciousPatterns([
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'method' => $request->method(),
            'path' => $request->path(),
            'headers' => $request->headers->all(),
            'query' => $request->query->all()
        ]);

        if ($suspicious) {
            $this->handleSuspiciousActivity($request, $suspicious);
        }
    }

    protected function validateCsrfToken(Request $request): void
    {
        if (!$this->token->validateCsrf($request->header('X-CSRF-TOKEN'))) {
            throw new SecurityException('Invalid CSRF token');
        }
    }

    protected function secureResponse($response): mixed
    {
        // Add security headers
        foreach (self::SECURE_HEADERS as $header => $value) {
            $response->headers->set($header, $value);
        }

        // Remove sensitive headers
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // Add request ID for tracking
        $response->headers->set(
            'X-Request-ID',
            request()->header('X-Request-ID', uniqid())
        );

        return $response;
    }

    protected function handleSecurityException(\Exception $e, Request $request): void
    {
        Log::error('Security exception', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'ip' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
            'user_agent' => $request->userAgent()
        ]);

        $this->security->logSecurityEvent(
            get_class($e),
            $request->ip(),
            $e->getMessage()
        );
    }

    protected function handleSuspiciousActivity(Request $request, array $patterns): void
    {
        Log::warning('Suspicious activity detected', [
            'patterns' => $patterns,
            'ip' => $request->ip(),
            'path' => $request->path()
        ]);

        $this->security->flagSuspiciousActivity(
            $request->ip(),
            $patterns
        );
    }

    protected function getRateLimitKey(Request $request): string
    {
        return 'rate_limit:' . md5(
            $request->ip() .
            $request->path() .
            $request->header('X-API-Key', '')
        );
    }

    protected function requiresAuthentication(Request $request): bool
    {
        return !in_array($request->path(), [
            'login',
            'register',
            'password/reset',
            'verify-email'
        ]);
    }
}
