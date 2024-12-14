<?php namespace CriticalCms;

class CriticalSystem 
{
    private static $instance;

    public static function init(): void {
        if (!self::$instance) {
            self::$instance = new static();
            self::$instance->bootCriticalSystems();
        }
    }

    protected function bootCriticalSystems(): void {
        DB::statement('SET SESSION sql_require_primary_key = ON');
        
        $this->createCriticalSchema();
        $this->registerCriticalRoutes();
        $this->enableSecurityProtocols();
    }

    protected function createCriticalSchema(): void {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['email', 'deleted_at']);
        });

        Schema::create('auth_logs', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('ip_address');
            $table->boolean('success');
            $table->timestamp('created_at');
            $table->index(['email', 'ip_address', 'created_at']);
        });

        Schema::create('contents', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->fullText(['title', 'body']);
            $table->index(['status', 'published_at', 'deleted_at']);
        });

        Schema::create('templates', function ($table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('path');
            $table->json('config')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['name', 'active', 'deleted_at']);
        });
    }

    protected function registerCriticalRoutes(): void {
        Route::prefix('api')->middleware('api')->group(function () {
            Route::post('auth/login', [AuthController::class, 'login']);
            Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {
                Route::apiResource('content', ContentController::class);
                Route::get('templates/{name}/render', [TemplateController::class, 'render']);
            });
        });
    }

    protected function enableSecurityProtocols(): void {
        app()->singleton(AuthManager::class, function() {
            return new AuthManager(
                new TokenService(config('app.key')),
                new EncryptionService(),
                new RateLimiter()
            );
        });

        app()->singleton(ContentManager::class, function() {
            return new ContentManager(
                new ContentRepository(),
                new ValidationService(),
                new CacheManager()
            );
        });
    }
}

class AuthManager {
    private TokenService $tokens;
    private EncryptionService $crypto;
    private RateLimiter $limiter;

    public function authenticate(array $credentials): array {
        $key = 'auth:' . $credentials['email'];
        if ($this->limiter->tooManyAttempts($key, 5)) {
            throw new AuthException('Too many attempts');
        }

        try {
            $user = User::where('email', $credentials['email'])->firstOrFail();
            if (!$this->crypto->verify($credentials['password'], $user->password)) {
                throw new AuthException('Invalid credentials');
            }

            $token = $this->tokens->generate($user);
            $this->logSuccess($user);
            return ['token' => $token, 'user' => $user];
        } catch (\Exception $e) {
            $this->logFailure($credentials['email']);
            throw $e;
        }
    }

    private function logSuccess(User $user): void {
        DB::table('auth_logs')->insert([
            'email' => $user->email,
            'ip_address' => request()->ip(),
            'success' => true,
            'created_at' => now()
        ]);
    }

    private function logFailure(string $email): void {
        DB::table('auth_logs')->insert([
            'email' => $email,
            'ip_address' => request()->ip(),
            'success' => false,
            'created_at' => now()
        ]);
    }
}

class ContentManager {
    private ContentRepository $repo;
    private ValidationService $validator;
    private CacheManager $cache;

    public function store(array $data): Content {
        return DB::transaction(function() use ($data) {
            $validated = $this->validator->validate($data);
            $content = $this->repo->create($validated);
            $this->cache->tags(['content'])->flush();
            return $content;
        });
    }

    public function update(int $id, array $data): Content {
        return DB::transaction(function() use ($id, $data) {
            $validated = $this->validator->validate($data);
            $content = $this->repo->findOrFail($id);
            $content->update($validated);
            $this->cache->tags(['content', $id])->flush();
            return $content->fresh();
        });
    }
}

class SecurityException extends \Exception {}
class AuthException extends \Exception {}
CriticalSystem::init();
