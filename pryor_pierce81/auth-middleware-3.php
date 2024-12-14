<?php

namespace App\Core\Auth\Middleware;

use App\Core\Auth\Exceptions\AuthenticationException;
use App\Core\Auth\Services\TokenService;
use Closure;
use Illuminate\Http\Request;

class AuthenticationMiddleware
{
    protected TokenService $tokenService;
    
    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }
    
    /**
     * Handle the incoming request
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $this->extractToken($request);
        
        if (!$token || !$this->tokenService->validateToken($token)) {
            throw new AuthenticationException('Invalid or expired token');
        }
        
        $user = $this->resolveUser($token);
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        // Attach user to request
        $request->setUserResolver(fn() => $user);
        
        // Add security headers
        return $next($request)->withHeaders([
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'X-Content-Type-Options' => 'nosniff',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'
        ]);
    }
    
    /**
     * Extract token from request
     */
    protected function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        
        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return null;
        }
        
        return substr($header, 7);
    }
    
    /**
     * Resolve user from token
     */
    protected function resolveUser(string $token): ?User
    {
        $payload = $this->tokenService->decode($token);
        return User::find($payload->sub);
    }
}

namespace App\Core\Auth\Security;

class SecurityMiddleware
{
    protected array $config;
    protected RateLimiter $rateLimiter;
    
    public function __construct(RateLimiter $rateLimiter)
    {
        $this->config = config('security');
        $this->rateLimiter = $rateLimiter;
    }
    
    public function handle(Request $request, Closure $next)
    {
        // Check rate limiting
        if (!$this->rateLimiter->attempt($request)) {
            throw new TooManyRequestsException();
        }
        
        // Validate CSRF token for non-read requests
        if (!$this->isReadOnlyRequest($request) && !$this->validateCsrfToken($request)) {
            throw new InvalidCsrfTokenException();
        }
        
        // Scan for malicious content
        if ($this->containsMaliciousContent($request)) {
            throw new SecurityException('Potentially malicious content detected');
        }
        
        return $next($request);
    }
    
    protected function isReadOnlyRequest(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']);
    }
    
    protected function validateCsrfToken(Request $request): bool
    {
        return $request->hasValidSignature() || 
               $request->hasValidCsrfToken();
    }
    
    protected function containsMaliciousContent(Request $request): bool
    {
        $content = $request->getContent();
        
        // Check for common malicious patterns
        $maliciousPatterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/javascript:/i',
            '/data:/i',
            '/vbscript:/i',
            '/onclick/i',
            '/onload/i',
            '/onerror/i'
        ];
        
        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
}

namespace App\Core\Auth\Security;

class RateLimiter
{
    protected CacheManager $cache;
    protected array $config;
    
    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
        $this->config = config('security.rate_limiting');
    }
    
    public function attempt(Request $request): bool
    {
        $key = $this->getKey($request);
        $attempts = (int) $this->cache->get($key, 0);
        
        if ($attempts >= $this->getMaxAttempts($request)) {
            return false;
        }
        
        $this->cache->increment($key);
        $this->cache->put($key, $attempts + 1, $this->getDecayMinutes());
        
        return true;
    }
    
    protected function getKey(Request $request): string
    {
        return sprintf(
            'rate_limit:%s:%s',
            $request->ip(),
            md5($request->path())
        );
    }
    
    protected function getMaxAttempts(Request $request): int
    {
        if ($this->isApiRequest($request)) {
            return $this->config['api_max_attempts'] ?? 60;
        }
        
        return $this->config['web_max_attempts'] ?? 30;
    }
    
    protected function getDecayMinutes(): int
    {
        return $this->config['decay_minutes'] ?? 1;
    }
    
    protected function isApiRequest(Request $request): bool
    {
        return str_starts_with($request->path(), 'api/');
    }
}

namespace App\Core\Auth\Security;

class TwoFactorAuthenticator
{
    protected TwoFactorProviderInterface $provider;
    protected UserRepository $users;
    
    public function __construct(TwoFactorProviderInterface $provider, UserRepository $users)
    {
        $this->provider = $provider;
        $this->users = $users;
    }
    
    public function enable(User $user): EnablementResult
    {
        // Generate secret
        $secret = $this->provider->generateSecret();
        
        // Store secret
        $this->users->update($user->id, [
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true
        ]);
        
        // Generate QR code
        $qrCode = $this->provider->getQRCode($user->email, $secret);
        
        return new EnablementResult($secret, $qrCode);
    }
    
    public function verify(User $user, string $code): bool
    {
        if (!$user->two_factor_enabled) {
            return false;
        }
        
        return $this->provider->verify(
            $user->two_factor_secret,
            $code
        );
    }
    
    public function disable(User $user): void
    {
        $this->users->update($user->id, [
            'two_factor_secret' => null,
            'two_factor_enabled' => false
        ]);
    }
}
