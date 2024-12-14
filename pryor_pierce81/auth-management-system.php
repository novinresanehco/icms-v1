namespace App\Core\Auth;

class AuthManager implements AuthenticationInterface
{
    private SecurityManager $security;
    private TokenService $tokenService;
    private EncryptionService $encryption;
    private UserRepository $users;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        TokenService $tokenService,
        EncryptionService $encryption,
        UserRepository $users,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->tokenService = $tokenService;
        $this->encryption = $encryption;
        $this->users = $users;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function authenticate(array $credentials): bool
    {
        return $this->security->executeCriticalOperation(
            new AuthenticationOperation(
                $credentials,
                $this->users,
                $this->tokenService
            ),
            SecurityContext::fromRequest()
        );
    }

    public function authorize(User $user, string $permission): bool
    {
        return $this->security->executeCriticalOperation(
            new AuthorizationOperation(
                $user,
                $permission,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function validateContentAccess(User $user, Content $content): bool
    {
        $cacheKey = sprintf('content_access.%d.%d', $user->id, $content->id);
        
        return $this->cache->remember($cacheKey, 300, function () use ($user, $content) {
            return $this->security->executeCriticalOperation(
                new ContentAccessValidationOperation(
                    $user,
                    $content
                ),
                SecurityContext::fromRequest()
            );
        });
    }

    public function validateMediaAccess(User $user, Media $media): bool
    {
        $cacheKey = sprintf('media_access.%d.%d', $user->id, $media->id);
        
        return $this->cache->remember($cacheKey, 300, function () use ($user, $media) {
            return $this->security->executeCriticalOperation(
                new MediaAccessValidationOperation(
                    $user,
                    $media
                ),
                SecurityContext::fromRequest()
            );
        });
    }

    public function createSession(User $user): string
    {
        return $this->security->executeCriticalOperation(
            new SessionCreationOperation(
                $user,
                $this->tokenService,
                $this->encryption
            ),
            SecurityContext::fromRequest()
        );
    }

    public function validateSession(string $token): ?User
    {
        return $this->security->executeCriticalOperation(
            new SessionValidationOperation(
                $token,
                $this->tokenService,
                $this->users,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function revokeSession(string $token): void
    {
        $this->security->executeCriticalOperation(
            new SessionRevocationOperation(
                $token,
                $this->tokenService,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function refreshSession(string $token): string
    {
        return $this->security->executeCriticalOperation(
            new SessionRefreshOperation(
                $token,
                $this->tokenService,
                $this->users,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function checkPermissions(User $user, array $permissions): bool
    {
        $cacheKey = sprintf(
            'user_permissions.%d.%s',
            $user->id,
            md5(serialize($permissions))
        );
        
        return $this->cache->remember($cacheKey, 300, function () use ($user, $permissions) {
            return $this->security->executeCriticalOperation(
                new PermissionCheckOperation(
                    $user,
                    $permissions
                ),
                SecurityContext::fromRequest()
            );
        });
    }
}
