<?php

namespace App\Core\User;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\UserException;
use App\Core\Validation\ValidationManagerInterface;
use Psr\Log\LoggerInterface;

class UserManager implements UserManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationManagerInterface $validator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationManagerInterface $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function createUser(array $data): User
    {
        $userId = $this->generateUserId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('user:create', $data);
            $this->validateUserData($data);

            $user = $this->processUserCreation($data);
            $this->validateUserCreation($user);

            $this->audit->logUserCreation($userId, $user);

            DB::commit();
            return $user;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleUserFailure($userId, 'create', $e);
            throw new UserException('User creation failed', 0, $e);
        }
    }

    public function updateUser(int $id, array $data): User
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('user:update', [
                'user_id' => $id
            ]);

            $user = $this->findUser($id);
            $this->validateUserUpdate($user, $data);

            $updatedUser = $this->processUserUpdate($user, $data);
            $this->validateUserState($updatedUser);

            $this->audit->logUserUpdate($id, $data);

            DB::commit();
            return $updatedUser;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleUserFailure($id, 'update', $e);
            throw new UserException('User update failed', 0, $e);
        }
    }

    public function deleteUser(int $id): void
    {
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('user:delete', [
                'user_id' => $id
            ]);

            $user = $this->findUser($id);
            $this->validateUserDeletion($user);

            $this->processUserDeletion($user);
            $this->audit->logUserDeletion($id);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleUserFailure($id, 'delete', $e);
            throw new UserException('User deletion failed', 0, $e);
        }
    }

    private function validateUserData(array $data): void
    {
        $this->validator->validateData($data, $this->config['user_rules']);

        if ($this->isUsernameTaken($data['username'])) {
            throw new UserException('Username already exists');
        }

        if ($this->isEmailTaken($data['email'])) {
            throw new UserException('Email already exists');
        }
    }

    private function processUserCreation(array $data): User
    {
        $user = new User();
        $user->fill($this->sanitizeUserData($data));
        $user->password = $this->hashPassword($data['password']);
        $user->status = UserStatus::ACTIVE;
        
        $this->assignDefaultRole($user);
        $this->setSecuritySettings($user);
        
        return $user;
    }

    private function validateUserUpdate(User $user, array $data): void
    {
        if (!$user->isActive()) {
            throw new UserException('Cannot update inactive user');
        }

        if (isset($data['username']) && $this->isUsernameTaken($data['username'], $user->id)) {
            throw new UserException('Username already exists');
        }

        if (isset($data['email']) && $this->isEmailTaken($data['email'], $user->id)) {
            throw new UserException('Email already exists');
        }
    }

    private function handleUserFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('User operation failed', [
            'user_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->audit->logUserFailure($id, $operation, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'user_rules' => [
                'username' => ['required', 'min:3', 'max:50'],
                'email' => ['required', 'email'],
                'password' => ['required', 'min:8', 'secure'],
                'role' => ['required', 'exists:roles']
            ],
            'security_settings' => [
                'password_expiry' => 90,
                'session_timeout' => 3600,
                'max_sessions' => 1
            ],
            'default_role' => 'user'
        ];
    }
}
