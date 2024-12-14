<?php
namespace App\Core\CMS;

class ContentManager
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;

    public function createContent(array $data): Content
    {
        return $this->security->executeSecureOperation(
            new CreateContentOperation(
                $this->validator->validate($data),
                $this->repository
            )
        );
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->security->executeSecureOperation(
            new UpdateContentOperation(
                $id,
                $this->validator->validate($data),
                $this->repository
            )
        );
    }

    public function getContent(int $id): Content
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            return $this->security->executeSecureOperation(
                new GetContentOperation($id, $this->repository)
            );
        });
    }
}

class MediaManager
{
    private SecurityManager $security;
    private StorageManager $storage;
    private ValidatorService $validator;

    public function uploadMedia(UploadedFile $file): Media
    {
        return $this->security->executeSecureOperation(
            new UploadMediaOperation(
                $this->validator->validateFile($file),
                $this->storage
            )
        );
    }
}
