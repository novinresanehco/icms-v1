<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{AuthException, ValidationException};

class AuthManager
{
    private SecurityManager $security;
    private UserRepository $users;
    private SessionManager $sessions;
    private TwoFactorAuth $twoFactor;

    public function __construct(
        SecurityManager $security,
        UserRepository $users,
        SessionManager $sessions,
        TwoFactorAuth $twoFactor
    ) {
        $this->security = $security;
        $this->users = $users;
        $this->sessions = $sessions;
        $this->twoFactor = $twoFactor;
    }

    public function authenticate(array $credentials): array
    {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthException('Invalid credentials');
        }

        $token = $this->twoFactor->generate($user);

        Cache::put(
            "2fa.{$user->id}",
            ['token' => $token, 'attempts' => 0],
            now()->addMinutes(15)
        );

        return [
            'status' => 'requires_2fa',
            'user_id' => $user->id
        ];
    }

    public function verify2FA(int $userId, string $token): array
    {
        $cacheKey = "2fa.{$userId}";
        $data = Cache::get($cacheKey);

        if (!$data || $data['attempts'] >= 3) {
            throw new AuthException('Too many attempts');
        }

        if (!$this->twoFactor->verify($token, $data['token'])) {
            Cache::put(
                $cacheKey,
                ['token' => $data['token'], 'attempts' => $data['attempts'] + 1],
                now()->addMinutes(15)
            );
            throw new AuthException('Invalid token');
        }

        $user = $this->users->find($userId);
        $session = $this->sessions->create($user);

        Cache::forget($cacheKey);

        return [
            'token' => $session->token,
            'user' => $user->toArray()
        ];
    }

    public function validateSession(string $token): User
    {
        $session = $this->sessions->validate($token);
        return $this->users->find($session->user_id);
    }
}

class SessionManager
{
    private Session $model;
    private int $lifetime = 900; // 15 minutes

    public function create(User $user): Session
    {
        return $this->model->create([
            'user_id' => $user->id,
            'token' => $this->generateToken(),
            'expires_at' => now()->addSeconds($this->lifetime)
        ]);
    }

    public function validate(string $token): Session
    {
        $session = $this->model
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            throw new AuthException('Invalid or expired session');
        }

        $session->expires_at = now()->addSeconds($this->lifetime);
        $session->save();

        return $session;
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}

class TwoFactorAuth
{
    public function generate(User $user): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function verify(string $input, string $stored): bool
    {
        return hash_equals($input, $stored);
    }
}

class UserRepository
{
    private User $model;

    public function find(int $id): ?User
    {
        return $this->model->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }
}

class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password',
        'role'
    ];

    protected $hidden = [
        'password'
    ];
}

class Session extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime'
    ];
}
