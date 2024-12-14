<?php
namespace App\Core\CMS;

class ContentManager implements CriticalContentInterface 
{
    private SecurityCore $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;

    public function create(array $data): Content
    {
        return $this->security->validateOperation(
            new CreateContentOperation(
                $this->validator->validateContent($data),
                $this->repository
            )
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->validateOperation(
            new UpdateContentOperation(
                $id,
                $this->validator->validateContent($data),  
                $this->repository
            )
        );
    }

    public function get(int $id): Content
    {
        return $this->cache->remember(
            "content.$id",
            fn() => $this->security->validateOperation(
                new GetContentOperation($id, $this->repository)
            )
        );
    }
}

class MediaManager implements CriticalMediaInterface
{
    private SecurityCore $security;
    private StorageManager $storage;
    private ValidationService $validator;

    public function store(UploadedFile $file): Media
    {
        return $this->security->validateOperation(
            new StoreMediaOperation(
                $this->validator->validateFile($file),
                $this->storage
            )
        );
    }

    public function delete(int $id): void
    {
        $this->security->validateOperation(
            new DeleteMediaOperation($id, $this->storage)
        );
    }
}
