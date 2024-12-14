<?php

namespace App\Core\Content\Contracts;

interface ContentRepositoryInterface
{
    /**
     * Create a new content record
     *
     * @param array $data Validated content data
     * @throws \App\Core\Content\Exceptions\ContentCreationException
     * @return \App\Core\Content\Models\Content
     */
    public function create(array $data): Content;

    /**
     * Update an existing content record
     *
     * @param int $id Content identifier
     * @param array $data Updated content data
     * @throws \App\Core\Content\Exceptions\ContentNotFoundException
     * @throws \App\Core\Content\Exceptions\ContentUpdateException
     * @return \App\Core\Content\Models\Content
     */
    public function update(int $id, array $data): Content;

    /**
     * Find content by ID
     *
     * @param int $id Content identifier
     * @throws \App\Core\Content\Exceptions\ContentNotFoundException
     * @return \App\Core\Content\Models\Content
     */
    public function find(int $id): ?Content;

    /**
     * Delete content by ID
     *
     * @param int $id Content identifier
     * @throws \App\Core\Content\Exceptions\ContentNotFoundException
     * @throws \App\Core\Content\Exceptions\ContentDeletionException
     * @return bool
     */
    public function delete(int $id): bool;
}

namespace App\Core\Content\Repositories;

use App\Core\Content\Models\Content;
use App\Core\Content\Exceptions\ContentNotFoundException;
use App\Core\Content\Exceptions\ContentCreationException;
use App\Core\Content\Exceptions\ContentUpdateException;
use App\Core\Content\Exceptions\ContentDeletionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ContentRepository implements ContentRepositoryInterface
{
    /**
     * @var Content
     */
    protected Content $model;

    /**
     * @param Content $model
     */
    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $data): Content
    {
        DB::beginTransaction();
        
        try {
            $content = $this->model->create($data);
            
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }
            
            $this->clearContentCache();
            DB::commit();
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentCreationException($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        
        try {
            $content = $this->find($id);
            
            if (!$content) {
                throw new ContentNotFoundException("Content with ID {$id} not found");
            }
            
            $content->update($data);
            
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }
            
            $this->clearContentCache($id);
            DB::commit();
            
            return $content;
        } catch (ContentNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentUpdateException($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?Content
    {
        return Cache::remember(
            "content.{$id}",
            config('cache.ttl', 3600),
            fn() => $this->model->with(['tags', 'category'])->find($id)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): bool
    {
        DB::beginTransaction();
        
        try {
            $content = $this->find($id);
            
            if (!$content) {
                throw new ContentNotFoundException("Content with ID {$id} not found");
            }
            
            $content->tags()->detach();
            $content->delete();
            
            $this->clearContentCache($id);
            DB::commit();
            
            return true;
        } catch (ContentNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentDeletionException($e->getMessage());
        }
    }

    /**
     * Clear content cache
     *
     * @param int|null $id Specific content ID to clear
     * @return void
     */
    protected function clearContentCache(?int $id = null): void
    {
        if ($id) {
            Cache::forget("content.{$id}");
        }
        Cache::tags(['content'])->flush();
    }
}
