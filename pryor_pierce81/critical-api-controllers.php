<?php
namespace App\Http\Controllers\Api;

class AuthController extends Controller
{
    protected AuthManager $auth;
    protected ValidationService $validator;

    public function login(Request $request): JsonResponse
    {
        $credentials = $this->validator->validate($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        try {
            $result = $this->auth->authenticate($credentials);
            return response()->json([
                'token' => $result->token,
                'user' => $result->user
            ]);
        } catch (AuthException $e) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }
    }
}

class ContentController extends Controller
{
    protected ContentManager $content;
    protected ValidationService $validator;

    public function index(): JsonResponse
    {
        $contents = $this->content->getPublished();
        return response()->json($contents);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validator->validate($request->all(), [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => 'required|in:draft,published',
            'published_at' => 'nullable|date'
        ]);

        $content = $this->content->create($validated);
        return response()->json($content, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $this->validator->validate($request->all(), [
            'title' => 'string|max:255',
            'body' => 'string',
            'status' => 'in:draft,published',
            'published_at' => 'nullable|date'
        ]);

        try {
            $content = $this->content->update($id, $validated);
            return response()->json($content);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Content not found'], 404);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->content->delete($id);
            return response()->json(null, 204);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Content not found'], 404);
        }
    }
}

class TemplateController extends Controller
{
    protected TemplateManager $templates;
    protected ValidationService $validator;

    public function render(Request $request, string $name): JsonResponse
    {
        $validated = $this->validator->validate($request->all(), [
            'data' => 'array'
        ]);

        try {
            $html = $this->templates->render($name, $validated['data'] ?? []);
            return response()->json(['html' => $html]);
        } catch (TemplateException $e) {
            return response()->json(['error' => 'Template error'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validator->validate($request->all(), [
            'name' => 'required|string|unique:templates',
            'path' => 'required|string',
            'config' => 'nullable|array'
        ]);

        $template = $this->templates->create($validated);
        return response()->json($template, 201);
    }
}

class ApiResponse
{
    public static function success($data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $data
        ], $code);
    }

    public static function error(string $message, int $code = 400): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message
        ], $code);
    }
}

Route::prefix('api')->middleware('api')->group(function() {
    Route::post('auth/login', [AuthController::class, 'login']);
    
    Route::middleware('auth:api')->group(function() {
        Route::apiResource('content', ContentController::class);
        Route::get('templates/{name}/render', [TemplateController::class, 'render']);
        Route::post('templates', [TemplateController::class, 'store']);
    });
});
