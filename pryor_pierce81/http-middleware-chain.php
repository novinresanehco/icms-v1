namespace App\Core\Http;

class SecurityMiddleware implements MiddlewareInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private ValidatorService $validator;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MetricsCollector $metrics,
        ValidatorService $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        try {
            $this->validateRequest($request);
            $this->checkRateLimit($request);
            $this->validateSecurityHeaders($request);
            $this->sanitizeInput($request);

            $response = $next($request);

            $this->validateResponse($response);
            $this->addSecurityHeaders($response);
            $this->logRequest($request, $response);

            return $response;

        } catch (SecurityException $e) {
            $this->handleSecurityFailure($request, $e);
            throw $e;
        } finally {
            $this->metrics->timing(
                'middleware.security.duration',
                microtime(true) - $startTime
            );
        }
    }

    private function validateRequest(Request $request): void
    {
        $rules = [
            'token' => 'required|string',
            'timestamp' => 'required|integer',
            'signature' => 'required|string|size:64'
        ];

        if (!$this->validator->validate($request->headers->all(), $rules)) {
            throw new InvalidRequestException('Invalid request headers');
        }

        if (!$this->verifyRequestSignature($request)) {
            throw new SecurityException('Invalid request signature');
        }
    }

    private function checkRateLimit(Request $request): void
    {
        $key = sprintf('rate_limit:%s:%s', 
            $request->ip(),
            $request->route()->getName()
        );

        $attempts = (int)$this->cache->get($key, 0);

        if ($attempts > config('security.rate_limit')) {
            $this->metrics->increment('rate_limit.blocked');
            throw new RateLimitException('Rate limit exceeded');
        }

        $this->cache->increment($key);
        $this->cache->expire($key, 60);
    }

    private function validateSecurityHeaders(Request $request): void
    {
        $required = ['X-CSRF-TOKEN', 'X-Frame-Options', 'X-Content-Type-Options'];

        foreach ($required as $header) {
            if (!$request->headers->has($header)) {
                throw new SecurityHeaderException("Missing required header: {$header}");
            }
        }

        if (!$this->security->verifyCsrfToken($request)) {
            throw new CsrfException('Invalid CSRF token');
        }
    }

    private function sanitizeInput(Request $request): void
    {
        $input = $request->all();

        array_walk_recursive($input, function (&$value) {
            if (is_string($value)) {
                $value = $this->sanitizeString($value);
            }
        });

        $request->replace($input);
    }

    private function sanitizeString(string $value): string
    {
        $clean = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        
        if ($clean !== $value) {
            $this->metrics->increment('security.xss_prevention');
        }

        return $clean;
    }

    private function validateResponse(Response $response): void
    {
        if (!$response->headers->has('Content-Security-Policy')) {
            throw new SecurityHeaderException('Missing CSP header');
        }

        if ($response->getStatusCode() >= 400) {
            $this->audit->logSecurityEvent(
                SecurityEventType::ERROR_RESPONSE,
                ['status' => $response->getStatusCode()]
            );
        }
    }

    private function addSecurityHeaders(Response $response): void
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=()'
        ];

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }
    }

    private function verifyRequestSignature(Request $request): bool
    {
        return $this->security->verifySignature(
            $request->headers->get('signature'),
            $this->buildSignatureData($request)
        );
    }

    private function buildSignatureData(Request $request): array
    {
        return [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'timestamp' => $request->headers->get('timestamp'),
            'token' => $request->headers->get('token')
        ];
    }

    private function handleSecurityFailure(Request $request, SecurityException $e): void
    {
        $this->metrics->increment('security.failures');

        $this->audit->logSecurityEvent(
            SecurityEventType::SECURITY_FAILURE,
            [
                'error' => $e->getMessage(),
                'request' => [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'ip' => $request->ip()
                ]
            ]
        );
    }

    private function logRequest(Request $request, Response $response): void
    {
        $this->audit->logHttpEvent(
            HttpEventType::REQUEST,
            [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'status' => $response->getStatusCode(),
                'duration' => $this->metrics->getLastTiming('middleware.security.duration')
            ]
        );
    }
}
