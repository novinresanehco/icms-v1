<?php

namespace App\Http\Controllers\Api;

use App\Core\CoreSystem;
use App\Core\Security\SecurityManager;
use Illuminate\Http\{Request, Response};

class ApiController
{
    private CoreSystem $core;
    private SecurityManager $security;

    public function __construct(CoreSystem $core, SecurityManager $security)
    {
        $this->core = $core;
        $this->security = $security;
    }

    public function authenticate(Request $request): Response
    {
        $result = $this->security->executeCriticalOperation(
            fn() => $this->core->auth->authenticate($request->only(['email', 'password'])),
            ['action' => 'authenticate']
        );

        return response()->json([
            'token' => $result->token,
            'user' => $result->user
        ]);
    }

    public function content(Request $request): Response
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleContentRequest($request),
            ['action' => 'content_' . strtolower($request->method())]
        );
    }

    public function template(Request $request, string $name): Response
    {
        return $this->security->executeCriticalOperation(
            fn() => response()->json([
                'rendered' => $this->core->template->render($name, $request->all())
            ]),
            ['action' => 'render_template']
        );
    }

    private function handleContentRequest(Request $request): Response
    {
        $result = match($request->method()) {
            'GET' => $this->core->cms->find($request->route('id')),
            'POST' => $this->core->cms->store($request->all()),
            'PUT' => $this->core->cms->update($request->route('id'), $request->all()),
            'DELETE' => $this->core->cms->delete($request->route('id')),
            default => throw new InvalidRequestException('Method not supported')
        };

        return response()->json($result);
    }
}

class AuthController
{
    private CoreSystem $core;
    private SecurityManager $security;

    public function login(Request $request): Response
    {
        $result = $this->security->executeCriticalOperation(
            fn() => $this->core->auth->authenticate($request->only(['email', 'password'])),
            ['action' => 'login']
        );

        return response()->json([
            'token' => $result->token,
            'user' => $result->user
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->security->executeCriticalOperation(
            fn() => $this->core->auth->logout($request->bearerToken()),
            ['action' => 'logout']
        );

        return response()->json(['status' => 'success']);
    }

    public function refresh(Request $request): Response
    {
        $result = $this->security->executeCriticalOperation(
            fn() => $this->core->auth->refresh($request->bearerToken()),
            ['action' => 'refresh_token']
        );

        return response()->json(['token' => $result->token]);
    }
}

// routes/api.php
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('auth:api');

    Route::middleware('auth:api')->group(function () {
        Route::apiResource('content', ContentController::class);
        Route::get('template/{name}', [TemplateController::class, 'render']);
    });
});
