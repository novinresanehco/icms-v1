<?php

namespace App\Core\Security;

class CriticalSessionManager
{
    private $store;
    private $encryptor;
    private $monitor;

    const MAX_LIFETIME = 3600; // 1 hour
    const IDLE_TIMEOUT = 900;  // 15 minutes

    public function startSession(string $userId): string
    {
        $sessionId = $this->monitor->startOperation('session_create');

        try {
            // Generate secure session ID
            $sid = $this->generateSecureId();
            
            // Create session data
            $data = [
                'user_id' => $userId,
                'created' => time(),
                'last_active' => time(),
                'ip' => $_SERVER['REMOTE_ADDR']
            ];

            // Encrypt and store
            $this->store->set(
                "session:$sid",
                $this->encryptor->encrypt($data),
                self::MAX_LIFETIME
            );

            $this->monitor->sessionCreated($sessionId);
            return $sid;

        } catch (\Exception $e) {
            $this->monitor->sessionError($sessionId, $e);
            throw $e;
        }
    }

    public function validateSession(string $sid): bool
    {
        try {
            $data = $this->getSession($sid);
            
            if (!$this->isValidSession($data)) {
                return false;
            }

            // Update last active time
            $data['last_active'] = time();
            $this->updateSession($sid, $data);

            return true;

        } catch (\Exception $e) {
            $this->monitor->sessionValidationError($e);
            return false;
        }
    }

    private function isValidSession(array $data): bool
    {
        $now = time();
        
        // Check absolute timeout
        if (($now - $data['created']) > self::MAX_LIFETIME) {
            return false;
        }

        // Check idle timeout
        if (($now - $data['last_active']) > self::IDLE_TIMEOUT) {
            return false;
        }

        return true;
    }
}
