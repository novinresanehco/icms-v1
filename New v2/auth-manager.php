<?php

namespace App\Core\Security;

class AuthManager implements AuthManagerInterface
{
    private TokenManager $tokens;
    private SessionManager $sessions;
    private EncryptionService $encryption;
    private AuditLogger $audit;

    public function __construct(
        TokenManager $tokens,
        SessionManager $sessions,
        EncryptionService $encryption,
        AuditLogger $audit
    ) {
        $this->tokens = $tokens;
        $this->sessions = $sessions;
        $this->encryption = $encryption;
        $this->audit = $audit;
    }

    public function validateSession(Session $session): bool
    {
        if (!$this->sessions->isValid($session)) {
            $this->audit->logInvalidSession($session);
            return false;
        }

        if ($this->sessions->isExpired($session)) {
            $this->audit->logExpiredSession($session);
            return false;
        }

        $this->sessions->refresh($session);
        return true;
    }

    public function createSession(User $user, array $metadata = []): Session
    {
        $token = $this->tokens->generate();
        $session = $this->sessions->create($user, $token, $metadata);
        $this->audit->logNewSession($session);
        return $session;
    }

    public function terminateSession(Session $session): void
    {
        $this->sessions->terminate($session);
        $this->audit->logSessionTermination($session);
    }

    public function validateToken(string $token): bool
    {
        return $this->tokens->validate($token);
    }
}
