// src/Core/CMS/ContentManager.php
<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\CMS\Models\Content;
use Illuminate\Support\Facades\{DB, Cache};

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;

    public function create(array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreate($data),
            ['action' => 'content.create', 'data' => $data]
        );
    }

    private function executeCreate(array $data): Content 
    {
        $validated = $this->validator->validate($data);
        
        $content = DB::transaction(function() use ($validated) {
            $content = $this->repository->create($validated);
            $this->clearCaches($content);
            return $content;
        });

        return $content;
    }

    public function update(int $id, array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdate($id, $data),
            ['action' => 'content.update', 'id' => $id, 'data' => $data]
        );
    }

    private function executeUpdate(int $id, array $data): Content 
    {
        $validated = $this->validator->validate($data);
        
        $content = DB::transaction(function() use ($id, $validated) {
            $content = $this->repository->update($id, $validated);
            $this->clearCaches($content);
            return $content;
        });

        return $content;
    }

    private function clearCaches(Content $content): void 
    {
        Cache::tags(['content', "content.{$content->id}"])->flush();
    }
}

// src/Core/CMS/ContentRepository.php
class ContentRepository 
{
    public function create(array $data): Content 
    {
        return Content::create($data);
    }

    public function update(int $id, array $data): Content 
    {
        $content = $this->findOrFail($id);
        $content->update($data);
        return $content;
    }

    public function findOrFail(int $id): Content 
    {
        return Content::findOrFail($id);
    }
}

// src/Core/CMS/ValidationService.php
class ValidationService 
{
    private array $rules = [
        'title' => 'required|string|max:255',
        'content' => 'required|string',
        'status' => 'required|in:draft,published',
        'author_id' => 'required|exists:users,id',
        'category_id' => 'required|exists:categories,id'
    ];

    public function validate(array $data): array 
    {
        return validator($data, $this->rules)->validate();
    }
}