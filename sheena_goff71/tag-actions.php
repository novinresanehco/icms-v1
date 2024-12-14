<?php

namespace App\Core\Tag\Services\Actions;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Services\Actions\DTOs\{TagCreateData, TagUpdateData};
use App\Core\Tag\Repositories\TagRepository;
use App\Core\Tag\Events\{TagCreated, TagUpdated, TagDeleted};
use Illuminate\Support\Facades\{DB, Cache, Event};
use App\Exceptions\TagException;

class CreateTagAction
{
    public function __construct(
        private TagRepository $repository,
        private TagValidator $validator
    ) {}

    public function execute(TagCreateData $data): Tag
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($data) {
            $tag = $this->repository->create($data->toArray());
            
            event(new TagCreated($tag));
            Cache::tags(['tags'])->flush();
            
            return $tag;
        });
    }
}

class UpdateTagAction
{
    public function __construct(
        private TagRepository $repository,
        private TagValidator $validator
    ) {}

    public function execute(int $id, TagUpdateData $data): Tag
    {
        $tag = $this->repository->findOrFail($id);
        $this->validator->validateUpdate($tag, $data);

        return DB::transaction(function () use ($tag, $data) {
            $updatedTag = $this->repository->update($tag, $data->toArray());
            
            event(new TagUpdated($updatedTag));
            Cache::tags(['tags'])->flush();
            
            return $updatedTag;
        });
    }
}

class DeleteTagAction
{
    public function __construct(
        private TagRepository $repository,
        private TagValidator $validator
    ) {}

    public function execute(int $id, bool $force = false): bool
    {
        $tag = $this->repository->findOrFail($id);
        $this->validator->validateDelete($tag);

        return DB::transaction(function () use ($tag, $force) {
            $result = $force 
                ? $this->repository->forceDelete($tag)
                : $this->repository->delete($tag);

            if ($result) {
                event(new TagDeleted($tag));
                Cache::tags(['tags'])->flush();
            }

            return $result;
        });
    }
}

class BulkTagAction
{
    public function __construct(
        private TagRepository $repository,
        private TagValidator $validator
    ) {}

    public function execute(string $action, array $tagIds, array $data = []): array
    {
        $this->validator->validateBulkAction($action, $tagIds, $data);

        return DB::transaction(function () use ($action, $tagIds, $data) {
            $results = [];

            foreach ($tagIds as $tagId) {
                try {
                    $results[$tagId] = match($action) {
                        'delete' => $this->repository->delete($tagId),
                        'update' => $this->repository->update($tagId, $data),
                        default => throw new TagException("Invalid bulk action: {$action}")
                    };
                } catch (\Exception $e) {
                    $results[$tagId] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            Cache::tags(['tags'])->flush();
            return $results;
        });
    }
}
