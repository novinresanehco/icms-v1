<?php

namespace App\Core\Security\Session;

use App\Core\Security\Models\{SessionData, SecurityContext};
use Illuminate\Support\Facades\{Cache, DB, Crypt};

class SessionManagementSystem
{
    private TokenGenerator $tokenGenerator;
    private EncryptionService $encryption;
    private AuditLogger $logger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        TokenGenerator $tokenGenerator,
        EncryptionService $encryption,
        AuditLogger $logger,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->tokenGenerator = $tokenGenerator;
        $this->encryption = $encryption;
        $this->logger = $logger;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function createSession(SecurityContext $context): SessionData
    {
        DB::beginTransaction();
        
        try {
            $sessionId = $this->tokenGenerator->generateSecureToken();
            $token = $this->tokenGenerator->generateSecureToken();
            
            $session = new SessionData([
                'id' => $sessionId,
                'token' => $this->encryption->encrypt($token),
                'user_id' => $context->getUserId(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'created_at' => time(),
                'expires_at' => time() + $this->config->getSessionLifetime(),
                'security_level' => $context->getSecurityLevel(),
                'metadata' => $this->getSessionMetadata($context)
            ]);

            $this->persistSession($session);
            $this->cacheSessionData($session);
            $this->trackSessionMetrics($session);
            
            DB::commit();
            
            return $session;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSessionCreationFailure($e, $context);
            throw $e;
        }
    }

    public function validateSession(string $sessionId, SecurityContext $context): bool
    {
        try {
            $session = $this->getSession($sessionId);
            
            if (!$session) {
                return false;
            }

            if (!$this->validateSessionIntegrity($session, $context)) {
                $this->terminateSession($sessionId, 'integrity_violation');
                return false;
            }

            if ($this->isSessionExpired($session)) {
                $this->terminateSession($sessionId, 'expired');
                return false;
            }

            if ($this->detectSessionAnomaly($session, $context)) {
                $this->terminateSession($sessionId, 'anomaly_detected');
                return false;
            }

            $this->updateSessionActivity($session);
            return true;
            
        } catch (\Exception $e) {
            $this->handleSessionValidationFailure($e, $sessionId, $context);
            return false;
        }
    }

    public function terminateSession(string $sessionId, string $reason): void
    {
        try {
            $session = $this->getSession($sessionId);
            
            if ($session) {
                $this->logSessionTermination($session, $reason);
                $this->removeSessionData($sessionId);
                $this->invalidateSessionTokens($session);
            }
            
        } catch (\Exception $e) {
            $this->handleSessionTerminationFailure($e, $sessionId);
        }
    }

    private function persistSession(SessionData $session): void
    {
        DB::table('sessions')->insert([
            'id' => $session->getId(),
            'user_id' => $session->getUserId(),
            'token_hash' => hash('sha256', $session->getToken()),
            'ip_address' => $session->getIpAddress(),
            'user_agent' => $session->getUserAgent(),
            'created_at' => $session->getCreatedAt(),
            'expires_at' => $session->getExpiresAt(),
            'metadata' => json_encode($session->getMetadata())
        ]);
    }

    private function validateSessionIntegrity(
        SessionData $session, 
        SecurityContext $context
    ): bool {
        if ($session->getUserId() !== $context->getUserId()) {
            return false;
        }

        if ($session->getIpAddress() !== $context->getIpAddress() && 
            !$this->isIpChangeAllowed($context)) {
            return false;
        }

        if ($session->getUserAgent() !== $context->getUserAgent()) {
            return false;
        }

        return true;
    }

    private function detectSessionAnomaly(
        SessionData $session,
        SecurityContext $context
    ): bool {
        $anomalyScore = 0;
        
        if ($this->isUnusualLocation($session, $context)) {
            $anomalyScore += 2;
        }
        
        if ($this->isUnusualTime($session)) {
            $anomalyScore += 1;
        }
        
        if ($this->detectConcurrentSession($session)) {
            $anomalyScore += 3;
        }
        
        return $anomalyScore >= $this->config->getAnomalyThreshold();
    }

    private function updateSessionActivity(SessionData $session): void
    {
        $session->setLastActivity(time());
        
        DB::table('sessions')
            ->where('id', $session->getId())
            ->update([
                'last_activity' => $session->getLastActivity(),
                'access_count' => DB::raw('access_count + 1')
            ]);

        $this->cacheSessionData($session);
    }

    private function removeSessionData(string $sessionId): void
    {
        DB::beginTransaction();
        
        try {
            DB::table('sessions')->where('id', $sessionId)->delete();
            DB::table('session_tokens')->where('session_id', $sessionId)->delete();
            Cache::forget("session:{$sessionId}");
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function cacheSessionData(SessionData $session): void
    {
        $cacheKey = "session:{$session->getId()}";
        $cacheDuration = $this->config->getSessionCacheDuration();
        
        Cache::put($cacheKey, $session, $cacheDuration);
    }

    private function handleSessionCreationFailure(
        \Exception $e,
        SecurityContext $context
    ): void {
        $this->logger->logSecurityEvent('session_creation_failed', [
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementCounter('session_creation_failures');
    }
}
