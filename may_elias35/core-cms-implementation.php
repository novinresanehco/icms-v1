<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{Hash, Auth, Cache, DB};

class ContentManager
{
    protected ContentRepository $content;
    protected CategoryRepository $category;
    protected MediaRepository $media;
    protected SecurityManager $security;

    public function createContent(array $data, array $media = []): Content 
    {
        return DB::transaction(function() use ($data, $media) {
            $content = $this->content->create($data);
            
            if (!empty($media)) {
                $this->media->attachToContent($content->id, $media);
            }

            Cache::tags(['content'])->flush();
            return $content;
        });
    }

    public function updateContent(int $id, array $data): Content 
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->content->update($id, $data);
            Cache::tags(['content'])->flush();
            return $content;
        });
    }
}

class AuthenticationManager 
{
    protected UserRepository $users;
    protected TokenService $tokens;

    public function authenticate(array $credentials): array
    {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        return [
            'token' => $this->tokens->create($user),
            'user' => $user
        ];
    }

    public function validateToken(string $token): bool
    {
        try {
            return $this->tokens->verify($token);
        } catch (\Exception $e) {
            return false;
        }
    }
}

class ContentRepository
{
    protected Content $model;

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->model->create([
                'title' => $data['title'],
                'slug' => str_slug($data['title']),
                'content' => $data['content'],
                'status' => $data['status'] ?? 'draft',
                'user_id' => Auth::id()
            ]);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->model->findOrFail($id);
            
            $content->update([
                'title' => $data['title'] ?? $content->title,
                'content' => $data['content'] ?? $content->content,
                'status' => $data['status'] ?? $content->status
            ]);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            return $content;
        });
    }
}

class MediaManager 
{
    protected MediaRepository $media;
    protected StorageService $storage;

    public function store(UploadedFile $file): Media
    {
        return DB::transaction(function() use ($file) {
            $path = $this->storage->store($file, 'media');
            
            return $this->media->create([
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'user_id' => Auth::id()
            ]);
        });
    }
}

class CategoryManager
{
    protected CategoryRepository $categories;

    public function create(array $data): Category
    {
        return DB::transaction(function() use ($data) {
            return $this->categories->create([
                'name' => $data['name'],
                'slug' => str_slug($data['name']),
                'parent_id' => $data['parent_id'] ?? null
            ]);
        });
    }
}

trait HasMedia 
{
    public function media()
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function attachMedia($mediaIds)
    {
        return $this->media()->syncWithoutDetaching($mediaIds);
    }
}

trait HasCategories
{
    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }
}

// Models
class Content extends Model
{
    use HasMedia, HasCategories;

    protected $fillable = [
        'title', 'slug', 'content', 
        'status', 'user_id'
    ];

    protected $casts = [
        'published_at' => 'datetime'
    ];
}

class Category extends Model 
{
    protected $fillable = [
        'name', 'slug', 'parent_id'
    ];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}

class Media extends Model
{
    protected $fillable = [
        'name', 'path', 'mime_type',
        'size', 'user_id'
    ];

    public function mediable()
    {
        return $this->morphTo();
    }
}

// Middleware
class AuthenticateApi
{
    protected AuthenticationManager $auth;

    public function handle($request, $next)
    {
        if (!$token = $request->bearerToken()) {
            throw new AuthenticationException('No token provided');
        }

        if (!$this->auth->validateToken($token)) {
            throw new AuthenticationException('Invalid token');
        }

        return $next($request);
    }
}
