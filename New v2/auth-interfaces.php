<?php

namespace App\Core\Auth\Interfaces;

interface AuthenticationInterface
{
    public function authenticate(array $credentials): AuthResult;
    public function validateSession(string $token): SessionValidationResult;
}

interface SessionInterface
{
    public function create(User $user, string $token): Session;
    public function validate(string $token): SessionValidationResult;
}

interface TokenInterface
{
    public function generate(User $user): string;
    public function validate(string $token): TokenValidationResult;
    public function refresh(string $token): string;
}

class AuthResult
{
    private User $user;
    private string $token;
    private Session $session;

    public function __construct(User $user, string $token, Session $session)
    {
        $this->user = $user;
        $this->token = $token;
        $this->session = $session;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getSession(): Session
    {
        return $this->session;
    }
}

class SessionValidationResult
{
    private bool $valid;
    private ?Session $session;

    public function __construct(bool $valid, ?Session $session = null)
    {
        $this->valid = $valid;
        $this->session = $session;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }
}

class TokenValidationResult
{
    private bool $valid;
    private ?object $payload;

    public function __construct(bool $valid, ?object $payload = null)
    {
        $this->valid = $valid;
        $this->payload = $payload;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getPayload(): ?object
    {
        return $this->payload;
    }
}
