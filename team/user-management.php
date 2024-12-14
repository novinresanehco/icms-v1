<?php

namespace App\Core\User;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Monitoring\MonitoringService;
use Illuminate\Support\Facades\{Hash, DB};

class UserManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    private RoleService $roles;

    /**
     * Execute critical user operation with full protection
     */
    public function executeUserOperation(string $operation, callable $action, array $context = []): mixed
    {
        $operationId = $this->monitor->startOperation($operation);
        
        try {
            // Validate operation context
            $this->validator->validateContext($context);
            
            // Check permissions
            $this->security->validateAccess($operation);
            
            DB::beginTransaction();
            
            // Execute with monitoring
            $result = $this->monitor->track($operationId, $action);
            
            // Verify result
            $this->validator->validateResult($result);
            
            DB::commit();
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $operation, $context);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Create new user with security validation
     */
    public function createUser(array $data): User
    {
        return $this->executeUserOperation('user:create', function() use ($data) {
            // Validate user data
            $validated = $this->validator->validateUserData($data);
            
            // Hash password
            $validated['password'] = Hash::make($validated['password']);
            
            // Create user
            $user = $this->createUserRecord($validated);
            
            // Assign roles
            if (isset($validated['roles'])) {
                $this->roles->assignRoles($user, $validated['roles']);
            }
            
            return $user;
        });
    }

    /**
     * Update user with security checks
     */
    public function updateUser(int $id, array $data): User
    {
        return $this->executeUserOperation('user:update', function() use ($id, $data) {
            // Find user with locking
            $user = $this->findUserWithLock($id);
            
            // Validate update data
            $validated = $this->validator->validateUserUpdate($data);
            
            // Update password if provided
            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }
            
            // Update user
            $user = $this->updateUserRecord($user, $validated);
            
            // Update roles if provided
            if (isset($validated['roles'])) {
                $this->roles->syncRoles($user, $validated['roles']);
            }
            
            return $user;
        });
    }

    /**
     * Delete user with security validation
     */
    public function deleteUser(int $id): void
    {
        $this->executeUserOperation('user:delete', function() use ($id) {
            // Find user with locking
            $user = $this->findUserWithLock($id);
            
            // Remove roles
            $this->roles->removeAllRoles($user);
            
            // Delete user
            $this->deleteUserRecord($user);
        });
    }

    /**
     * Find user with row locking
     */
    private function findUserWithLock(int $id): User
    {
        $user = User::lockForUpdate()->find($id);
        
        if (!$user) {
            throw new UserNotFoundException("User {$id} not found");
        }
        
        return $user;
    }

    /**
     * Create user record
     */
    private function createUserRecord(array $data): User
    {
        $user = new User($data);
        $user->save();
        
        // Log creation
        $this->monitor->logUserAction('create', $user);
        
        return $user;
    }

    /**
     * Update user record
     */
    private function updateUserRecord(User $user, array $data): User
    {
        $user->fill($data);
        $user->save();
        
        // Log update
        $this->monitor->logUserAction('update', $user);
        
        return $user;
    }

    /**
     * Delete user record
     */
    private function deleteUserRecord(User $user): void
    {
        $user->delete();
        
        // Log deletion
        $this->monitor->logUserAction('delete', $user);
    }

    /**
     * Handle operation failures
     */
    private function handleOperationFailure(\Throwable $e, string $operation, array $context): void
    {
        $this->monitor->recordFailure('user_operation', [
            'operation' => $operation,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->notifyAdministrators('user_operation_failure', [
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);
    }
}

class RoleService
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;

    /**
     * Assign roles to user
     */
    public function assignRoles(User $user, array $roles): void
    {
        foreach ($roles as $role) {
            $this->validateRole($role);
            $user->roles()->attach($role);
        }
        
        $this->monitor->logRoleAssignment($user, $roles);
    }

    /**
     * Sync user roles
     */
    public function syncRoles(User $user, array $roles): void
    {
        foreach ($roles as $role) {
            $this->validateRole($role);
        }
        
        $user->roles()->sync($roles);
        
        $this->monitor->logRoleSync($user, $roles);
    }

    /**
     * Remove all roles from user
     */
    public function removeAllRoles(User $user): void
    {
        $user->roles()->detach();
        
        $this->monitor->logRoleRemoval($user);
    }

    /**
     * Validate role before assignment
     */
    private function validateRole($role): void
    {
        if (!$this->validator->validateRole($role)) {
            throw new InvalidRoleException("Invalid role: {$role}");
        }

        if (!$this->security->canAssignRole($role)) {
            throw new UnauthorizedRoleAssignmentException("Unauthorized role assignment: {$role}");
        }
    }
}
