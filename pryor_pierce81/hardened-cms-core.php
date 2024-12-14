<?php namespace CriticalCms;

class CoreSystem {
    private static $instance;
    private SecurityManager $security;
    private RateLimiter $limiter;
    private Logger $logger;

    public static function boot(): void {
        if (!self::$instance) {
            self::$instance = new static();
            self::$instance->initializeCriticalSystems();
        }
    }

    protected function initializeCriticalSystems(): void {
        $this->security = new SecurityManager(new EncryptionService());
        $this->limiter = new RateLimiter();
        $this->logger = new Logger();

        $this->enableSecurityProtocols();
        $this->initializeCriticalSchema();
        $this->registerCriticalRoutes();
    }

    protected function enableSecurityProtocols(): void {
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        
        DB::statement('SET SESSION sql_require_primary_key = ON');
        DB::statement('SET SESSION sql_safe_updates = ON');
    }

    protected function initializeCriticalSchema(): void {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('email')->unique();
                $table->string('password');
                $table->boolean('is_admin')->default(false);
                $table->integer('failed_attempts')->default(0);
                $table->timestamp('locked_until')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('contents')) {
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
            });
        }

        if (!Schema::hasTable('templates')) {
            Schema::create('templates', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('path');
                $table->json('config')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function ($table) {
                $table->id();
                $table->string('action');
                $table->string('entity_type');
                $table->unsignedBigInteger('entity_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->json('data')->nullable();
                $table->string('ip_address');
                $table->timestamp('created_at');
            });
        }
    }

    protected function registerCriticalRoutes(): void {
        Route::middleware(['api', 'throttle:60,1'])->group(function () {
            Route::post('auth/login', [AuthController::class, 'login']);
            
            Route::middleware('auth:api')->group(function () {
                Route::apiResource('content', ContentController::class)->middleware('validate.content');
                Route::apiResource('templates', TemplateController::class)->middleware('validate.template');
            });
        });
    }
}

class SecurityManager {
    private EncryptionService $crypto;
    private TokenGenerator $tokens;
    private array $securityHeaders = [
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'X-Content-Type-Options' => 'nosniff',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'self'"
    ];

    public function validateRequest(Request $request): bool {
        if ($this->detectSuspiciousActivity($request)) {
            throw new SecurityException('Suspicious activity detected');
        }

        if (!$this->validateToken($request)) {
            throw new AuthException('Invalid token');
        }

        return true;
    }

    public function secureResponse(Response $response): Response {
        foreach ($this->securityHeaders as $header => $value) {
            $response->headers->set($header, $value);
        }
        return $response;
    }

    private function detectSuspiciousActivity(Request $request): bool {
        return $request->header('X-Forwarded-For') !== null ||
               $request->header('X-Real-IP') !== $request->ip() ||
               !$this->validateUserAgent($request->userAgent());
    }
}

class AuthController extends Controller {
    private SecurityManager $security;
    private RateLimiter $limiter;

    public function login(Request $request) {
        if ($this->limiter->tooManyAttempts($request->ip(), 5)) {
            throw new TooManyAttemptsException();
        }

        try {
            $user = User::where('email', $request->email)->firstOrFail();
            
            if ($user->failed_attempts >= 5 && $user->locked_until > now()) {
                throw new AccountLockedException();
            }

            if (!Hash::check($request->password, $user->password)) {
                $user->increment('failed_attempts');
                if ($user->failed_attempts >= 5) {
                    $user->update(['locked_until' => now()->addMinutes(30)]);
                }
                throw new InvalidCredentialsException();
            }

            $user->update(['failed_attempts' => 0, 'locked_until' => null]);
            
            return response()->json([
                'token' => $this->security->createToken($user),
                'user' => $user
            ]);

        } catch (\Exception $e) {
            $this->limiter->hit($request->ip());
            throw $e;
        }
    }
}

CoreSystem::boot();
