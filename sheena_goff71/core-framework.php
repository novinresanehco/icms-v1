<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationManager;
use App\Core\CMS\ContentManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\{CacheService, ValidationService, LogService};

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityManager::class);
        $this->app->singleton(AuthenticationManager::class);
        $this->app->singleton(ContentManager::class);
        $this->app->singleton(TemplateManager::class);
        $this->app->singleton(CacheService::class);
        $this->app->singleton(ValidationService::class);
        $this->app->singleton(LogService::class);
    }
}

namespace App\Http\Controllers;

use App\Core\Auth\AuthenticationManager;
use App\Core\CMS\ContentManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    private AuthenticationManager $auth;

    public function __construct(AuthenticationManager $auth)
    {
        $this->auth = $auth;
    }

    public function login(Request $request): JsonResponse
    {
        $result = $this->auth->authenticate($request->only(['email', 'password']));
        return response()->json(['token' => $result->token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->bearerToken());
        return response()->json(['status' => 'success']);
    }
}

class ContentController extends Controller
{
    private ContentManager $content;

    public function __construct(ContentManager $content)
    {
        $this->content = $content;
        $this->middleware('auth');
    }

    public function store(Request $request): JsonResponse
    {
        $content = $this->content->createContent(
            $request->all(),
            $request->user()
        );
        return response()->json($content);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $content = $this->content->updateContent(
            $id,
            $request->all(),
            $request->user()
        );
        return response()->json($content);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $this->content->deleteContent($id, $request->user());
        return response()->json(['status' => 'success']);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $content = $this->content->getContent($id, $request->user());
        return response()->json($content);
    }

    public function index(Request $request): JsonResponse
    {
        $content = $this->content->getContentList(
            $request->all(),
            $request->user()
        );
        return response()->json($content);
    }
}

namespace App\Http\Middleware;

use App\Core\Auth\AuthenticationManager;
use Closure;
use Illuminate\Http\Request;

class AuthMiddleware
{
    private AuthenticationManager $auth;

    public function __construct(AuthenticationManager $auth)
    {
        $this->auth = $auth;
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        
        if (!$token || !$this->auth->validateSession($token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
