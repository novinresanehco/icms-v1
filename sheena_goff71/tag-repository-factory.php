<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Contracts\{
    TagReadInterface,
    TagWriteInterface,
    TagCacheInterface,
    TagRelationshipInterface
};

class TagRepositoryFactory
{
    /**
     * @var array
     */
    protected array $instances = [];

    /**
     * @var Tag
     */
    protected Tag $model;

    /**
     * @var TagCacheRepository
     */
    protected TagCacheRepository $cacheRepository;

    public function __construct(Tag $model, TagCacheRepository $cacheRepository)
    {
        $this->model = $model;
        $this->cacheRepository = $cacheRepository;
    }

    /**
     * Get read repository instance.
     */
    public function createReadRepository(): TagReadInterface
    {
        return $this->getInstance(TagReadInterface::class, function() {
            return new TagReadRepository($this->model);
        });
    }

    /**
     * Get write repository instance.
     */
    public function createWriteRepository(): TagWriteInterface
    {
        return $this->getInstance(TagWriteInterface::class, function() {
            return new TagWriteRepository($this->model, $this->cacheRepository);
        });
    }

    /**
     * Get cache repository instance.
     */
    public function createCacheRepository(): TagCacheInterface
    {
        return $this->getInstance(TagCacheInterface::class, function() {
            return $this->cacheRepository;
        });
    }

    /**
     * Get relationship repository instance.
     */
    public function createRelationshipRepository(): TagRelationshipInterface
    {
        return $this->getInstance(TagRelationshipInterface::class, function() {
            return new TagRelationshipRepository($this->model);
        });
    }

    /**
     * Create query builder instance.
     */
    public function createQueryBuilder(): TagQueryBuilder
    {
        return new TagQueryBuilder($this->model);
    }

    /**
     * Get repository instance.
     */
    protected function getInstance(string $interface, callable $factory)
    {
        if (!isset($this->instances[$interface])) {
            $this->instances[$interface] = $factory();
        }

        return $this->instances[$interface];
    }

    /**
     * Clear repository instances.
     */
    public function clearInstances(): void
    {
        $this->instances = [];
    }
}
