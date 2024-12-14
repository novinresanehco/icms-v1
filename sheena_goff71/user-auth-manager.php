<?php

namespace App\Core\Auth;

use App\Core\Security\{SecurityContext, CoreSecurityManager};
use App\Core\Services\{ValidationService, EncryptionService};
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\{Hash, DB};

class UserAuthManager implements AuthenticationInterface
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private EncryptionService $encryption;
    private CacheManager $cache;
    private UserRepository $users;
    private RoleManager $roles;
    private SessionManager $sessions;
    private AuditLogger $audit;

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        EncryptionService $encryption,
        CacheManager $cache,
        UserRepository $users,
        RoleManager $roles,
        SessionManager $sessions,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->cache = $cache;
        $this->users = $users;
        $this->roles = $roles;
        $this->sessions = $sessions;
        $this->audit = $audit;
    }

    public function authenticate(array $credentials, AuthContext $context): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            new AuthOperation('authenticate', $credentials),
            $context,
            function() use ($credentials, $context) {
                $validated = $this->validator->validate($credentials, $this->getAuthRules());
                
                $user = $this->users->findByEmail($validated['email']);
                
                if (!$user || !Hash::check($validated['password'], $user->password)) {
                    $this->audit->logFailedAuth($validated['email'], $context);
                    throw new AuthenticationException('Invalid credentials');
                }

                if ($this->sessions->hasExceededLimit($user->id)) {
                    $this->audit->logSessionLimitExceeded($user->id);
                    throw new SessionLimitException('Active session limit exceeded');
                }

                $token = $this->sessions->create($user, $context);
                $permissions = $this->roles->getPermissions($user->role_id);
                
                $this->audit->logSuccessfulAuth($user->id, $context);
                
                return new AuthResult($user, $token, $permissions);
            }
        );
    }

    public function validateSession(string $token, AuthContext $context): SessionValidation 
    {
        return $this->cache->remember(
            "session.{$token}",
            function() use ($token, $context) {
                return $this->security->executeCriticalOperation(
                    new AuthOperation('validate_session', ['token' => $token]),
                    $context,
                    function() use ($token) {
                        $session = $this->sessions->validate($token);
                        
                        if (!$session->isValid()) {
                            $this->audit->logInvalidSession($token, $context);
                            throw new InvalidSessionException('Session is invalid or expired');
                        }

                        $user = $this->users->findOrFail($session->user_id);
                        $permissions = $this->roles->getPermissions($user->role_id);
                        
                        return new SessionValidation($session, $user, $permissions);
                    }
                );
            },
            60
        );
    }

    public function invalidateSession(string $token, SecurityContext $context): void 
    {
        $this->security->executeCriticalOperation(
            new AuthOperation('invalidate_session', ['token' => $token]),
            $context,
            function() use ($token) {
                $this->sessions->invalidate($token);
                $this->cache->invalidate("session.{$token}");
                $this->audit->logSessionInvalidation($token, $context);
            }
        );
    }

    public function createUser(array $data, SecurityContext $context): User 
    {
        return $this->security->executeCriticalOperation(
            new AuthOperation('create_user', $data),
            $context,
            function() use ($data) {
                $validated = $this->validator->validate($data, $this->getUserCreationRules());
                
                if ($this->users->emailExists($validated['email'])) {
                    throw new UserExistsException('Email already registered');
                }

                $validated['password'] = Hash::make($validated['password']);
                $user = $this->users->create($validated);
                
                $this->roles->assignDefaultRole($user->id);
                $this->audit->logUserCreation($user->id, $context);
                
                return $user;
            }
        );
    }

    public function updateUser(int $userId, array $data, SecurityContext $context): User 
    {
        return $this->security->executeCriticalOperation(
            new AuthOperation('update_user', $data, $userId),
            $context,
            function() use ($userId, $data) {
                $validated = $this->validator->validate($data, $this->getUserUpdateRules());
                
                if (isset($validated['email']) && $this->users->emailExistsForOther($validated['email'], $userId)) {
                    throw new UserExistsException('Email already registered');
                }

                if (isset($validated['password'])) {
                    $validated['password'] = Hash::make($validated['password']);
                }

                $user = $this->users->update($userId, $validated);
                $this->audit->logUserUpdate($userId, $context);
                $this->invalidateUserSessions($userId);
                
                return $user;
            }
        );
    }

    public function deleteUser(int $userId, SecurityContext $context): void 
    {
        $this->security->executeCriticalOperation(
            new AuthOperation('delete_user', [], $userId),
            $context,
            function() use ($userId) {
                $this->users->delete($userId);
                $this->roles->removeUserRoles($userId);
                $this->invalidateUserSessions($userId);
                $this->audit->logUserDeletion($userId, $context);
            }
        );
    }

    private function invalidateUserSessions(int $userId): void 
    {
        $sessions = $this->sessions->getUserSessions($userId);
        foreach ($sessions as $session) {
            $this->sessions->invalidate($session->token);
            $this->cache->invalidate("session.{$session->token}");
        }
    }

    private function getAuthRules(): array 
    {
        return [
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8',
            'mfa_token' => 'string|size:6'
        ];
    }

    private function getUserCreationRules(): array 
    {
        return [
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8',
            'name' => 'required|string|max:255',
            'role_id' => 'exists:roles,id'
        ];
    }

    private function getUserUpdateRules(): array 
    {
        return [
            'email' => 'email|max:255',
            'password' => 'string|min:8',
            'name' => 'string|max:255',
            'role_id' => 'exists:roles,id'
        ];
    }
}
