```php
namespace App\Core\Middleware;

class SecurityMiddlewareChain
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $logger;
    private RateLimiter $limiter;
    private RequestAnalyzer $analyzer;

    public function process(Request $request, Closure $next): Response
    {
        try {
            // Initialize security context
            $context = $this->createSecurityContext($request);
            
            // Execute pre-request validation chain
            $this->executePreRequestChain($context);
            
            // Process request with monitoring
            $response = $this->processWithMonitoring($request, $next);
            
            // Execute post-request validation chain
            $this->executePostRequestChain($context, $response);
            
            return $response;
            
        } catch (SecurityException $e) {
            return $this->handleSecurityException($e, $context);
        }
    }

    private function executePreRequestChain(SecurityContext $context): void
    {
        // Rate limiting check
        if (!$this->limiter->checkLimit($context)) {
            throw new RateLimitException('Rate limit exceeded');
        }

        // Request validation
        if (!$this->validator->validateRequest($context->getRequest())) {
            throw new ValidationException('Invalid request format');
        }

        // Security token verification
        if (!$this->security->verifyToken($context->getToken())) {
            throw new InvalidTokenException('Invalid security token');
        }

        // Permission check
        if (!$this->security->checkPermissions($context)) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Request analysis for threats
        $threats = $this->analyzer->detectThreats($context);
        if ($threats->hasHighRisk()) {
            throw new SecurityThreatException('High risk request detected');
        }
    }

    private function processWithMonitoring(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        try {
            // Process request
            $response = $next($request);
            
            // Record metrics
            $this->recordRequestMetrics($request, $response, $startTime);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->logException($e);
            throw $e;
        }
    }

    private function executePostRequestChain(SecurityContext $context, Response $response): void
    {
        // Response validation
        if (!$this->validator->validateResponse($response)) {
            throw new ValidationException('Invalid response format');
        }

        // Security headers
        $response->headers->add([
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => $this->security->getCSPPolicy(),
        ]);

        // Response logging
        $this->logger->logResponse($context, $response);
    }

    private function createSecurityContext(Request $request): SecurityContext
    {
        return new SecurityContext([
            'request' => $request,
            'token' => $request->bearerToken(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => microtime(true),
            'session_id' => session()->getId()
        ]);
    }

    private function handleSecurityException(
        SecurityException $e, 
        SecurityContext $context
    ): Response {
        // Log security exception
        $this->logger->logSecurityException($e, $context);
        
        // Notify security team if necessary
        if ($e->isCritical()) {
            $this->notifySecurityTeam($e, $context);
        }
        
        // Return appropriate error response
        return response()->json([
            'error' => 'Security violation',
            'code' => $e->getCode()
        ], 403);
    }
}

class RequestAnalyzer
{
    private ThreatDetector $detector;
    private PatternMatcher $patterns;
    private RiskAssessor $risk;

    public function detectThreats(SecurityContext $context): ThreatReport
    {
        $threats = new ThreatCollection();
        
        // Analyze request patterns
        $this->analyzeRequestPatterns($context, $threats);
        
        // Check for known attack signatures
        $this->checkAttackSignatures($context, $threats);
        
        // Assess payload risks
        $this->assessPayloadRisks($context, $threats);
        
        return new ThreatReport($threats, $this->risk->calculateRiskLevel($threats));
    }

    private function analyzeRequestPatterns(SecurityContext $context, ThreatCollection $threats): void
    {
        foreach ($this->patterns->getPatterns() as $pattern) {
            if ($pattern->matches($context->getRequest())) {
                $threats->add(new Threat($pattern, ThreatLevel::fromPattern($pattern)));
            }
        }
    }

    private function checkAttackSignatures(SecurityContext $context, ThreatCollection $threats): void
    {
        $signatures = $this->detector->detectSignatures($context);
        foreach ($signatures as $signature) {
            if ($signature->isActive()) {
                $threats->add(new Threat($signature, ThreatLevel::Critical));
            }
        }
    }
}
```
