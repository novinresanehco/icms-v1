<?php

namespace App\Core\Middleware;

use Illuminate\Http\Request;
use App\Core\Security\SecurityManager;
use App\Core\Security\SecurityMonitor;
use App\Core\Exceptions\SecurityException;
use Illuminate\Support\Facades\{Cache, Log};

class SecurityMiddleware
{
    protected SecurityManager $security;
    protected SecurityMonitor $monitor;
    protected array $exemptRoutes = ['login', 'logout', 'password.reset'];

    public function __construct(
        SecurityManager $security,
        SecurityMonitor $monitor
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
    }

    public function handle(Request $request, \Closure $next)
    {
        if ($this->isExempt($request)) {
            return $next($request);
        }

        try {
            $this->validateRequest($request);
            $this->enforceRateLimit($request);
            $this->checkSecurityStatus();
            
            $response = $next($request);
            
            $this->validateResponse($response);
            $this->logAccess($request, $response);
            
            return $response;

        } catch (\Exception $e) {
            $this->handleSecurityException($e, $request);
            throw $e;
        }
    }

    protected function validateRequest(Request $request): void
    {
        $this->security->validateToken($request->bearerToken());
        $this->security->validateSession();
        $this->security->checkPermissions($request->route()->getName());

        if ($request->hasFile()) {
            $this->security->validateUploads($request->allFiles());
        }
    }

    protected function enforceRateLimit(Request $request): void
    {
        $key = 'rate_limit:' . $request->ip();
        $attempts = Cache::increment($key);
        
        if ($attempts > config('security.rate_limit')) {
            $this->monitor->logRateLimitExceeded($request);
            throw new SecurityException('Rate limit exceeded');
        }

        Cache::put($key, $attempts, now()->addMinutes(1));
    }

    protected function checkSecurityStatus(): void
    {
        if (Cache::tags(['system'])->get('security_lockdown')) {
            throw new SecurityException('System is in security lockdown');
        }

        if (!$this->security->isSystemSecure()) {
            $this->initiateSecurityProtocol();
        }
    }

    protected function validateResponse($response): void
    {
        if (!$this->security->validateResponseIntegrity($response)) {
            throw new SecurityException('Response integrity validation failed');
        }
    }

    protected function logAccess(Request $request, $response): void
    {
        $this->monitor->logAccess([
            'route' => $request->route()->getName(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user' => auth()->id(),
            'status' => $response->status(),
            'duration' => defined('LARAVEL_START') 
                ? microtime(true) - LARAVEL_START 
                : 0
        ]);
    }

    protected function handleSecurityException(\Exception $e, Request $request): void
    {
        $this->monitor->logSecurityIncident([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'route' => $request->route()->getName(),
            'ip' => $request->ip(),
            'user' => auth()->id()
        ]);

        if ($this->isHighSeverity($e)) {
            $this->initiateSecurityProtocol();
        }
    }

    protected function initiateSecurityProtocol(): void
    {
        Cache::tags(['system'])->put('security_lockdown', true, 300);
        $this->monitor->triggerSecurityAlert();
        Log::emergency('Security protocol initiated');
    }

    protected function isExempt(Request $request): bool
    {
        return in_array($request->route()->getName(), $this->exemptRoutes) ||
            $request->is('public/*');
    }

    protected function isHighSeverity(\Exception $e): bool
    {
        return $e instanceof SecurityException &&
            $e->getSeverity() === 'high';
    }
}
