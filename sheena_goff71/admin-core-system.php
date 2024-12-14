<?php

namespace App\Core\Admin;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;

class AdminController
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected ContentRepository $content;
    protected MediaRepository $media;
    protected UserRepository $users;

    public function index()
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->content->getPaginated(20),
            ['action' => 'admin.view']
        );
    }

    public function store(array $data)
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->processStore($data),
            ['action' => 'admin.create']
        );
    }

    public function update(int $id, array $data)
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->processUpdate($id, $data),
            ['action' => 'admin.update']
        );
    }

    protected function processStore(array $data)
    {
        DB::beginTransaction();
        try {
            $validated = $this->validator->validate($data, [
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'required|in:draft,published',
                'media' => 'array',
                'meta' => 'array'
            ]);

            $content = $this->content->create($validated);
            
            if (!empty($validated['media'])) {
                $this->media->attachToContent($content->id, $validated['media']);
            }

            DB::commit();
            Cache::tags(['content'])->flush();
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function processUpdate(int $id, array $data)
    {
        DB::beginTransaction();
        try {
            $validated = $this->validator->validate($data, [
                'title' => 'sometimes|string|max:255',
                'content' => 'sometimes|string',
                'status' => 'sometimes|in:draft,published',
                'media' => 'sometimes|array',
                'meta' => 'sometimes|array'
            ]);

            $content = $this->content->update($id, $validated);
            
            if (isset($validated['media'])) {
                $this->media->syncWithContent($content->id, $validated['media']);
            }

            DB::commit();
            Cache::tags(['content'])->flush();
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

class ContentRepository
{
    public function getPaginated(int $perPage = 20)
    {
        return Cache::tags(['content'])->remember(
            'content.page.' . request()->get('page', 1),
            3600,
            fn() => Content::with(['media', 'author'])
                ->orderByDesc('created_at')
                ->paginate($perPage)
        );
    }

    public function create(array $data)
    {
        $content = Content::create([
            'title' => $data['title'],
            'content' => $data['content'],
            'status' => $data['status'],
            'meta' => $data['meta'] ?? [],
            'author_id' => auth()->id()
        ]);

        Log::info('Content created', ['id' => $content->id]);
        return $content;
    }

    public function update(int $id, array $data)
    {
        $content = Content::findOrFail($id);
        $content->update($data);
        
        Log::info('Content updated', ['id' => $content->id]);
        return $content->fresh();
    }
}

class MediaRepository
{
    public function attachToContent(int $contentId, array $mediaIds): void
    {
        $content = Content::findOrFail($contentId);
        $content->media()->attach($mediaIds);
    }

    public function syncWithContent(int $contentId, array $mediaIds): void
    {
        $content = Content::findOrFail($contentId);
        $content->media()->sync($mediaIds);
    }
}

class UserRepository
{
    public function getAdmins()
    {
        return Cache::remember('admin.users', 3600, function() {
            return User::whereHas('roles', function($query) {
                $query->where('name', 'admin');
            })->get();
        });
    }
}

class AdminMiddleware
{
    public function handle($request, $next)
    {
        if (!auth()->check() || !auth()->user()->hasRole('admin')) {
            Log::warning('Unauthorized admin access attempt', [
                'ip' => request()->ip(),
                'user' => auth()->id()
            ]);
            throw new UnauthorizedException('Admin access required');
        }

        return $next($request);
    }
}

class UnauthorizedException extends \Exception {}
