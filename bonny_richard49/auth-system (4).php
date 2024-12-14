<?php

namespace App\Core\Auth;

class AuthenticationManager
{
    private $userRepository;
    private $tokenManager;
    private $sessionManager;

    public function authenticate(array $credentials): AuthResult
    {
        try {
            $user = $this->userRepository->findByCredentials($credentials);
            if (!$user || !$this->validatePassword($user, $credentials['password'])) {
                throw new AuthenticationException('Invalid credentials');
            }

            $token = $this->tokenManager->generate($user);
            $this->sessionManager->create($user, $token);

            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            throw new AuthenticationException('Authentication failed', 0, $e);
        }
    }

    public function validateSession(string $token): bool
    {
        return $this->sessionManager->validate($token);
    }

    public function logout(string $token): void
    {
        $this->sessionManager->destroy($token);
    }
}

class TokenManager
{
    public function generate(User $user): string
    {
        return bin2hex(random_bytes(32));
    }

    public function validate(string $token): bool
    {
        return $this->findValidToken($token) !== null;
    }
}

class SessionManager
{
    public function create(User $user, string $token): void
    {
        Cache::put($this->getKey($token), [
            'user_id' => $user->id,
            'expires' => time() + 3600,
            'token' => $token
        ], 3600);
    }

    public function validate(string $token): bool
    {
        $session = Cache::get($this->getKey($token));
        return $session && $session['expires'] > time();
    }

    private function getKey(string $token): string
    {
        return 'session:' . $token;
    }
}

class User
{
    public $id;
    public $email;
    public $password;
    public $roles = [];
}

class AuthResult
{
    public $user;
    public $token;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
    }
}

class AuthenticationException extends \Exception {}
