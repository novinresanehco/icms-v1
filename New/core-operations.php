<?php

namespace App\Core\Operations;

abstract class BaseOperation implements OperationInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $logger;
    protected array $data;
    protected ?int $userId;

    public function execute(): OperationResult
    {
        try {
            $this->validate();
            $result = $this->doExecute();
            $this->logger->logOperation($this);
            return $result;
        } catch (\Exception $e) {
            $this->logger->logFailure($e, $this);
            throw $e;
        }
    }

    protected function validate(): void
    {
        if (!$this->validator->validateOperation($this)) {
            throw new ValidationException('Invalid operation');
        }
    }

    abstract protected function doExecute(): OperationResult;

    public function getType(): string
    {
        return static::class;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getData(): array
    {
        return $this->data;
    }
}

class ContentOperation extends BaseOperation
{
    private ContentRepository $repository;
    private CacheManager $cache;

    protected function doExecute(): OperationResult
    {
        return DB::transaction(function() {
            $content = $this->repository->store($this->data);
            $this->cache->invalidate(['content', $content->id]);
            return new OperationResult($content);
        });
    }
}

class MediaOperation extends BaseOperation
{
    private MediaHandler $mediaHandler;
    private StorageManager $storage;

    protected function doExecute(): OperationResult
    {
        $file = $this->data['file'];
        $path = $this->storage->store($file);
        
        return DB::transaction(function() use ($path) {
            $media = $this->mediaHandler->process([
                'path' => $path,
                'type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $this->extractMetadata($file)
            ]);
            return new OperationResult($media);
        });
    }

    private function extractMetadata(UploadedFile $file): array
    {
        return [
            'filename' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize()
        ];
    }
}

class UserOperation extends BaseOperation
{
    private UserRepository $repository;
    private PasswordHasher $hasher;

    protected function validate(): void
    {
        parent::validate();
        
        if (isset($this->data['password'])) {
            $this->data['password'] = $this->hasher->make($this->data['password']);
        }
    }

    protected function doExecute(): OperationResult
    {
        return DB::transaction(function() {
            $user = $this->repository->store($this->data);
            return new OperationResult($user);
        });
    }
}

class OperationResult
{
    private mixed $data;
    private string $type;
    private int $timestamp;

    public function __construct(mixed $data)
    {
        $this->data = $data;
        $this->type = gettype($data);
        $this->timestamp = time();
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}
