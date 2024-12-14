<?php

namespace App\Services;

use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TagService
{
    protected TagRepositoryInterface $tagRepository;
    
    public function __construct(TagRepositoryInterface $tagRepository)
    {
        $this->tagRepository = $tagRepository;
    }
    
    public function createTag(array $data): ?int
    {
        $this->validateTagData($data);
        return $this->tagRepository->create($data);
    }
    
    public function updateTag(int $tagId, array $data): bool
    {
        $this->validateTagData($data);
        return $this->tagRepository->update($tagId, $data);
    }
    
    public function deleteTag(int $tagId): bool
    {
        return $this->tagRepository->delete($tagId);
    }
    
    public function getTag(int $tagId): ?array
    {
        return $this->tagRepository->get($tagId);
    }
    
    public function getTagBySlug(string $slug): ?array
    {
        return $this->tagRepository->getBySlug($slug);
    }
    
    public function getAllTags(): Collection
    {
        return $this->tagRepository->getAll();
    }
    
    public function findOrCreateTag(string $name): int
    {
        return $this->tagRepository->findOrCreate($name);
    }
    
    public function getPopularTags(int $limit = 10): Collection
    {
        return $this->tagRepository->getPopular($limit);
    }
    
    public function getRelatedTags(int $tagId, int $limit = 5): Collection
    {
        return $this->tagRepository->getRelated($tagId, $limit);
    }
    
    protected function validateTagData(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
