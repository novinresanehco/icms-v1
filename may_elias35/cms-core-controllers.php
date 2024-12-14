<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\{ContentService, MediaService, SecurityService};
use App\Http\Requests\{ContentRequest, MediaRequest};

class ContentController extends Controller
{
    private ContentService $content;
    private SecurityService $security;

    public function store(ContentRequest $request)
    {
        return DB::transaction(function() use ($request) {
            $content = $this->content->create($request->validated());
            $this->security->log('content_created', $content->id);
            return response()->json($content, 201);
        });
    }

    public function update(ContentRequest $request, $id)
    {
        return DB::transaction(function() use ($request, $id) {
            $content = $this->content->update($id, $request->validated());
            $this->security->log('content_updated', $id);
            return response()->json($content);
        });
    }

    public function destroy($id)
    {
        return DB::transaction(function() use ($id) {
            $this->content->delete($id);
            $this->security->log('content_deleted', $id);
            return response()->noContent();
        });
    }
}

class MediaController extends Controller
{
    private MediaService $media;

    public function upload(MediaRequest $request)
    {
        return DB::transaction(function() use ($request) {
            $file = $request->file('file');
            $media = $this->media->store($file);
            return response()->json($media, 201);
        });
    }
}

namespace App\Http\Middleware;

class SecurityMiddleware
{
    private SecurityService $security;

    public function handle($request, Closure $next)
    {
        if (!$this->security->validateToken($request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$this->security->checkPermissions($request->user(), $request->route()->getName())) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}

namespace App\Services;

class ContentService
{
    private Repository $repository;
    private ValidationService $validator;
    private CacheManager $cache;

    public function create(array $data): Content
    {
        $data = $this->validator->validate($data);
        $content = $this->repository->create($data);
        $this->cache->invalidate("content:{$content->id}");
        return $content;
    }

    public function update(int $id, array $data): Content
    {
        $data = $this->validator->validate($data);
        $content = $this->repository->update($id, $data);
        $this->cache->invalidate("content:{$id}");
        return $content;
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
        $this->cache->invalidate("content:{$id}");
    }
}

namespace App\Services;

class MediaService
{
    private StorageManager $storage;
    private ImageProcessor $processor;

    public function store(UploadedFile $file): Media
    {
        $path = $this->storage->store($file, 'media');
        
        if ($this->processor->isImage($file)) {
            $this->processor->optimize($path);
            $this->processor->createThumbnails($path);
        }
        
        return Media::create([
            'path' => $path,
            'type' => $file->getMimeType(),
            'size' => $file->getSize()
        ]);
    }
}

class SecurityService
{
    private AuditLogger $logger;
    private TokenValidator $tokens;
    private PermissionManager $permissions;

    public function validateToken(string $token): bool
    {
        try {
            return $this->tokens->validate($token);
        } catch (\Exception $e) {
            $this->logger->logSecurityEvent('invalid_token', ['token' => substr($token, 0, 8)]);
            return false;
        }
    }

    public function checkPermissions(User $user, string $route): bool
    {
        return $this->permissions->check($user, $route);
    }

    public function log(string $action, $resourceId): void
    {
        $this->logger->log([
            'action' => $action,
            'resource_id' => $resourceId,
            'user_id' => auth()->id(),
            'ip' => request()->ip()
        ]);
    }
}

namespace App\Providers;

class RouteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Route::middleware(['auth:api', 'security'])
            ->prefix('api/v1')
            ->group(function () {
                Route::apiResource('content', ContentController::class);
                Route::post('media/upload', [MediaController::class, 'upload']);
            });
    }
}
