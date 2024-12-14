<?php

namespace App\Core\Security\Session;

class SessionManager implements SessionInterface
{
    private SessionStore $sessionStore;
    private TokenGenerator $tokenGenerator;
    private SessionValidator $validator;
    private TimeoutManager $timeoutManager;
    private SessionLogger $logger;
    private SecurityProtocol $security;

    public function __construct(
        SessionStore $sessionStore,
        TokenGenerator $tokenGenerator,
        SessionValidator $validator,
        TimeoutManager $timeoutManager,
        SessionLogger $logger,
        SecurityProtocol $security
    ) {
        $this->sessionStore = $sessionStore;
        $this->tokenGenerator = $tokenGenerator;
        $this->validator = $validator;
        $this->timeoutManager = $timeoutManager;
        $this->logger = $logger;
        $this->security = $security;
    }

    public function createSession(SessionRequest $request): SessionResult
    {
        $sessionId = $this->initializeSession($request);
        
        try {
            DB::beginTransaction();

            $this->validateRequest($request);
            $token = $this->generateSessionToken($request);
            $this->validateToken($token);

            $session = new Session([
                'sessionId' => $sessionId,
                'token' => $token,
                'securityContext' => $this->createSecurityContext($request),
                'timeout' => $this->timeoutManager->calculateTimeout($request),
                'restrictions' => $this->security->getSessionRestrictions()
            ]);

            $this->storeSession($session);

            DB::commit();
            return new SessionResult(['session' => $session]);

        } catch (SessionException $e) {
            DB::rollBack();
            $this->handleSessionFailure($e, $sessionId);
            throw new CriticalSessionException($e->getMessage(), $e);
        }
    }

    public function validateSession(string $token): ValidationResult
    {
        try {
            $session = $this->sessionStore->getSession($token);
            
            if (!$session) {
                throw new InvalidSessionException('Session not found');
            }

            $this->validateSessionState($session);
            $this->checkTimeout($session);
            $this->validateSecurityContext($session);

            return new ValidationResult(['valid' => true, 'session' => $session]);

        } catch (ValidationException $e) {
            $this->handleValidationFailure($e, $token);
            throw new CriticalValidationException($e->getMessage(), $e);
        }
    }

    private function generateSessionToken(SessionRequest $request): string
    {
        return $this->tokenGenerator->generate([
            'entropy' => config('security.session.token_entropy'),
            'context' => $request->getSecurityContext()
        ]);
    }

    private function validateSessionState(Session $session): void
    {
        if (!$this->validator->validateState($session)) {
            $this->security->invalidateSession($session);
            throw new InvalidStateException('Session state validation failed');
        }
    }

    private function checkTimeout(Session $session): void
    {
        if ($this->timeoutManager->isExpired($session)) {
            $this->security->handleSessionTimeout($session);
            throw new SessionTimeoutException('Session has expired');
        }
    }
}
