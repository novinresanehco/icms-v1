<?php

namespace App\Http\Controllers\Admin;

use App\Core\Security\{
    SecurityManager,
    AuthManager,
    AccessControl,
    TokenManager
};
use App\Core\Logging\AuditLogger;
use App\Core\Cache\CacheManager;
use App\Core\Notification\NotificationSystem;

class AdminAuthController extends Controller
{
    private SecurityManager $security;
    private AuthManager $auth;
    private AccessControl $access;
    private TokenManager $tokens;
    private AuditLogger $audit;
    private CacheManager $cache;
    private NotificationSystem $notifications;

    public function __construct(
        SecurityManager $security,
        AuthManager $auth, 
        AccessControl $access,
        TokenManager $tokens,
        AuditLogger $audit,
        CacheManager $cache,
        NotificationSystem $notifications
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->access = $access;
        $this->tokens = $tokens;
        $this->audit = $audit;
        $this->cache = $cache;
        $this->notifications = $notifications;
    }

    public function login(Request $request): JsonResponse
    {
        try {
            // Validate credentials with rate limiting
            $this->validateWithRateLimit($request);

            // Multi-factor authentication
            $mfaResult = $this->auth->verifyMFA($request);
            if (!$mfaResult->isValid()) {
                throw new MFARequiredException();
            }

            // Generate secure token
            $token = $this->tokens->generate(
                $request->user(),
                $this->getTokenOptions()
            );

            // Log successful login
            $this->audit->logSuccess('login', [
                'user' => $request->user()->id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'token' => $token,
                'expires' => $this->tokens->getExpiration($token)
            ]);

        } catch (\Exception $e) {
            // Log failed attempt
            $this->audit->logFailure('login', [
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);

            // Check for brute force
            if ($this->detectBruteForce($request)) {
                $this->lockAccount($request);
            }

            throw $e;
        }
    }

    public function validateSession(Request $request): void
    {
        // Verify token
        $token = $this->tokens->validate($request->bearerToken());
        if (!$token->isValid()) {
            throw new InvalidTokenException();
        }

        // Check permissions
        if (!$this->access->hasPermission($token->user(), 'admin.access')) {
            throw new UnauthorizedException();
        }

        // Extend session if needed
        if ($token->shouldExtend()) {
            $this->tokens->extend($token);
        }

        // Log activity
        $this->audit->logAccess('session_validation', [
            'user' => $token->user()->id
        ]);
    }

    protected function validateWithRateLimit(Request $request): void
    {
        $key = 'auth_attempts:' . $request->ip();
        
        $attempts = $this->cache->get($key, 0);
        if ($attempts >= 5) {
            throw new TooManyAttemptsException();
        }

        $this->cache->increment($key);
        $this->cache->expire($key, 300); // 5 minutes
    }

    protected function detectBruteForce(Request $request): bool
    {
        $attempts = $this->cache->get('failed_attempts:' . $request->ip(), 0);
        return $attempts >= 10;
    }

    protected function lockAccount(Request $request): void
    {
        $this->auth->lockAccount($request->input('email'));
        $this->notifications->sendLockoutAlert($request->input('email'));
        $this->audit->logSecurity('account_locked', [
            'email' => $request->input('email'),
            'ip' => $request->ip()
        ]);
    }

    protected function getTokenOptions(): array
    {
        return [
            'expires' => config('auth.token_lifetime'),
            'refresh' => true,
            'scope' => ['admin'],
            'encryption' => 'AES-256-GCM'
        ];
    }
}
