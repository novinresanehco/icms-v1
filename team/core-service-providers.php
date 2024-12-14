namespace App\Providers;

class SecurityServiceProvider extends ServiceProvider
{
    private array $securityServices = [
        'auth' => AuthenticationManager::class,
        'authz' => AuthorizationManager::class,
        'validator' => SecurityValidator::class,
        'encryption' => EncryptionService::class,
        'audit' => AuditLogger::class
    ];

    public function register(): void
    {
        foreach ($this->securityServices as $key => $class) {
            $this->app->singleton($class, function ($app) use ($class) {
                return new $class(
                    $app->make(ConfigRepository::class),
                    $app->make(ValidationService::class),
                    $app->make(MetricsCollector::class)
                );
            });
        }

        $this->registerSecurityMiddleware();
        $this->registerSecurityPolicies();
    }

    private function registerSecurityMiddleware(): void
    {
        $this->app['router']->aliasMiddleware('secure', SecureRequestMiddleware::class);
        $this->app['router']->aliasMiddleware('auth.mfa', MFAMiddleware::class);
        $this->app['router']->aliasMiddleware('rate.limit', RateLimitMiddleware::class);
    }

    private function registerSecurityPolicies(): void
    {
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });
    }
}

namespace App\Http\Middleware;

class SecureRequestMiddleware
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Validate request integrity
            $this->validator->validateRequest($request);
            
            // Create security context
            $context = $this->security->createContext($request);
            
            // Add context to request
            $request->setSecurityContext($context);
            
            // Process request
            $response = $next($request);
            
            // Validate response
            $this->validator->validateResponse($response);
            
            // Add security headers
            return $this->addSecurityHeaders($response);
            
        } catch (SecurityException $e) {
            $this->audit->logSecurityViolation($request, $e);
            throw $e;
        }
    }

    private function addSecurityHeaders(Response $response): Response
    {
        return $response
            ->header('X-Frame-Options', 'DENY')
            ->header('X-XSS-Protection', '1; mode=block')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', $this->getCSPPolicy())
            ->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    private function getCSPPolicy(): string
    {
        return "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
               "style-src 'self' 'unsafe-inline'; " .
               "img-src 'self' data: https:; " .
               "font-src 'self' data:; " .
               "connect-src 'self'";
    }
}

class RateLimitMiddleware
{
    private RateLimiter $limiter;
    private AuditLogger $audit;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->generateRateLimitKey($request);
        $limit = $this->getLimit($request);

        if (!$this->limiter->attempt($key, $limit)) {
            $this->audit->logRateLimitExceeded($request);
            throw new RateLimitException('Rate limit exceeded');
        }

        return $next($request);
    }

    private function generateRateLimitKey(Request $request): string
    {
        return sha1(
            $request->ip() . '|' . 
            $request->route()->getName() . '|' .
            $request->user()?->id
        );
    }

    private function getLimit(Request $request): RateLimit
    {
        return new RateLimit(
            max: config('security.rate_limit.max'),
            period: config('security.rate_limit.period')
        );
    }
}

class CorsMiddleware
{
    private ConfigRepository $config;
    private ValidationService $validator;

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isAllowedOrigin($request->header('Origin'))) {
            throw new SecurityException('Invalid origin');
        }

        $response = $next($request);

        return $this->addCorsHeaders($response, $request);
    }

    private function isAllowedOrigin(?string $origin): bool
    {
        if (!$origin) {
            return false;
        }

        return in_array($origin, $this->config->get('cors.allowed_origins'));
    }

    private function addCorsHeaders(Response $response, Request $request): Response
    {
        return $response
            ->header('Access-Control-Allow-Origin', $request->header('Origin'))
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->header('Access-Control-Allow-Credentials', 'true')
            ->header('Access-Control-Max-Age', '86400');
    }
}
