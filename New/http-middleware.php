<?php

namespace App\Http\Middleware;

use App\Core\Security\{SecurityManager, TokenManager};
use App\Core\Audit\AuditLogger;

class AuthenticateRequest
{
    private TokenManager $tokens;
    private AuditLogger $logger;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (!$token = $request->bearerToken()) {
                throw new AuthenticationException('No token provided');
            }

            $user = $this->tokens->validate($token);
            $request->setUser($user);

            return $next($request);

        } catch (TokenException $e) {
            $this->logger->logFailure($e, ['request' => $request]);
            throw new AuthenticationException('Invalid token');
        }
    }
}

class ValidatePermissions
{
    private SecurityManager $security;
    private AuditLogger $logger;
    
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        try {
            $context = SecurityContext::fromRequest($request)
                ->withRequiredPermission($permission);

            $this->security->validateAccess($context);
            return $next($request);

        } catch (AccessDeniedException $e) {
            $this->logger->logFailure($e, [
                'user' => $request->user()->id,
                'permission' => $permission
            ]);
            throw $e;
        }
    }
}

class ValidateContentType
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson()) {
            return $next($request);
        }
        throw new BadRequestException('JSON content type required');
    }
}

class PreventRequestsDuringMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isDownForMaintenance()) {
            throw new MaintenanceModeException();
        }
        return $next($request);
    }
}

class ThrottleRequests
{
    private RateLimiter $limiter;
    private AuditLogger $logger;

    public function handle(Request $request, Closure $next, int $maxAttempts = 60): Response
    {
        $key = $this->resolveRequestSignature($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $this->logger->logFailure(
                new RateLimitExceededException(),
                ['ip' => $request->ip()]
            );
            throw new TooManyRequestsException();
        }

        $this->limiter->hit($key);
        return $next($request);
    }

    private function resolveRequestSignature(Request $request): string
    {
        return sha1(implode('|', [
            $request->ip(),
            $request->user()?->id,
            $request->path()
        ]));
    }
}
