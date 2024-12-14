<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Security\{SecurityConfig, SecurityException};
use App\Core\Interfaces\SessionManagementInterface;

class SessionManager implements SessionManagementInterface
{
    private SecurityConfig $config;
    private AuditLogger $auditLogger;
    private TokenManager $tokenManager;
    private SessionRepository $repository;

    public function __construct(
        SecurityConfig $config,
        AuditLogger $auditLogger,
        TokenManager $tokenManager,
        SessionRepository $repository
    ) {
        $this->config = $config;
        $this->auditLogger = $auditLogger;
        $this->tokenManager = $tokenManager;
        $this->repository = $repository;
    }

    public function createSession(array $sessionData): Session
    {
        DB::beginTransaction();
        try {
            // Generate secure session ID
            $sessionId = $this->generateSecureSessionId();
            
            // Create session record
            $session = $this->repository->create([
                'id' => $sessionId,
                'user_id' => $sessionData['user_id'],
                'ip_address' => $sessionData['ip'],
                'user_agent' => $sessionData['user_agent'],
                'last_activity' => now(),
                'expires_at' => now()->addMinutes($this->config->getSessionLifetime())
            ]);

            // Store session in cache for fast access
            $this->storeSessionInCache($session);

            // Log session creation
            $this->auditLogger->logSessionCreation($session);

            DB::commit();
            return $session;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSessionError($e, 'create', $sessionData);
            throw new SessionCreationException(
                'Failed to create session',
                previous: $e
            );
        }
    }

    public function validateSession(string $sessionId): Session
    {
        try {
            $session = $this->getSession($sessionId);

            // Check expiration
            if ($this->isSessionExpired($session)) {
                $this->invalidateSession($sessionId);
                throw new SessionExpiredException('Session has expired');
            }

            // Verify IP and user agent if strict checking enabled
            if ($this->config->isStrictSessionChecking()) {
                $this->verifySessionContext($session);
            }

            // Update last activity
            $this->touchSession($sessionId);

            return $session;

        } catch (\Exception $e) {
            $this->handleSessionError($e, 'validate', ['session_id' => $sessionId]);
            throw new SessionValidationException(
                'Session validation failed',
                previous: $e
            );
        }
    }

    public function invalidateSession(string $sessionId): void
    {
        try {
            // Remove from cache
            Cache::forget("session:{$sessionId}");
            
            // Mark as invalid in database
            $this->repository->invalidate($sessionId);
            
            // Revoke associated tokens
            $this->tokenManager->revokeSessionTokens($sessionId);
            
            // Log session invalidation
            $this->auditLogger->logSessionInvalidation($sessionId);

        } catch (\Exception $e) {
            $this->handleSessionError($e, 'invalidate', ['session_id' => $sessionId]);
            throw new SessionInvalidationException(
                'Failed to invalidate session',
                previous: $e
            );
        }
    }

    public function invalidateAllUserSessions(int $userId): void
    {
        try {
            // Get all active sessions for user
            $sessions = $this->repository->getUserActiveSessions($userId);
            
            foreach ($sessions as $session) {
                $this->invalidateSession($session->id);
            }

            $this->auditLogger->logAllSessionsInvalidation($userId);

        } catch (\Exception $e) {
            $this->handleSessionError($e, 'invalidateAll', ['user_id' => $userId]);
            throw new SessionInvalidationException(
                'Failed to invalidate all user sessions',
                previous: $e
            );
        }
    }

    public function getSession(string $sessionId): Session
    {
        // Try to get from cache first
        $session = Cache::get("session:{$sessionId}");
        
        if (!$session) {
            // Fetch from database if not in cache
            $session = $this->repository->find($sessionId);
            
            if (!$session) {
                throw new SessionNotFoundException('Session not found');
            }

            // Store in cache for future requests
            $this->storeSessionInCache($session);
        }

        return $session;
    }

    public function touchSession(string $sessionId): void
    {
        try {
            $session = $this->getSession($sessionId);
            
            // Update last activity timestamp
            $session->last_activity = now();
            
            // Update in both cache and database
            $this->storeSessionInCache($session);
            $this->repository->update($session);

        } catch (\Exception $e) {
            $this->handleSessionError($e, 'touch', ['session_id' => $sessionId]);
            throw new SessionUpdateException(
                'Failed to update session activity',
                previous: $e
            );
        }
    }

    private function generateSecureSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function storeSessionInCache(Session $session): void
    {
        Cache::put(
            "session:{$session->id}",
            $session,
            now()->addMinutes($this->config->getSessionLifetime())
        );
    }

    private function isSessionExpired(Session $session): bool
    {
        return $session->expires_at->isPast() ||
               $session->last_activity->addMinutes($this->config->getInactivityTimeout())->isPast();
    }

    private function verifySessionContext(Session $session): void
    {
        $request = request();
        
        if ($session->ip_address !== $request->ip() ||
            $session->user_agent !== $request->userAgent()) {
            throw new SessionContextException('Session context mismatch');
        }
    }

    private function handleSessionError(\Exception $e, string $operation, array $context): void
    {
        $this->auditLogger->logSessionError($e, $operation, $context);
    }
}
