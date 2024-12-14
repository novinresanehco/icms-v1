<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Operations\CriticalOperationManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Exceptions\{ContentException, ValidationException};
use App\Models\Content;
use Illuminate\Support\Facades\{DB, Cache, Event};

class ContentManager
{
    private SecurityManager $security;
    private CriticalOperationManager $operations;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CriticalOperationManager $operations,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->operations = $operations;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function createContent(array $data, array $meta = []): Content
    {
        return $this->operations->executeOperation(
            new ContentOperation('create', [
                'data' => $data,
                'meta' => $meta,
                'validation_rules' => $this->config['validation']['create']
            ])
        );
    }

    public function updateContent(int $id, array $data, array $meta = []): Content
    {
        $content = $this->findContent($id);

        return $this->operations->executeOperation(
            new ContentOperation('update', [
                'content' => $content,
                'data' => $data,
                'meta' => $meta,
                'validation_rules' => $this->config['validation']['update']
            ])
        );
    }

    public function publishContent(int $id): Content
    {
        $content = $this->findContent($id);

        return $this->operations->executeOperation(
            new ContentOperation('publish', [
                'content' => $content,
                'validation_rules' => $this->config['validation']['publish']
            ])
        );
    }

    public function deleteContent(int $id): bool
    {
        $content = $this->findContent($id);

        return $this->operations->executeOperation(
            new ContentOperation('delete', [
                'content' => $content,
                'validation_rules' => $this->config['validation']['delete']
            ])
        );
    }

    protected function findContent(int $id): Content
    {
        $content = Cache::remember(
            "content.{$id}",
            $this->config['cache_ttl'],
            fn() => Content::findOrFail($id)
        );

        if (!$content) {
            throw new ContentException("Content not found: {$id}");
        }

        return $content;
    }
}

class ContentOperation implements CriticalOperation
{
    private string $type;
    private array $data;
    private ValidationService $validator;
    private AuditService $audit;

    public function __construct(string $type, array $data)
    {
        $this->type = $type;
        $this->data = $data;
        $this->validator = app(ValidationService::class);
        $this->audit = app(AuditService::class);
    }

    public function execute(): mixed
    {
        DB::beginTransaction();

        try {
            $result = match($this->type) {
                'create' => $this->executeCreate(),
                'update' => $this->executeUpdate(),
                'publish' => $this->executePublish(),
                'delete' => $this->executeDelete(),
                default => throw new ContentException("Invalid operation type: {$this->type}")
            };

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function executeCreate(): Content
    {
        $this->validateData($this->data['data'], $this->data['validation_rules']);

        $content = new Content($this->data['data']);
        $content->meta = $this->data['meta'];
        $content->save();

        $this->audit->logContentOperation('create', $content);
        Event::dispatch(new ContentCreated($content));

        return $content;
    }

    protected function executeUpdate(): Content
    {
        $content = $this->data['content'];
        $this->validateData($this->data['data'], $this->data['validation_rules']);

        $content->update($this->data['data']);
        $content->meta = array_merge($content->meta ?? [], $this->data['meta']);
        $content->save();

        $this->audit->logContentOperation('update', $content);
        Event::dispatch(new ContentUpdated($content));

        return $content;
    }

    protected function executePublish(): Content
    {
        $content = $this->data['content'];
        
        if (!$this->validator->validateForPublishing($content)) {
            throw new ValidationException('Content failed publishing validation');
        }

        $content->published_at = now();
        $content->save();

        $this->audit->logContentOperation('publish', $content);
        Event::dispatch(new ContentPublished($content));

        return $content;
    }

    protected function executeDelete(): bool
    {
        $content = $this->data['content'];
        
        $this->audit->logContentOperation('delete', $content);
        Event::dispatch(new ContentDeleted($content));

        return $content->delete();
    }

    protected function validateData(array $data, array $rules): void
    {
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Content validation failed');
        }
    }

    public function getRequiredPermissions(): array
    {
        return ["content.{$this->type}"];
    }

    public function getAuditData(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data
        ];
    }
}
