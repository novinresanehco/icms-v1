<?php

namespace App\Core\Repositories;

interface RepositoryInterface
{
    public function find(int $id);
    public function findWhere(array $criteria);
    public function all();
    public function create(array $attributes);
    public function update(int $id, array $attributes);
    public function delete(int $id);
}

abstract class BaseRepository implements RepositoryInterface
{
    protected $model;

    public function __construct()
    {
        $this->makeModel();
    }

    abstract protected function model(): string;

    protected function makeModel()
    {
        $model = app($this->model());
        return $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->findOrFail($id);
    }

    public function findWhere(array $criteria)
    {
        return $this->model->where($criteria)->get();
    }

    public function all()
    {
        return $this->model->all();
    }

    public function create(array $attributes)
    {
        return $this->model->create($attributes);
    }

    public function update(int $id, array $attributes)
    {
        $model = $this->find($id);
        $model->update($attributes);
        return $model;
    }

    public function delete(int $id): bool
    {
        return $this->find($id)->delete();
    }
}

// Content Repository
namespace App\Repositories;

use App\Models\Content;
use App\Core\Repositories\BaseRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

class ContentRepository extends BaseRepository
{
    protected function model(): string
    {
        return Content::class;
    }

    public function findBySlug(string $slug)
    {
        return Cache::remember("content.slug.{$slug}", 3600, function () use ($slug) {
            return $this->model->where('slug', $slug)->firstOrFail();
        });
    }

    public function getPublished()
    {
        return Cache::remember('content.published', 3600, function () {
            return $this->model
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get();
        });
    }

    public function createWithMeta(array $attributes, array $meta = [])
    {
        return DB::transaction(function () use ($attributes, $meta) {
            $content = $this->create($attributes);
            
            if (!empty($meta)) {
                $content->meta()->createMany($meta);
            }
            
            Cache::tags(['content'])->flush();
            
            return $content->load('meta');
        });
    }

    public function updateWithMeta(int $id, array $attributes, array $meta = [])
    {
        return DB::transaction(function () use ($id, $attributes, $meta) {
            $content = $this->update($id, $attributes);
            
            if (!empty($meta)) {
                $content->meta()->delete();
                $content->meta()->createMany($meta);
            }
            
            Cache::tags(['content'])->flush();
            
            return $content->load('meta');
        });
    }
}

// User Repository
namespace App\Repositories;

use App\Models\User;
use App\Core\Repositories\BaseRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository
{
    protected function model(): string
    {
        return User::class;
    }

    public function findByEmail(string $email)
    {
        return $this->model->where('email', $email)->first();
    }

    public function create(array $attributes)
    {
        if (isset($attributes['password'])) {
            $attributes['password'] = Hash::make($attributes['password']);
        }

        return parent::create($attributes);
    }

    public function update(int $id, array $attributes)
    {
        if (isset($attributes['password'])) {
            $attributes['password'] = Hash::make($attributes['password']);
        }

        return parent::update($id, $attributes);
    }

    public function getAdmins()
    {
        return Cache::remember('users.admins', 3600, function () {
            return $this->model->whereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })->get();
        });
    }

    public function attachRole(int $userId, int $roleId)
    {
        $user = $this->find($userId);
        $user->roles()->attach($roleId);
        Cache::tags(['users', 'roles'])->flush();
        return $user->load('roles');
    }
}

// Media Repository
namespace App\Repositories;

use App\Models\Media;
use App\Core\Repositories\BaseRepository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class MediaRepository extends BaseRepository
{
    protected function model(): string
    {
        return Media::class;
    }

    public function upload(UploadedFile $file, array $attributes = [])
    {
        $path = $file->store('media', 'public');
        
        $attributes = array_merge($attributes, [
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'path' => $path,
            'size' => $file->getSize()
        ]);

        return $this->create($attributes);
    }

    public function delete(int $id): bool
    {
        $media = $this->find($id);
        Storage::disk('public')->delete($media->path);
        return parent::delete($id);
    }

    public function getByType(string $type)
    {
        return $this->model
            ->where('mime_type', 'like', $type . '/%')
            ->latest()
            ->get();
    }
}
