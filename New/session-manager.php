<?php

namespace App\Core\Security;

use App\Core\Cache\CacheManager;
use App\Core\Security\Models\Session;

class SessionManager implements SessionManagerInterface
{
    private CacheManager $cache;
    private int $lifetime;
    private AuditLogger $audit;

    public function __construct(CacheManager $cache, int $lifetime, AuditLogger $audit)
    {
        $this->cache = $cache;
        $this->lifetime = $lifetime;
        $this->audit = $audit;
    }

    public function create(User $user, string $token, array $metadata = []): Session
    {
        $session = new Session([
            'user_id' => $user->id,
            'token' => $token,
            'metadata' => $metadata,
            'expires_at' => now()->addSeconds($this->lifetime),
            'created_at' => now()
        ]);

        $session->save();
        $this->cacheSession($session);
        $this->audit->logSessionCreated($session);

        return $session;
    }

    public function isValid(Session $session): bool
    {
        return $this->getCachedSession($session->id) !== null;
    }

    public function isExpired(Session $session): bool
    {
        return $session->expires_at < now();
    }

    public function refresh(Session $session): void
    {
        $session->expires_at = now()->addSeconds($this->lifetime);
        $session->save();
        $this->cacheSession($session);
    }

    public function terminate(Session $session): void
    {
        $this->cache->forget($this->getSessionKey($session->id));
        $session->delete();
    }

    private function cacheSession(Session $session): void
    {
        $this->cache->put(
            $this->getSessionKey($session->id),
            $session,
            $this->lifetime
        );
    }

    private function getCachedSession(int $id): ?Session
    {
        return $this->cache->get($this->getSessionKey($id));
    }

    private function getSessionKey(int $id): string
    {
        return "session:{$id}";
    }
}
