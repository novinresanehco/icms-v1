<?php

namespace App\Core\Tagging;

use App\Core\Security\SecurityContext;
use Illuminate\Support\Collection;

interface TagManagerInterface
{
    /**
     * Creates a new tag with security validation
     *
     * @throws TagException
     * @throws SecurityException
     * @throws ValidationException
     */
    public function createTag(array $data, SecurityContext $context): Tag;

    /**
     * Attaches tags to content with security validation
     *
     * @throws TagException
     * @throws SecurityException
     * @throws ValidationException
     */
    public function attachTags(int $contentId, array $tagIds, SecurityContext $context): void;

    /**
     * Retrieves all tags for given content
     *
     * @throws ContentNotFoundException
     */
    public function getContentTags(int $contentId): Collection;
}

interface TagRepositoryInterface
{
    public function create(array $data): Tag;
    public function findById(int $id): ?Tag;
    public function findByIds(array $ids): Collection;
    public function search(string $query): Collection;
}
