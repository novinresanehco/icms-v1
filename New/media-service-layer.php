<?php

namespace App\Core\Services;

class MediaService implements MediaServiceInterface
{
    private MediaRepository $repository;
    private SecurityValidator $security;
    private FileProcessor $processor;
    private StorageManager $storage;

    public function __construct(
        MediaRepository $repository,
        SecurityValidator $security,
        FileProcessor $processor,
        StorageManager $storage
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->processor = $processor;
        $this->storage = $storage;
    }

    public function upload(UploadedFile $file): Media
    {
        $operation = new UploadOperation($file, $this->storage, $this->processor);
        $result = $this->security->validateOperation($operation);
        
        $mediaData = [
            'path' => $result->getPath(),
            'type' => $file->getMimeType(),
            'size' => $file->getSize()
        ];

        $storeOperation = new StoreOperation($mediaData, $this->repository);
        $storeResult = $this->security->validateOperation($storeOperation);
        
        return $storeResult->getContent();
    }

    public function delete(int $id): bool
    {
        $operation = new DeleteMediaOperation($id, $this->repository, $this->storage);
        $result = $this->security->validateOperation($operation);
        return $result->isSuccess();
    }

    public function find(int $id): ?Media
    {
        $operation = new FindOperation($id, $this->repository);
        $result = $this->security->validateOperation($operation);
        return $result->getContent();
    }
}

class UploadOperation implements Operation
{
    private UploadedFile $file;
    private StorageManager $storage;
    private FileProcessor $processor;

    public function __construct(
        UploadedFile $file,
        StorageManager $storage,
        FileProcessor $processor
    ) {
        $this->file = $file;
        $this->storage = $storage;
        $this->processor = $processor;
    }

    public function getData(): array
    {
        return [
            'name' => $this->file->getClientOriginalName(),
            'type' => $this->file->getMimeType(),
            'size' => $this->file->getSize()
        ];
    }

    public function execute(): OperationResult
    {
        $processed = $this->processor->process($this->file);
        $path = $this->storage->store($processed);
        return new OperationResult(null, true, ['path' => $path]);
    }
}

class DeleteMediaOperation implements Operation
{
    private int $id;
    private MediaRepository $repository;
    private StorageManager $storage;

    public function __construct(
        int $id, 
        MediaRepository $repository,
        StorageManager $storage
    ) {
        $this->id = $id;
        $this->repository = $repository;
        $this->storage = $storage;
    }

    public function getData(): array
    {
        return ['id' => $this->id];
    }

    public function execute(): OperationResult
    {
        $media = $this->repository->find($this->id);
        if ($media) {
            $this->storage->delete($media->path);
            $this->repository->delete($this->id);
        }
        return new OperationResult(null, true);
    }
}

interface MediaServiceInterface
{
    public function upload(UploadedFile $file): Media;
    public function delete(int $id): bool;
    public function find(int $id): ?Media;
}

interface FileProcessor
{
    public function process(UploadedFile $file): UploadedFile;
}

interface StorageManager
{
    public function store(UploadedFile $file): string;
    public function delete(string $path): bool;
}
