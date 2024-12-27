<?php

namespace App\Repositories;

use App\Core\Repository\BaseRepository;

class UserRepository extends BaseRepository
{
    protected array $rules = [
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'password' => 'required|string|min:8',
        'role' => 'required|exists:roles,name'
    ];

    public function findByEmail(string $email): ?User
    {
        return $this->cache->remember(
            $this->getCacheKey('email', $email),
            fn() => $this->model->where('email', $email)->first()
        );
    }
}

class ContentRepository extends BaseRepository
{
    protected array $rules = [
        'title' => 'required|string|max:255',
        'body' => 'required|string',
        'status' => 'required|in:draft,published',
        'category_id' => 'required|exists:categories,id',
        'tags' => 'array',
        'tags.*' => 'exists:tags,id'
    ];

    public function store(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = parent::store($data);
            
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }
            
            return $content->fresh('tags');
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = parent::update($id, $data);
            
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }
            
            return $content->fresh('tags');
        });
    }
}

class MediaRepository extends BaseRepository
{
    protected array $rules = [
        'path' => 'required|string',
        'type' => 'required|string',
        'size' => 'required|integer'
    ];
}

class CategoryRepository extends BaseRepository 
{
    protected array $rules = [
        'name' => 'required|string|max:255',
        'slug' => 'required|string|unique:categories',
        'parent_id' => 'nullable|exists:categories,id'
    ];

    public function getTree(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('tree'),
            fn() => $this->model->with('children')->whereNull('parent_id')->get()
        );
    }
}

class TagRepository extends BaseRepository
{
    protected array $rules = [
        'name' => 'required|string|max:255',
        'slug' => 'required|string|unique:tags'
    ];

    public function findBySlug(string $slug): ?Tag
    {
        return $this->cache->remember(
            $this->getCacheKey('slug', $slug),
            fn() => $this->model->where('slug', $slug)->first()
        );
    }
}
