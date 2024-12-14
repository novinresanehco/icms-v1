<?php

namespace App\Core\Auth;

class CriticalUserManager implements UserManagerInterface
{
    protected UserRepository $users;
    protected PasswordHasher $hasher;
    protected AuditLogger $logger;

    public function authenticate(string $username, string $password): User
    {
        $user = $this->users->findByUsername($username);
        
        if (!$user || !$this->hasher->verify($password, $user->password)) {
            $this->logger->failedLogin($username);
            throw new AuthException('Invalid credentials');
        }

        return $user;
    }

    public function createUser(array $data): User
    {
        DB::beginTransaction();
        try {
            $data['password'] = $this->hasher->hash($data['password']);
            
            $user = $this->users->create($data);
            
            DB::commit();
            return $user;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function validateCredentials(array $credentials): bool
    {
        return !empty($credentials['username']) && 
               !empty($credentials['password']) &&
               strlen($credentials['password']) >= 8;
    }
}

class PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}

class UserRepository
{
    public function findByUsername(string $username): ?User
    {
        return User::where('username', $username)->first();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }
}
