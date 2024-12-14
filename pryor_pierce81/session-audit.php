<?php

namespace App\Core\Auth\Session;

class SessionManager
{
    protected StorageInterface $storage;
    protected SecurityService $security;
    protected array $config;
    
    public function __construct(StorageInterface $storage, SecurityService $security)
    {
        $this->storage = $storage;
        $this->security = $security;
        $this->config = config('session');
    }
    
    /**
     * Create a new session
     */
    public function createSession(User $user, Request $request): Session
    {
        $sessionId = $this->generateSessionId();
        
        $session = new Session([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $this->getInitialPayload($user),
            'last_activity' => now(),
            'expires_at' => $this->getExpirationTime()
        ]);
        
        $this->storage->store($sessionId, $session);
        $this->auditLog->logSessionCreated($session);
        
        return $session;
    }
    
    /**
     * Validate and refresh session
     */
    public function validateSession(string $sessionId): bool
    {
        $session = $this->storage->get($sessionId);
        
        if (!$session || $session->isExpired()) {
            return false;
        }
        
        if ($this->security->isSessionCompromised($session)) {
            $this->terminateSession($sessionId);
            return false;
        }
        
        $this->refreshSession($session);
        return true;
    }
    
    /**
     * Refresh session data and extend lifetime
     */
    protected function refreshSession(Session $session): void
    {
        $session->last_activity = now();
        $session->expires_at = $this->getExpirationTime();
        
        $this->storage->store($session->id, $session);
    }
    
    /**
     * Terminate session
     */
    public function terminateSession(string $sessionId): void
    {
        $session = $this->storage->get($sessionId);
        
        if ($session) {
            $this->storage->delete($sessionId);
            $this->auditLog->logSessionTerminated($session);
        }
    }
    
    /**
     * Clean expired sessions
     */
    public function cleanExpiredSessions(): void
    {
        $expired = $this->storage->getExpired();
        
        foreach ($expired as $session) {
            $this->terminateSession($session->id);
        }
    }
}

namespace App\Core\Auth\Audit;

class AuditLogger
{
    protected LoggerInterface $logger;
    protected QueueInterface $queue;
    protected array $config;
    
    public function __construct(LoggerInterface $logger, QueueInterface $queue)
    {
        $this->logger = $logger;
        $this->queue = $queue;
        $this->config = config('audit');
    }
    
    /**
     * Log authentication event
     */
    public function logAuthEvent(string $event, User $user, array $context = []): void
    {
        $data = [
            'event' => $event,
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now(),
            'context' => $context
        ];
        
        $this->processAuditLog($data);
    }
    
    /**
     * Log security event
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        $logData = [
            'event' => $event,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now(),
            'severity' => $this->determineSeverity($event),
            'data' => $data
        ];
        
        $this->processAuditLog($logData);
        
        if ($this->isHighSeverityEvent($event)) {
            $this->notifySecurityTeam($logData);
        }
    }
    
    /**
     * Process audit log entry
     */
    protected function processAuditLog(array $data): void
    {
        // Store in database
        $this->logger->log($data);
        
        // Queue for external processing if configured
        if ($this->config['external_logging_enabled']) {
            $this->queue->push(new ProcessAuditLog($data));
        }
    }
    
    /**
     * Determine event severity
     */
    protected function determineSeverity(string $event): string
    {
        $severityMap = [
            'auth.failed' => 'medium',
            'auth.blocked' => 'high',
            'security.breach' => 'critical',
            'session.compromised' => 'high',
            'user.role_changed' => 'medium',
            'user.password_reset' => 'medium'
        ];
        
        return $severityMap[$event] ?? 'low';
    }
    
    /**
     * Check if event is high severity
     */
    protected function isHighSeverityEvent(string $event): bool
    {
        return in_array($this->determineSeverity($event), ['high', 'critical']);
    }
}

namespace App\Core\Auth\Monitoring;

class SecurityMonitor
{
    protected AlertManager $alertManager;
    protected MetricsCollector $metrics;
    protected array $thresholds;
    
    public function __construct(AlertManager $alertManager, MetricsCollector $metrics)
    {
        $this->alertManager = $alertManager;
        $this->metrics = $metrics;
        $this->thresholds = config('security.thresholds');
    }
    
    /**
     * Monitor security events
     */
    public function monitorEvents(): void
    {
        $this->checkFailedLogins();
        $this->checkSuspiciousActivity();
        $this->checkBruteForceAttempts();
        $this->checkAnomalies();
    }
    
    /**
     * Check for failed login attempts
     */
    protected function checkFailedLogins(): void
    {
        $metrics = $this->metrics->getFailedLogins();
        
        if ($metrics->count > $this->thresholds['failed_logins']) {
            $this->alertManager->sendAlert(
                'High number of failed login attempts detected',
                $metrics
            );
        }
    }
    
    /**
     * Check for suspicious activity
     */
    protected function checkSuspiciousActivity(): void
    {
        $activity = $this->metrics->getSuspiciousActivity();
        
        foreach ($activity as $event) {
            if ($this->isAnomalous($event)) {
                $this->alertManager->sendAlert(
                    'Suspicious activity detected',
                    $event
                );
            }
        }
    }
    
    /**
     * Check for brute force attempts
     */
    protected function checkBruteForceAttempts(): void
    {
        $attempts = $this->metrics->getBruteForceAttempts();
        
        if ($attempts->count > $this->thresholds['brute_force']) {
            $this->alertManager->sendHighPriorityAlert(
                'Possible brute force attack detected',
                $attempts
            );
            
            $this->blockSuspectedAttackers($attempts->ips);
        }
    }
}
