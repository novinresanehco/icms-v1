namespace App\Core\Security;

class AuthorizationManager implements AuthorizationInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function authorize(SecurityContext $context, string $permission): bool
    {
        $startTime = microtime(true);

        try {
            // Verify context is valid and not expired
            $this->validateContext($context);

            // Check role-based permissions
            if (!$this->checkRolePermission($context->getRole(), $permission)) {
                $this->logUnauthorized($context, $permission);
                return false;
            }

            // Check additional constraints
            if (!$this->checkConstraints($context, $permission)) {
                $this->logConstraintFailure($context, $permission);
                return false;
            }

            // Log successful authorization
            $this->audit->logAuthorization($context, $permission, true);

            return true;

        } catch (SecurityException $e) {
            $this->handleFailure($context, $permission, $e);
            throw $e;
        } finally {
            $this->recordMetrics($context, $permission, microtime(true) - $startTime);
        }
    }

    private function validateContext(SecurityContext $context): void
    {
        if (!$context->isValid()) {
            throw new SecurityException('Invalid security context');
        }

        if ($context->isExpired()) {
            throw new SecurityException('Expired security context');
        }
    }

    private function checkRolePermission(Role $role, string $permission): bool
    {
        return $this->roles->hasPermission($role, $permission);
    }

    private function checkConstraints(SecurityContext $context, string $permission): bool
    {
        $constraints = $this->permissions->getConstraints($permission);

        foreach ($constraints as $constraint) {
            if (!$constraint->validate($context)) {
                return false;
            }
        }

        return true;
    }

    private function handleFailure(SecurityContext $context, string $permission, \Exception $e): void
    {
        $this->audit->logAuthorizationFailure($context, $permission, $e);
        $this->metrics->incrementFailureCount('authorization', $e->getCode());
    }

    private function recordMetrics(SecurityContext $context, string $permission, float $duration): void
    {
        $this->metrics->record([
            'type' => 'authorization',
            'context' => $context->getIdentifier(),
            'permission' => $permission,
            'duration' => $duration,
            'timestamp' => microtime(true)
        ]);
    }
}
