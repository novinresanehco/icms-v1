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
        return $this->security->executeCriticalOperation(
            new CreateContentOperation(
                $this->validator->validateContent($data),
                $this->repository
            )
        );
    }

    public function update(int $id, array $data): Content 
    {
        return $this->security->executeCriticalOperation(
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
            fn() => $this->security->executeCriticalOperation(
                new GetContentOperation($id, $this->repository)
            )
        );
    }
}

class MediaManager implements MediaManagerInterface 
{
    private SecurityCore $security;
    private StorageManager $storage;
    private ValidationService $validator;

    public function store(UploadedFile $file): Media
    {
        return $this->security->executeCriticalOperation(
            new StoreMediaOperation(
                $this->validator->validateFile($file),
                $this->storage
            )
        );
    }
}

class TemplateManager implements TemplateManagerInterface
{
    private SecurityCore $security;
    private TemplateEngine $engine;
    private CacheManager $cache;

    public function render(string $template, array $data): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation(
                $template,
                $data,
                $this->engine
            )
        );
    }
}
