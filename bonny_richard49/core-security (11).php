<?php
namespace App\Core\Security;

class CoreSecurityManager
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator, 
        AuditLogger $logger
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    public function authenticateRequest(Request $request): SecurityContext 
    {
        DB::beginTransaction();
        try {
            // Validate request
            $this->validator->validateRequest($request);
            
            // Authenticate user
            $user = $this->getUserFromRequest($request);
            if (!$this->verifyUser($user)) {
                throw new AuthenticationException('Invalid user credentials');
            }

            // Create security context
            $context = new SecurityContext($user, $request);
            
            // Log successful authentication
            $this->logger->logAuthentication($context);

            DB::commit();
            return $context;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logFailure($e);
            throw $e;
        }
    }

    public function authorizeAction(SecurityContext $context, string $action): bool
    {
        try {
            // Verify user permissions
            if (!$this->hasPermission($context->user, $action)) {
                $this->logger->logUnauthorizedAccess($context, $action);
                return false;
            }

            // Log authorized access
            $this->logger->logAuthorizedAccess($context, $action);
            return true;

        } catch (\Exception $e) {
            $this->logger->logFailure($e);
            throw new AuthorizationException('Authorization failed', 0, $e);
        }
    }

    private function hasPermission(User $user, string $action): bool 
    {
        return $user->can($action);
    }

    public function encryptSensitiveData(array $data): array
    {
        return array_map(function($value) {
            return $this->encryption->encrypt($value);
        }, $data);
    }
}
