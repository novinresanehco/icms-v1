<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Security\SecurityManager;
use App\Core\Security\SecurityContext;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationManager;
use App\Core\Audit\AuditManager;
use App\Core\Exceptions\SecurityException;
use Symfony\Component\HttpFoundation\Response;

class SecurityMiddleware
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationManager $validator;
    private AuditManager $audit;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationManager $validator,
        AuditManager $audit
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Create security context
            $context = $this->createSecurityContext($request);
            
            // Validate request integrity
            $this->validateRequest($request, $context);
            
            // Check rate limits
            $this->checkRateLimits($request, $context);
            
            // Perform security checks
            $this->performSecurityChecks($request, $context);
            
            // Add security headers
            $response = $next($request);
            return $this->addSecurityHeaders($response);
            
        } catch (SecurityException $e) {
            return $this->handleSecurityException($e, $request);
        } catch (\Exception $e) {
            return $this->handleException($e, $request);
        }
    }

    protected function createSecurityContext(Request $request): SecurityContext
    {
        $cacheKey = $this->getContextCacheKey($request);
        
        return $this->cache->remember($cacheKey, 300, function() use ($request) {
            return new SecurityContext(
                $request->user()?->id,
                $request->user()?->roles->pluck('id')->toArray() ?? [],
                $request->ip(),
                $request->userAgent(),
                $request->session()->getId()
            );
        });
    }

    protected function validateRequest(Request $request, SecurityContext $context): void
    {
        // Validate headers
        $this->validator->validate($request->headers->all(), [
            'user-agent' => 'required|string|max:255',
            'accept' => 'required|string',
            'content-type' => 'required_if:method,POST,PUT,PATCH'
        ]);

        // Validate input data
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            $this->validator->validate($request->all(), $this->getValidationRules($request));
        }

        // Log validation success
        $this->audit->logSecurityEvent(
            'request.validated',
            $context,
            ['uri' => $request->getUri()]
        );
    }

    protected function checkRateLimits(Request $request, SecurityContext $context): void
    {
        $key = $this->getRateLimitKey($request, $context);
        
        if (!$this->security->checkRateLimit($context, $key)) {
            $this->audit->logSecurityEvent(
                'rate.limit.exceeded',
                $context,
                ['uri' => $request->getUri()],
                'high'
            );
            
            throw new SecurityException('Rate limit exceeded');
        }
    }

    protected function performSecurityChecks(Request $request, SecurityContext $context): void
    {
        // Check required permissions
        $permissions = $this->getRequiredPermissions($request);
        
        if (!empty($permissions) && !$this->security->validateMultiple($context, $permissions)) {
            throw new SecurityException('Insufficient permissions');
        }

        // Verify CSRF token for state-changing operations
        if ($this->requiresCsrfVerification($request)) {
            $this->validateCsrfToken($request);
        }

        // Additional security validations
        $this->validateSecurityRequirements($request, $context);
    }

    protected function addSecurityHeaders(Response $response): Response
    {
        return $response
            ->headers->set('X-Content-Type-Options', 'nosniff')
            ->headers->set('X-Frame-Options', 'DENY')
            ->headers->set('X-XSS-Protection', '1; mode=block')
            ->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->headers->set('Content-Security-Policy', $this->getContentSecurityPolicy())
            ->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->headers->set('Feature-Policy', $this->getFeaturePolicy());
    }

    protected function getValidationRules(Request $request): array
    {
        // Get route specific validation rules
        if ($route = $request->route()) {
            $controller = $route->getController();
            $action = $route->getActionMethod();
            
            if (method_exists($controller, 'validationRules')) {
                return $controller->validationRules($action);
            }
        }

        return [];
    }

    protected function getRequiredPermissions(Request $request): array
    {
        if ($route = $request->route()) {
            return $route->getAction('permissions') ?? [];
        }
        
        return [];
    }

    protected function requiresCsrfVerification(Request $request): bool
    {
        return !$this->inExceptArray($request) && 
               !$request->isReadOnly() && 
               $request->isFromWebMiddleware();
    }

    protected function getContextCacheKey(Request $request): string
    {
        return sprintf(
            'security.context.%s.%s',
            $request->user()?->id ?? 'guest',
            md5($request->ip() . $request->userAgent())
        );
    }

    protected function getRateLimitKey(Request $request, SecurityContext $context): string
    {
        return sprintf(
            'rate.limit.%s.%s',
            $context->getUserId() ?? 'guest',
            md5($request->getUri())
        );
    }

    protected function getContentSecurityPolicy(): string
    {
        return "default-src 'self'; " .
               "script-src 'self' 'strict-dynamic'; " .
               "style-src 'self' 'unsafe-inline'; " .
               "img-src 'self' data:; " .
               "font-src 'self'; " .
               "object-src 'none'; " .
               "base-uri 'self'; " .
               "form-action 'self'; " .
               "frame-ancestors 'none';";
    }

    protected function getFeaturePolicy(): string
    {
        return "camera 'none'; " .
               "microphone 'none'; " .
               "geolocation 'none'; " .
               "payment 'none';";
    }
}
