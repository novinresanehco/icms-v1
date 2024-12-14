<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache, Log};
use Illuminate\Support\Str;

class ContentManager
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected ContentRepository $repository;
    protected AuditLogger $audit;

    public function store(array $data): ContentResult
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->storeContent($data),
            ['action' => 'store', 'data' => $data]
        );
    }

    protected function storeContent(array $data): ContentResult
    {
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'meta' => 'array'
        ]);

        return DB::transaction(function() use ($validated) {
            $content = $this->repository->create($validated);
            $this->audit->log('content.created', $content->id);
            Cache::tags('content')->flush();
            return new ContentResult($content);
        });
    }
}

class SecurityManager
{
    private EncryptionService $encryption;
    private AuditLogger $audit;

    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            $this->validateAccess($context);
            $result = $operation();
            $this->validateResult($result);
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validateAccess(array $context): void
    {
        if (!$this->checkPermissions($context['action'])) {
            throw new UnauthorizedException();
        }
    }
}

class ContentRepository 
{
    protected CacheManager $cache;
    protected ValidationService $validator;

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = Content::create([
                'uuid' => (string) Str::uuid(),
                'title' => $data['title'],
                'content' => $this->encryption->encrypt($data['content']),
                'status' => $data['status'],
                'meta' => $data['meta'] ?? [],
                'created_by' => auth()->id()
            ]);

            $this->cache->tags(['content'])->flush();
            return $content;
        });
    }

    public function find(string $uuid): ?Content
    {
        return $this->cache->tags(['content'])->remember(
            "content:{$uuid}",
            3600,
            fn() => Content::whereUuid($uuid)->first()
        );
    }
}

class ValidationService
{
    public function validate(array $data, array $rules): array
    {
        $validator = validator($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    protected function validateSecurity(array $data): void
    {
        if (!$this->xssCheck($data) || !$this->sqlCheck($data)) {
            throw new SecurityException('Malicious content detected');
        }
    }
}

class AuditLogger
{
    public function log(string $action, mixed $target): void
    {
        Log::info('CMS Audit', [
            'action' => $action,
            'target' => $target,
            'user' => auth()->id(),
            'ip' => request()->ip(),
            'timestamp' => now()
        ]);
    }
}

class EncryptionService
{
    public function encrypt(string $data): string
    {
        return openssl_encrypt(
            $data,
            'AES-256-CBC',
            config('app.key'),
            0,
            config('app.cipher')
        );
    }

    public function decrypt(string $encrypted): string
    {
        return openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            config('app.key'),
            0,
            config('app.cipher')
        );
    }
}

class ContentResult
{
    public function __construct(
        public Content $content,
        public array $meta = []
    ) {}
}
