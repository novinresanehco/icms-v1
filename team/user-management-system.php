<?php

namespace App\Core\User;

class UserManager implements UserManagerInterface
{
    private SecurityManager $security;
    private AuthService $auth;
    private ValidationService $validator;
    private AuditLogger $audit;
    private Repository $repository;
    private PasswordHasher $hasher;

    public function createUser(array $data, SecurityContext $context): User
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateAccess($context, 'user:create');
            
            $validatedData = $this->validator->validateInput(
                $data,
                $this->getUserRules()
            );
            
            $validatedData['password'] = $this->hasher->hash(
                $validatedData['password']
            );
            
            $user = $this->repository->create($validatedData);
            
            $this->assignDefaultRole($user);
            $this->audit->logUserCreation($user, $context);
            
            DB::commit();
            return $user;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, __FUNCTION__, $data);
            throw $e;
        }
    }

    public function updateUser(int $id, array $data, SecurityContext $context): User
    {
        DB::beginTransaction();
        
        try {
            $user = $this->getUser($id);
            $this->security->validateAccess($context, 'user:update', $user);
            
            $validatedData = $this->validator->validateInput(
                $data,
                $this->getUpdateRules()
            );
            
            if (isset($validatedData['password'])) {
                $validatedData['password'] = $this->hasher->hash(
                    $validatedData['password']
                );
            }
            
            $user->update($validatedData);
            $this->audit->logUserUpdate($user, $context);
            
            DB::commit();
            return $user;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, __FUNCTION__, ['id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    public function deleteUser(int $id, SecurityContext $context): bool
    {
        DB::beginTransaction();
        
        try {
            $user = $this->getUser($id);
            $this->security->validateAccess($context, 'user:delete', $user);
            
            $this->validateDeletion($user);
            
            $this->repository->softDelete($user);
            $this->audit->logUserDeletion($user, $context);
            
            $this->auth->revokeAllTokens($user);
            
            DB::commit();
            return true;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, __FUNCTION__, ['id' => $id]);
            throw $e;
        }
    }

    public function assignRole(int $userId, int $roleId, SecurityContext $context): bool
    {
        DB::beginTransaction();
        
        try {
            $user = $this->getUser($userId);
            $role = $this->getRoleOrFail($roleId);
            
            $this->security->validateAccess($context, 'role:assign');
            $this->validateRoleAssignment($user, $role);
            
            $user->assignRole($role);
            $this->audit->logRoleAssignment($user, $role, $context);
            
            $this->auth->refreshPermissions($user);
            
            DB::commit();
            return true;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, __FUNCTION__, [
                'user_id' => $userId,
                'role_id' => $roleId
            ]);
            throw $e;
        }
    }

    private function validateDeletion(User $user): void
    {
        if ($user->isSuperAdmin()) {
            throw new SecurityException('Cannot delete super admin user');
        }

        if ($this->hasActiveChildren($user)) {
            throw new ValidationException('User has active subordinates');
        }
    }

    private function validateRoleAssignment(User $user, Role $role): void
    {
        if ($role->isSuperAdmin() && !$this->context->isSuperAdmin()) {
            throw new SecurityException('Only super admins can assign super admin role');
        }

        if ($user->hasRole($role)) {
            throw new ValidationException('User already has this role');
        }
    }

    private function assignDefaultRole(User $user): void
    {
        $defaultRole = $this->repository->getDefaultRole();
        $user->assignRole($defaultRole);
    }

    private function handleFailure(Exception $e, string $operation, array $data): void
    {
        $this->audit->logError($e, [
            'operation' => $operation,
            'data' => $this->maskSensitiveData($data),
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->audit->logSecurityEvent(
                SecurityEvent::USER_ACCESS_DENIED,
                $data
            );
        }
    }

    private function maskSensitiveData(array $data): array
    {
        $sensitiveFields = ['password', 'token', 'secret'];
        
        foreach ($data as $key => &$value) {
            if (in_array($key, $sensitiveFields)) {
                $value = '********';
            }
        }
        
        return $data;
    }

    private function getUserRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string', 'min:12'],
            'department_id' => ['required', 'exists:departments,id'],
            'status' => ['required', 'in:active,inactive']
        ];
    }

    private function getUpdateRules(): array
    {
        return [
            'name' => ['string', 'max:255'],
            'email' => ['email', 'unique:users'],
            'password' => ['string', 'min:12'],
            'department_id' => ['exists:departments,id'],
            'status' => ['in:active,inactive']
        ];
    }
}
