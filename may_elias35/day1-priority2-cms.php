<?php
namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface
{
    private SecurityCore $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;

    public function create(array $data): Content
    {
        return $this->security->validateCriticalOperation(
            new CreateContentOperation(
                $this->validator->validate($data),
                $this->repository
            )
        );
    }

    public function get(int $id): Content
    {
        return $this->cache->remember(
            "content.$id",
            fn() => $this->security->validateCriticalOperation(
                new GetContentOperation($id, $this->repository)
            )
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->validateCriticalOperation(
            new UpdateContentOperation(
                $id,
                $this->validator->validate($data),
                $this->repository
            )
        );
    }
}

class MediaManager
{
    private SecurityCore $security;
    private StorageManager $storage;
    private ValidationService $validator;

    public function store(UploadedFile $file): Media
    {
        return $this->security->validateCriticalOperation(
            new StoreMediaOperation(
                $this->validator->validateFile($file),
                $this->storage
            )
        );
    }
}
