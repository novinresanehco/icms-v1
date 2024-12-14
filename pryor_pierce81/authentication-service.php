<?php

namespace App\Core\Security\Authentication;

class AuthenticationService implements AuthenticationInterface
{
    private IdentityVerifier $identityVerifier;
    private CredentialValidator $credentialValidator;
    private MFAManager $mfaManager;
    private SessionManager $sessionManager;
    private AuthenticationLogger $logger;
    private SecurityProtocol $security;

    public function __construct(
        IdentityVerifier $identityVerifier,
        CredentialValidator $credentialValidator,
        MFAManager $mfaManager,
        SessionManager $sessionManager,
        AuthenticationLogger $logger,
        SecurityProtocol $security
    ) {
        $this->identityVerifier = $identityVerifier;
        $this->credentialValidator = $credentialValidator;
        $this->mfaManager = $mfaManager;
        $this->sessionManager = $sessionManager;
        $this->logger = $logger;
        $this->security = $security;
    }

    public function authenticate(AuthenticationRequest $request): AuthenticationResult
    {
        $authId = $this->initializeAuthentication($request);
        
        try {
            DB::beginTransaction();

            $identity = $this->verifyIdentity($request);
            $this->validateCredentials($request, $identity);
            $this->verifyMFA($request, $identity);

            $session = $this->createSecureSession($identity);
            $this->validateSession($session);

            $result = new AuthenticationResult([
                'authId' => $authId,
                'identity' => $identity,
                'session' => $session,
                'status' => AuthenticationStatus::AUTHENTICATED,
                'timestamp' => now()
            ]);

            DB::commit();
            $this->finalizeAuthentication($result);

            return $result;

        } catch (AuthenticationException $e) {
            DB::rollBack();
            $this->handleAuthenticationFailure($e, $authId);
            throw new CriticalAuthenticationException($e->getMessage(), $e);
        }
    }

    private function verifyIdentity(AuthenticationRequest $request): Identity
    {
        $identity = $this->identityVerifier->verify($request->getIdentityToken());
        
        if (!$identity->isValid()) {
            throw new InvalidIdentityException('Identity verification failed');
        }
        return $identity;
    }

    private function validateCredentials(AuthenticationRequest $request, Identity $identity): void
    {
        if (!$this->credentialValidator->validate($request->getCredentials(), $identity)) {
            $this->security->handleFailedAttempt($identity);
            throw new InvalidCredentialsException('Invalid credentials provided');
        }
    }

    private function verifyMFA(AuthenticationRequest $request, Identity $identity): void
    {
        if (!$this->mfaManager->verify($request->getMFAToken(), $identity)) {
            $this->security->handleMFAFailure($identity);
            throw new MFAVerificationException('MFA verification failed');
        }
    }

    private function createSecureSession(Identity $identity): Session
    {
        return $this->sessionManager->createSession([
            'identity' => $identity,
            'securityLevel' => SecurityLevel::CRITICAL,
            'timeout' => config('security.session.timeout'),
            'restrictions' => config('security.session.restrictions')
        ]);
    }
}
