<?php

namespace App\Http\Controllers\Api;

use App\Core\{Auth, CMS, Security, Infrastructure};
use Illuminate\Http\{Request, JsonResponse};

class AuthController
{
    private Auth\AuthManager $auth;
    private Security\SecurityManager $security;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        return response()->json(
            $this->auth->authenticate($credentials)
        );
    }

    public function verify2FA(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'token' => 'required|string|size:6'
        ]);

        return response()->json(
            $this->auth->verify2FA($data['user_id'], $data['token'])
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->invalidateSession($request->bearerToken());
        return response()->json(['status' => 'success']);
    }
}

class ContentController
{
    private CMS\ContentManager $content;
    private Security\SecurityManager $security;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'string|in:draft,published',
            'author_id' => 'integer'
        ]);

        return response()->json(
            $this->content->list($filters)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        return response()->json(
            $this->content->createContent($data, $request->file('media'))
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published'
        ]);

        return response()->json(
            $this->content->updateContent($id, $data)
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->content->deleteContent($id);
        return response()->json(['status' => 'success']);
    }
}

class TemplateController
{
    private Template\TemplateManager $templates;
    private Security\SecurityManager $security;

    public function render(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template' => 'required|string',
            'data' => 'array'
        ]);

        return response()->json([
            'html' => $this->templates->render($data['template'], $data['data'])
        ]);
    }
}

class AdminController
{
    private CMS\ContentManager $content;
    private Template\TemplateManager $templates;
    private Infrastructure\MonitoringService $monitor;

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'content_stats' => $this->content->getStats(),
            'system_health' => $this->monitor->getSystemHealth(),
            'recent_activity' => $this->content->getRecentActivity()
        ]);
    }
}

// Core API Routes Configuration
class ApiRoutes
{
    public static function register($router): void
    {
        $router->group(['prefix' => 'api', 'middleware' => ['api']], function($router) {
            // Auth Routes
            $router->post('auth/login', [AuthController::class, 'login']);
            $router->post('auth/verify', [AuthController::class, 'verify2FA']);
            $router->post('auth/logout', [AuthController::class, 'logout'])
                ->middleware('auth.cms');

            // Protected Routes
            $router->group(['middleware' => ['auth.cms', 'auth.2fa']], function($router) {
                // Content Management
                $router->apiResource('content', ContentController::class);
                
                // Template Management
                $router->post('templates/render', [TemplateController::class, 'render']);
                
                // Admin Dashboard
                $router->get('admin/dashboard', [AdminController::class, 'dashboard'])
                    ->middleware('role:admin');
            });
        });
    }
}
