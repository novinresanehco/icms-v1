<?php

namespace App\Core;

use Illuminate\Support\Facades\Route;
use Illuminate\Http\{Request, Response, JsonResponse};

class CMSController {
    private SecurityManager $security;
    private AuthSystem $auth;
    private ContentManager $content;
    private TemplateManager $template;

    public function __construct(
        SecurityManager $security,
        AuthSystem $auth,
        ContentManager $content,
        TemplateManager $template
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->content = $content;
        $this->template = $template;
    }

    public function login(Request $request): JsonResponse {
        try {
            $result = $this->auth->authenticate($request->only(['email', 'password']));
            return response()->json(['token' => $result['token']]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Authentication failed'], 401);
        }
    }

    public function createContent(Request $request): JsonResponse {
        try {
            $content = $this->content->create(
                $request->except('media'),
                $request->file('media')
            );
            return response()->json($content, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function getContent(int $id): JsonResponse {
        try {
            $content = $this->content->get($id);
            if (!$content) {
                return response()->json(['error' => 'Content not found'], 404);
            }
            return response()->json($content);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function updateContent(Request $request, int $id): JsonResponse {
        try {
            $content = $this->content->update($id, $request->all());
            return response()->json($content);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function deleteContent(int $id): JsonResponse {
        try {
            $result = $this->content->delete($id);
            return response()->json(['success' => $result]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function renderTemplate(Request $request, string $template): Response {
        try {
            $html = $this->template->render($template, $request->all());
            return response($html);
        } catch (\Exception $e) {
            return response($e->getMessage(), 400);
        }
    }
}

// Critical Routes
Route::prefix('api/cms')->group(function() {
    Route::post('login', [CMSController::class, 'login']);
    
    Route::middleware('auth:cms')->group(function() {
        Route::post('content', [CMSController::class, 'createContent']);
        Route::get('content/{id}', [CMSController::class, 'getContent']);
        Route::put('content/{id}', [CMSController::class, 'updateContent']);
        Route::delete('content/{id}', [CMSController::class, 'deleteContent']);
        Route::get('template/{template}', [CMSController::class, 'renderTemplate']);
    });
});

class CMSAuthMiddleware {
    private AuthSystem $auth;

    public function handle(Request $request, \Closure $next): mixed {
        $token = $request->bearerToken();
        if (!$token || !$this->auth->verify($token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
