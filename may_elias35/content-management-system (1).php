// File: app/Core/Content/Manager/ContentManager.php
<?php

namespace App\Core\Content\Manager;

class ContentManager
{
    protected ContentRepository $repository;
    protected ContentValidator $validator;
    protected VersionManager $versionManager;
    protected ContentCache $cache;
    protected EventDispatcher $events;

    public function create(array $data): Content
    {
        $this->validator->validate($data);
        
        DB::beginTransaction();
        try {
            $content = $this->repository->create($data);
            $this->versionManager->createVersion($content);
            $this->cache->invalidate($content);
            $this->events->dispatch(new ContentCreated($content));
            
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException("Failed to create content: " . $e->getMessage());
        }
    }

    public function update(int $id, array $data): Content
    {
        $content = $this->repository->find($id);
        $this->validator->validateUpdate($content, $data);
        
        DB::beginTransaction();
        try {
            $content = $this->repository->update($id, $data);
            $this->versionManager->createVersion($content);
            $this->cache->invalidate($content);
            $this->events->dispatch(new ContentUpdated($content));
            
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException("Failed to update content: " . $e->getMessage());
        }
    }

    public function publish(int $id): Content
    {
        $content = $this->repository->find($id);
        
        if (!$content->canBePublished()) {
            throw new ContentException("Content cannot be published");
        }

        DB::beginTransaction();
        try {
            $content->publish();
            $this->repository->save($content);
            $this->cache->invalidate($content);
            $this->events->dispatch(new ContentPublished($content));
            
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException("Failed to publish content: " . $e->getMessage());
        }
    }
}

// File: app/Core/Content/Version/VersionManager.php
<?php

namespace App\Core\Content\Version;

class VersionManager
{
    protected ContentVersionRepository $repository;
    protected DiffGenerator $diffGenerator;
    protected VersionConfig $config;

    public function createVersion(Content $content): ContentVersion
    {
        $latestVersion = $this->repository->getLatestVersion($content->id);
        $diff = $this->diffGenerator->generate($latestVersion?->data, $content);
        
        return $this->repository->create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'diff' => $diff,
            'created_by' => auth()->id(),
            'version' => $this->generateVersionNumber($latestVersion),
            'parent_version' => $latestVersion?->id
        ]);
    }

    public function restore(Content $content, int $versionId): Content
    {
        $version = $this->repository->find($versionId);
        
        if ($version->content_id !== $content->id) {
            throw new VersionException("Version does not belong to this content");
        }

        DB::beginTransaction();
        try {
            $content->fill($version->data);
            $content->save();
            
            $this->createVersion($content);
            
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new VersionException("Failed to restore version: " . $e->getMessage());
        }
    }
}

// File: app/Core/Content/Cache/ContentCache.php
<?php

namespace App\Core\Content\Cache;

class ContentCache
{
    protected CacheManager $cache;
    protected array $tags = ['content'];
    protected int $ttl = 3600;

    public function remember(int $id, Closure $callback): ?Content
    {
        return $this->cache->tags($this->tags)
            ->remember($this->getKey($id), $this->ttl, $callback);
    }

    public function invalidate(Content $content): void
    {
        $this->cache->tags($this->tags)->forget($this->getKey($content->id));
        
        foreach ($this->getRelatedTags($content) as $tag) {
            $this->cache->tags([$tag])->flush();
        }
    }

    protected function getRelatedTags(Content $content): array
    {
        return array_merge(
            ['content'],
            ["content.type.{$content->type}"],
            ["content.author.{$content->author_id}"],
            $content->categories->pluck('id')->map(fn($id) => "category.{$id}")->toArray()
        );
    }
}

// File: app/Core/Content/Validation/ContentValidator.php
<?php

namespace App\Core\Content\Validation;

class ContentValidator
{
    protected array $rules = [
        'title' => 'required|string|max:255',
        'body' => 'required|string',
        'type' => 'required|string|in:post,page,article',
        'status' => 'required|string|in:draft,published,archived',
        'author_id' => 'required|exists:users,id',
        'published_at' => 'nullable|date'
    ];

    public function validate(array $data): bool
    {
        $validator = Validator::make($data, $this->rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return true;
    }

    public function validateUpdate(Content $content, array $data): bool
    {
        $rules = $this->rules;
        
        if ($content->isPublished()) {
            $rules = array_merge($rules, $this->getPublishedRules());
        }

        $validator = Validator::make($data, $rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return true;
    }
}
