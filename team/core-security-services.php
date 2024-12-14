<?php

namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $audit;
    private SecurityConfig $config;

    public function authenticate(Request $request): SecurityContext
    {
        DB::beginTransaction();
        try {
            $this->validator->validateAuthRequest($request);
            
            $credentials = $this->encryption->decrypt(
                $request->getCredentials()
            );
            
            $user = $this->auth->validateCredentials($credentials);
            
            if (!$user) {
                $this->audit->logFailedAuth($request);
                throw new AuthenticationException('Invalid credentials');
            }

            $session = $this->auth->createSession($user, [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_id' => $request->header('X-Device-ID')
            ]);

            $this->audit->logSuccessfulAuth($user, $session);
            
            DB::commit();
            
            return new SecurityContext($user, $session);

        } catch (Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($e, $request);
            throw $e;
        }
    }

    public function authorize(SecurityContext $context, string $permission): bool
    {
        try {
            if (!$context->isValid()) {
                $this->audit->logInvalidContext($context);
                throw new SecurityException('Invalid security context');
            }

            $user = $context->getUser();
            $roles = $this->authz->getUserRoles($user);

            foreach ($roles as $role) {
                if ($this->authz->checkPermission($role, $permission)) {
                    $this->audit->logPermissionGranted($user, $permission);
                    return true;
                }
            }

            $this->audit->logPermissionDenied($user, $permission);
            return false;

        } catch (Exception $e) {
            $this->handleAuthzFailure($e, $context, $permission);
            throw $e;
        }
    }

    public function validateRequest(Request $request, array $validations): ValidatedRequest
    {
        try {
            foreach ($validations as $validation) {
                $result = $this->validator->validate($request, $validation);
                
                if (!$result->isValid()) {
                    $this->audit->logValidationFailure($request, $validation);
                    throw new ValidationException($result->getErrors());
                }
            }

            $this->audit->logValidationSuccess($request);
            
            return new ValidatedRequest($request, $validations);

        } catch (Exception $e) {
            $this->handleValidationFailure($e, $request);
            throw $e;
        }
    }

    public function encryptSensitiveData(array $data, array $options = []): EncryptedData
    {
        try {
            $validatedData = $this->validator->validateSensitiveData($data);
            
            $encryptedData = $this->encryption->encrypt(
                $validatedData,
                array_merge($this->config->getEncryptionDefaults(), $options)
            );

            $this->audit->logEncryption($data);
            
            return new EncryptedData($encryptedData);

        } catch (Exception $e) {
            $this->handleEncryptionFailure($e, $data);
            throw $e;
        }
    }

    private function handleAuthFailure(Exception $e, Request $request): void
    {
        $this->audit->logAuthFailure($e, $request, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all()
        ]);

        if ($this->shouldBlockIp($request->ip())) {
            $this->blockIp($request->ip());
        }
    }

    private function handleAuthzFailure(
        Exception $e,
        SecurityContext $context,
        string $permission
    ): void {
        $this->audit->logAuthzFailure($e, $context, $permission, [
            'user' => $context->getUser()->toArray(),
            'session' => $context->getSession()->toArray(),
            'stack_trace' => $e->getTraceAsString()
        ]);
    }

    private function handleValidationFailure(Exception $e, Request $request): void
    {
        $this->audit->logValidationError($e, $request, [
            'input' => $request->all(),
            'rules_failed' => $e->getFailedRules()
        ]);
    }

    private function handleEncryptionFailure(Exception $e, array $data): void
    {
        $this->audit->logEncryptionFailure($e, [
            'data_size' => strlen(serialize($data)),
            'encryption_config' => $this->config->getEncryptionConfig()
        ]);
    }

    private function shouldBlockIp(string $ip): bool
    {
        $attempts = $this->auth->getFailedAttempts($ip);
        return $attempts >= $this->config->getMaxFailedAttempts();
    }

    private function blockIp(string $ip): void
    {
        $this->auth->blockIp($ip, $this->config->getBlockDuration());
        $this->audit->logIpBlocked($ip);
    }
}
