<?php

namespace App\Core\Security;

class SecurityContext
{
    private User $user;
    private Session $session;
    private array $metadata;

    public function __construct(User $user, Session $session, array $metadata = [])
    {
        $this->user = $user;
        $this->session = $session;
        $this->metadata = $metadata;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUserId(): int 
    {
        return $this->user->id;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getMetadata(string $key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }
        return $this->metadata[$key] ?? $default;
    }

    public function getIpAddress(): ?string
    {
        return $this->metadata['ip_address'] ?? null;
    }

    public function getUserAgent(): ?string 
    {
        return $this->metadata['user_agent'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->user->id,
            'session_id' => $this->session->id,
            'metadata' => $this->metadata
        ];
    }
}
