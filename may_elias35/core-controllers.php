<?php

namespace App\Http\Controllers;

class ContentController extends Controller
{
    protected ContentManager $content;
    protected SecurityManager $security;
    protected TemplateManager $template;

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'required|in:draft,published',
                'categories' => 'array',
                'media' => 'array'
            ]);

            $content = DB::transaction(function() use ($validated) {
                return $this->content->createContent(
                    $validated,
                    $validated['media'] ?? []
                );
            });

            return response()->json($content, 201);
        } catch (\Exception $e) {
            throw new ApiException('Content creation failed', 500, $e);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->security->validateAccess($request->user(), $id, 'content:update');

        $content = DB::transaction(function() use ($request, $id) {
            return $this->content->updateContent($id, $request->validated());
        });

        return response()->json($content);
    }

    public function render(Request $request, int $id): Response
    {
        $content = $this->content->find($id);
        
        if (!$content) {
            throw new NotFoundException('Content not found');
        }

        return response()->make(
            $this->template->render('content.show', ['content' => $content])
        );
    }
}

class AuthController extends Controller
{
    protected AuthenticationService $auth;
    protected UserRepository $users;
    
    public function login(Request $request): JsonResponse
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string'
            ]);

            $result = $this->auth->authenticate([
                ...$credentials,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'token' => $result->token,
                'user' => $result->user
            ]);
        } catch (AuthenticationException $e) {
            throw new ApiException('Authentication failed', 401, $e);
        } catch (RateLimitException $e) {
            throw new ApiException('Too many attempts', 429, $e);
        }
    }

    public function refresh(Request $request): JsonResponse
    {
        try {
            $token = $this->auth->refresh($request->bearerToken());
            return response()->json(['token' => $token]);
        } catch (\Exception $e) {
            throw new ApiException('Token refresh failed', 401, $e);
        }
    }
}

class MediaController extends Controller 
{
    protected MediaManager $media;
    protected SecurityManager $security;

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240'
        ]);

        try {
            $media = DB::transaction(function() use ($request) {
                return $this->media->store($request->file('file'));
            });

            return response()->json($media, 201);
        } catch (\Exception $e) {
            throw new ApiException('Upload failed', 500, $e);
        }
    }
}

class CategoryController extends Controller
{
    protected CategoryManager $categories;
    protected SecurityManager $security;

    public function store(Request $request): JsonResponse
    {
        $this->security->validateAccess($request->user(), 'category:create');

        try {
            $category = DB::transaction(function() use ($request) {
                return $this->categories->create($request->validated());
            });

            return response()->json($category, 201);
        } catch (\Exception $e) {
            throw new ApiException('Category creation failed', 500, $e);
        }
    }
}

class ThemeController extends Controller
{
    protected ThemeManager $themes;
    protected SecurityManager $security;

    public function activate(Request $request, int $id): JsonResponse
    {
        $this->security->validateAccess($request->user(), 'theme:manage');

        try {
            $theme = DB::transaction(function() use ($id) {
                return $this->themes->setActive($id);
            });

            return response()->json($theme);
        } catch (\Exception $e) {
            throw new ApiException('Theme activation failed', 500, $e);
        }
    }
}

class ApiException extends HttpException
{
    public function __construct(
        string $message,
        int $code = 500,
        \Throwable $previous = null
    ) {
        parent::__construct($code, $message, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => $this->getMessage(),
            'code' => $this->getCode()
        ], $this->getCode());
    }
}
