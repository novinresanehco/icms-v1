<?php

namespace App\Core;

use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware('api')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth.cms');
    
    Route::middleware(['auth.cms'])->group(function () {
        Route::prefix('content')->group(function () {
            Route::get('/', [ContentController::class, 'index'])->middleware('permission:content.view');
            Route::post('/', [ContentController::class, 'store'])->middleware('permission:content.create');
            Route::put('{id}', [ContentController::class, 'update'])->middleware('permission:content.edit');
            Route::delete('{id}', [ContentController::class, 'destroy'])->middleware('permission:content.delete');
        });

        Route::prefix('media')->group(function () {
            Route::post('upload', [MediaController::class, 'upload'])->middleware('permission:media.upload');
            Route::get('browse', [MediaController::class, 'browse'])->middleware('permission:media.view');
        });
        
        Route::prefix('admin')->middleware('permission:admin.access')->group(function () {
            Route::get('dashboard', [AdminController::class, 'dashboard']);
            Route::get('content/{id}/edit', [AdminController::class, 'editContent']);
            Route::post('content/{id}', [AdminController::class, 'saveContent']);
        });
    });
});

return [
    'api' => [
        'auth' => [
            'token_ttl' => env('AUTH_TOKEN_TTL', 3600),
            'refresh_ttl' => env('AUTH_REFRESH_TTL', 86400),
            'lock_enabled' => env('AUTH_LOCK_ENABLED', true),
            'max_attempts' => env('AUTH_MAX_ATTEMPTS', 5),
        ],
        'rate_limits' => [
            'login' => '5,1',
            'content' => '60,1',
            'media' => '30,1',
        ]
    ],
    'security' => [
        'key' => env('APP_KEY'),
        'cipher' => 'AES-256-CBC',
        'hash' => 'sha256',
        'secure_cookies' => env('SECURE_COOKIES', true),
        'proxy_headers' => env('PROXY_HEADERS', true),
    ],
    'services' => [
        'cache' => [
            'driver' => env('CACHE_DRIVER', 'redis'),
            'prefix' => env('CACHE_PREFIX', 'cms'),
            'ttl' => env('CACHE_TTL', 3600),
        ],
        'media' => [
            'disk' => env('MEDIA_DISK', 'public'),
            'allowed_types' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'application/pdf',
            ],
            'max_size' => env('MEDIA_MAX_SIZE', 10240),
        ],
    ]
];

interface ApiResponse
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    
    public static function success($data = null, string $message = ''): array
    {
        return [
            'status' => self::STATUS_SUCCESS,
            'data' => $data,
            'message' => $message
        ];
    }
    
    public static function error(string $message, $errors = null, int $code = 400): array
    {
        return [
            'status' => self::STATUS_ERROR,
            'message' => $message,
            'errors' => $errors,
            'code' => $code
        ];
    }
}

interface ApiInterface
{
    public function list(array $params): array;
    public function create(array $data): array;
    public function update(int $id, array $data): array;
    public function delete(int $id): array;
    public function validate(array $data, string $operation): bool;
}

namespace App\Core\Http\Controllers;

class ContentController extends Controller implements ApiInterface
{
    private ContentManager $content;
    private CoreSecurityManager $security;

    public function index(Request $request): JsonResponse
    {
        try {
            $result = $this->security->executeSecureOperation(
                fn() => $this->content->list($request->all()),
                ['permission' => 'content.view']
            );
            return response()->json(ApiResponse::success($result));
        } catch (\Exception $e) {
            return response()->json(
                ApiResponse::error($e->getMessage()),
                $e instanceof SecurityException ? 403 : 400
            );
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $result = $this->security->executeSecureOperation(
                fn() => $this->content->create($request->all()),
                [
                    'permission' => 'content.create',
                    'data' => $request->all()
                ]
            );
            return response()->json(ApiResponse::success($result));
        } catch (\Exception $e) {
            return response()->json(
                ApiResponse::error($e->getMessage()),
                $e instanceof ValidationException ? 422 : 400
            );
        }
    }
}
