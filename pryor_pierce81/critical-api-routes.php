<?php
namespace App\Core;

class ApiRouter 
{
    public static function register(): void
    {
        Route::prefix('api/v1')->group(function() {

            // Auth Routes
            Route::post('auth/login', [AuthController::class, 'login']);
            Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:api');
            Route::get('auth/me', [AuthController::class, 'me'])->middleware('auth:api');

            // Protected Routes
            Route::middleware(['auth:api', 'throttle:60,1'])->group(function() {
                
                // Content Management
                Route::prefix('content')->group(function() {
                    Route::get('/', [ContentController::class, 'index']);
                    Route::post('/', [ContentController::class, 'store']);
                    Route::get('{id}', [ContentController::class, 'show']);
                    Route::put('{id}', [ContentController::class, 'update']);
                    Route::delete('{id}', [ContentController::class, 'destroy']);
                    Route::post('{id}/publish', [ContentController::class, 'publish']);
                });

                // Media Management
                Route::prefix('media')->group(function() {
                    Route::post('upload', [MediaController::class, 'upload']);
                    Route::delete('{id}', [MediaController::class, 'delete']);
                });

                // Template Management
                Route::prefix('templates')->group(function() {
                    Route::get('/', [TemplateController::class, 'index']);
                    Route::post('/', [TemplateController::class, 'store']);
                    Route::get('{id}', [TemplateController::class, 'show']);
                    Route::put('{id}', [TemplateController::class, 'update']);
                    Route::post('{id}/render', [TemplateController::class, 'render']);
                });

                // System Management
                Route::prefix('system')->group(function() {
                    Route::get('health', [SystemController::class, 'health']);
                    Route::post('cache/clear', [SystemController::class, 'clearCache']);
                });
            });

            // Error Handler
            Route::fallback(function() {
                return response()->json([
                    'error' => 'Not Found',
                    'message' => 'The requested resource does not exist'
                ], 404);
            });
        });
    }
}

class RouteServiceProvider extends ServiceProvider 
{
    public function boot(): void
    {
        parent::boot();

        // Global Response Format
        Response::macro('api', function($data = null, string $message = '', int $code = 200) {
            return response()->json([
                'success' => $code < 400,
                'message' => $message,
                'data' => $data
            ], $code);
        });

        // Global Middleware
        $this->app['router']->middleware([
            'auth.api' => CheckApiAuth::class,
            'throttle' => ThrottleRequests::class
        ]);

        // Register Routes
        ApiRouter::register();
    }
}

class CheckApiAuth 
{
    public function handle($request, Closure $next)
    {
        if (!$token = $request->bearerToken()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $user = app(AuthManager::class)->validateToken($token);
            if (!$user) {
                throw new AuthException('Invalid token');
            }
            
            $request->setUserResolver(fn() => $user);
            return $next($request);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
    }
}
