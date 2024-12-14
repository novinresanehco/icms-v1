<?php

namespace App\Core\Service;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Content\ContentRepository;
use App\Core\Media\MediaRepository;
use Illuminate\Support\Facades\{DB, Event, Log};

class CoreServiceManager implements ServiceInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ContentRepository $content;
    private MediaRepository $media;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ContentRepository $content,
        MediaRepository $media
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->content = $content;
        $this->media = $media;
    }

    public function handleRequest($request, $context = []): ServiceResponse
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processRequest($request, $context),
            $context
        );
    }

    protected function processRequest($request, array $context): ServiceResponse 
    {
        DB::beginTransaction();
        
        try {
            $operation = $this->resolveOperation($request);
            $validatedData = $this->validateRequest($request, $operation);
            $result = $this->executeOperation($operation, $validatedData, $context);
            
            Event::dispatch("service.{$operation}.complete", [$result]);
            DB::commit();
            
            return new ServiceResponse($result);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServiceException($e->getMessage(), 0, $e);
        }
    }

    protected function resolveOperation($request): string 
    {
        return match($request->type) {
            'content' => 'content.' . $request->action,
            'media' => 'media.' . $request->action,
            'system' => 'system.' . $request->action,
            default => throw new ValidationException('Invalid operation type')
        };
    }

    protected function validateRequest($request, string $operation): array 
    {
        $validator = $this->getValidator($operation);
        return $validator->validate($request->all());
    }

    protected function executeOperation(string $operation, array $data, array $context): mixed
    {
        return match($operation) {
            'content.create' => $this->content->create($data),
            'content.update' => $this->content->update($data['id'], $data),
            'content.delete' => $this->content->delete($data['id']),
            'media.upload' => $this->media->upload($data['file']),
            'media.delete' => $this->media->delete($data['id']),
            default => throw new ServiceException('Operation not supported')
        };
    }

    protected function getValidator(string $operation): ValidatorInterface 
    {
        return match($operation) {
            'content.create', 'content.update' => new ContentValidator(),
            'media.upload' => new MediaValidator(),
            'system.config' => new SystemValidator(),
            default => throw new ServiceException('Invalid validator requested')
        };
    }
}

interface ValidatorInterface 
{
    public function validate(array $data): array;
}

class ContentValidator implements ValidatorInterface
{
    public function validate(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'meta' => 'array'
        ];

        return Validator::make($data, $rules)->validate();
    }
}

class MediaValidator implements ValidatorInterface
{
    public function validate(array $data): array
    {
        $rules = [
            'file' => 'required|file|max:10240|mimes:jpeg,png,pdf',
            'type' => 'required|in:image,document',
            'description' => 'nullable|string|max:1000'
        ];

        return Validator::make($data, $rules)->validate();
    }
}

class SystemValidator implements ValidatorInterface
{
    public function validate(array $data): array
    {
        $rules = [
            'key' => 'required|string|max:255',
            'value' => 'required',
            'type' => 'required|in:string,boolean,integer,array'
        ];

        return Validator::make($data, $rules)->validate();
    }
}

class ServiceResponse 
{
    private mixed $data;
    private array $meta;

    public function __construct(mixed $data, array $meta = [])
    {
        $this->data = $data;
        $this->meta = array_merge([
            'timestamp' => microtime(true),
            'version' => config('app.version')
        ], $meta);
    }

    public function getData(): mixed 
    {
        return $this->data;
    }

    public function getMeta(): array 
    {
        return $this->meta;
    }

    public function toArray(): array 
    {
        return [
            'data' => $this->data,
            'meta' => $this->meta
        ];
    }
}

class ServiceException extends \Exception {}
