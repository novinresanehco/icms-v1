<?php

namespace App\Core\Services;

use App\Core\Repositories\Repository;
use App\Core\Events\{ContentCreated, ContentUpdated};
use App\Core\Exceptions\ServiceException;
use App\Core\Support\Cache\CacheManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Event, Log};

abstract class BaseService
{
    protected Repository $repository;
    protected array $validators = [];
    protected bool $enableLogging = true;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Create a new resource
     *
     * @param array $data
     * @return Model
     * @throws ServiceException
     */
    public function create(array $data): Model
    {
        try {
            $this->validateData($data);

            DB::beginTransaction();

            $model = $this->repository->create($data);
            
            $this->afterCreate($model, $data);
            
            DB::commit();
            
            $this->logAction('create', $model);
            
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServiceException("Failed to create resource: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Update an existing resource
     *
     * @param Model $model
     * @param array $data
     * @return bool
     * @throws ServiceException
     */
    public function update(Model $model, array $data): bool
    {
        try {
            $this->validateData($data, $model);

            DB::beginTransaction();

            $updated = $this->repository->update($model, $data);
            
            if ($updated) {
                $this->afterUpdate($model, $data);
            }
            
            DB::commit();
            
            $this->logAction('update', $model);
            
            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServiceException("Failed to update resource: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Delete a resource
     *
     * @param Model $model
     * @return bool
     * @throws ServiceException
     */
    public function delete(Model $model): bool
    {
        try {
            DB::beginTransaction();

            $deleted = $this->repository->delete($model);
            
            if ($deleted) {
                $this->afterDelete($model);
            }
            
            DB::commit();
            
            $this->logAction('delete', $model);
            
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServiceException("Failed to delete resource: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Validate input data
     *
     * @param array $data
     * @param Model|null $model
     * @throws ServiceException
     */
    protected function validateData(array $data, ?Model $model = null): void
    {
        foreach ($this->validators as $validator) {
            $validator->validate($data, $model);
        }
    }

    /**
     * Hook for after create operations
     *
     * @param Model $model
     * @param array $data
     */
    protected function afterCreate(Model $model, array $data): void
    {
        // Override in child classes if needed
    }

    /**
     * Hook for after update operations
     *
     * @param Model $model
     * @param array $data
     */
    protected function afterUpdate(Model $model, array $data): void
    {
        // Override in child classes if needed
    }

    /**
     * Hook for after delete operations
     *
     * @param Model $model
     */
    protected function afterDelete(Model $model): void
    {
        // Override in child classes if needed
    }

    /**
     * Log service actions
     *
     * @param string $action
     * @param Model $model
     */
    protected function logAction(string $action, Model $model): void
    {
        if ($this->enableLogging) {
            Log::info("Service action: {$action}", [
                'model' => get_class($model),
                'id' => $model->id,
                'user_id' => auth()->id()
            ]);
        }
    }
}

class ContentService extends BaseService
{
    protected array $validators = [
        ContentTitleValidator::class,
        ContentSlugValidator::class,
        ContentCategoryValidator::class
    ];

    /**
     * Publish content
     *
     * @param Model $content
     * @return bool
     * @throws ServiceException
     */
    public function publish(Model $content): bool
    {
        if ($content->status === 'published') {
            throw new ServiceException('Content is already published');
        }

        return $this->update($content, [
            'status' => 'published',
            'published_at' => now()
        ]);
    }

    /**
     * Archive content
     *
     * @param Model $content
     * @return bool
     * @throws ServiceException
     */
    public function archive(Model $content): bool
    {
        if ($content->status === 'archived') {
            throw new ServiceException('Content is already archived');
        }

        return $this->update($content, [
            'status' => 'archived',
            'archived_at' => now()
        ]);
    }

    /**
     * Find content by slug
     *
     * @param string $slug
     * @return Model|null
     */
    public function findBySlug(string $slug): ?Model
    {
        return $this->repository->findBySlug($slug);
    }

    /**
     * Get published content
     *
     * @return Collection
     */
    public function getPublished(): Collection
    {
        return $this->repository->findPublished();
    }

    /**
     * Override afterCreate to handle additional content operations
     *
     * @param Model $model
     * @param array $data
     */
    protected function afterCreate(Model $model, array $data): void
    {
        // Handle tags if present
        if (isset($data['tags'])) {
            $model->tags()->sync($data['tags']);
        }

        // Handle featured image
        if (isset($data['featured_image'])) {
            $this->handleFeaturedImage($model, $data['featured_image']);
        }

        Event::dispatch(new ContentCreated($model));
    }

    /**
     * Handle featured image upload and processing
     *
     * @param Model $model
     * @param mixed $image
     */
    protected function handleFeaturedImage(Model $model, $image): void
    {
        // Image processing logic here
    }
}
