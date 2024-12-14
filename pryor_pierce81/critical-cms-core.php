<?php
namespace App\Core;

class CriticalCmsKernel {
    protected $config = [
        'auth' => ['token_ttl' => 3600],
        'cache' => ['ttl' => 3600],
        'security' => ['max_attempts' => 5]
    ];

    protected $providers = [
        AuthManager::class,
        ContentManager::class,
        TemplateManager::class,
        SecurityManager::class
    ];

    public function bootstrap(): void {
        foreach ($this->providers as $provider) {
            app()->singleton($provider);
        }
    }
}

class SecurityManager {
    public function validateToken(string $token): ?User {
        try {
            $payload = JWT::decode($token, config('app.key'));
            return User::find($payload->user_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function encrypt(string $data): string {
        return encrypt($data);
    }
}

class AuthManager {
    protected SecurityManager $security;
    protected UserRepository $users;

    public function authenticate(array $credentials): array {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthException('Invalid credentials');
        }

        $token = $this->createToken($user);
        return ['user' => $user, 'token' => $token];
    }

    protected function createToken(User $user): string {
        $payload = [
            'user_id' => $user->id,
            'exp' => time() + config('auth.token_ttl')
        ];
        return JWT::encode($payload, config('app.key'));
    }
}

class ContentManager {
    protected ContentRepository $content;
    protected CacheManager $cache;

    public function store(array $data): Content {
        return DB::transaction(function() use ($data) {
            $content = $this->content->create($data);
            $this->cache->tags(['content'])->flush();
            return $content;
        });
    }

    public function update(int $id, array $data): Content {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->content->findOrFail($id);
            $content->update($data);
            $this->cache->tags(['content'])->flush();
            return $content->fresh();
        });
    }
}

class TemplateManager {
    protected TemplateRepository $templates;
    protected CacheManager $cache;

    public function render(string $name, array $data = []): string {
        return $this->cache->remember("template.$name", function() use ($name, $data) {
            $template = $this->templates->findByName($name);
            return view($template->path, $data)->render();
        });
    }
}

class User extends Model {
    protected $hidden = ['password'];
    protected $fillable = ['name', 'email', 'password'];

    protected static function boot() {
        parent::boot();
        static::creating(function($user) {
            $user->password = Hash::make($user->password);
        });
    }
}

class Content extends Model {
    protected $fillable = ['title', 'body', 'status', 'published_at'];
    protected $casts = ['published_at' => 'datetime', 'metadata' => 'array'];
}

class Template extends Model {
    protected $fillable = ['name', 'path', 'config'];
    protected $casts = ['config' => 'array'];
}

class ApiController extends Controller {
    public function login(Request $request) {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        try {
            $result = app(AuthManager::class)->authenticate($credentials);
            return response()->json($result);
        } catch (AuthException $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }

    public function storeContent(Request $request) {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        $content = app(ContentManager::class)->store($data);
        return response()->json($content, 201);
    }

    public function renderTemplate(Request $request, string $name) {
        try {
            $html = app(TemplateManager::class)->render($name, $request->all());
            return response()->json(['html' => $html]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Template error'], 500);
        }
    }
}

Route::prefix('api')->middleware('api')->group(function() {
    Route::post('login', [ApiController::class, 'login']);
    
    Route::middleware('auth')->group(function() {
        Route::post('content', [ApiController::class, 'storeContent']);
        Route::get('templates/{name}', [ApiController::class, 'renderTemplate']);
    });
});
