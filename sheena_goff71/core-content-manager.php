<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Interfaces\{
    ContentManagerInterface,
    StorageInterface
};
use App\Core\Exceptions\{
    ContentException,
    ValidationException,
    SecurityException
};

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private StorageInterface $storage;
    private array $config;

    private const CACHE_PREFIX = 'content:';
    private const CACHE_TTL = 3600;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        StorageInterface $storage,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function create(array $data, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($data, $context) {
            $this->validateContentData($data);
            
            $content = $this->prepareContent($data);
            $storedContent = $this->storage->store($content);
            
            $this->invalidateCache($content['type']);
            $this->createAuditTrail('create', $storedContent, $context);
            
            return $storedContent;
        }, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($id, $data, $context) {
            $existingContent = $this->storage->find($id);
            if (!$existingContent) {
                throw new ContentException('Content not found');
            }
            
            $this->validateContentData($data);
            $this->validateVersionControl($existingContent, $data);
            
            $updatedContent = $this->prepareUpdate($existingContent, $data);
            $storedContent = $this->storage->update($id, $updatedContent);
            
            $this->invalidateCache($storedContent['type']);
            $this->createAuditTrail('update', $storedContent, $context);
            
            return $storedContent;
        }, $context);
    }

    public function delete(int $id, array $context): bool
    {
        return $this->security->executeSecureOperation(function() use ($id, $context) {
            $content = $this->storage->find($id);
            if (!$content) {
                throw new ContentException('Content not found');
            }
            
            $this->validateDeletion($content);
            $success = $this->storage->delete($id);
            
            if ($success) {
                $this->invalidateCache($content['type']);
                $this->createAuditTrail('delete', $content, $context);
            }
            
            return $success;
        }, $context);
    }

    public function publish(int $id, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($id, $context) {
            $content = $this->storage->find($id);
            if (!$content) {
                throw new ContentException('Content not found');
            }
            
            $this->validatePublishing($content);
            $content['status'] = 'published';
            $content['published_at'] = time();
            
            $publishedContent = $this->storage->update($id, $content);
            
            $this->invalidateCache($content['type']);
            $this->createAuditTrail('publish', $publishedContent, $context);
            
            return $publishedContent;
        }, $context);
    }

    protected function validateContentData(array $data): void
    {
        $rules = $this->config['validation_rules'][$data['type']] ?? null;
        if (!$rules) {
            throw new ValidationException('Invalid content type');
        }

        if (!$this->validator->validateInput($data)) {
            throw new ValidationException('Invalid content data');
        }

        $this->validateContentSecurity($data);
    }

    protected function validateContentSecurity(array $data): void
    {
        if ($this->containsMaliciousContent($data)) {
            throw new SecurityException('Malicious content detected');
        }

        if ($this->exceedsStorageLimit($data)) {
            throw new ValidationException('Content exceeds storage limit');
        }
    }

    protected function validateVersionControl(array $existing, array $new): void
    {
        if ($existing['version'] !== ($new['version'] ?? null)) {
            throw new ContentException('Version mismatch');
        }
    }

    protected function validateDeletion(array $content): void
    {
        if ($content['status'] === 'published' && !$this->config['allow_published_deletion']) {
            throw new ContentException('Cannot delete published content');
        }
    }

    protected function validatePublishing(array $content): void
    {
        if ($content['status'] === 'published') {
            throw new ContentException('Content already published');
        }

        if (!$this->isReadyForPublishing($content)) {
            throw new ContentException('Content not ready for publishing');
        }
    }

    protected function prepareContent(array $data): array
    {
        return array_merge($data, [
            'version' => 1,
            'status' => 'draft',
            'created_at' => time(),
            'updated_at' => time(),
            'hash' => $this->generateContentHash($data)
        ]);
    }

    protected function prepareUpdate(array $existing, array $data): array
    {
        return array_merge($existing, $data, [
            'version' => $existing['version'] + 1,
            'updated_at' => time(),
            'hash' => $this->generateContentHash($data)
        ]);
    }

    protected function containsMaliciousContent(array $data): bool
    {
        $content = json_encode($data);
        $patterns = $this->config['malicious_patterns'] ?? [];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }

    protected function exceedsStorageLimit(array $data): bool
    {
        $size = strlen(json_encode($data));
        return $size > ($this->config['max_content_size'] ?? PHP_INT_MAX);
    }

    protected function isReadyForPublishing(array $content): bool
    {
        $requiredFields = $this->config['publishing_requirements'][$content['type']] ?? [];
        foreach ($requiredFields as $field) {
            if (empty($content[$field])) {
                return false;
            }
        }
        return true;
    }

    protected function generateContentHash(array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode($data),
            $this->config['app_key']
        );
    }

    protected function invalidateCache(string $type): void
    {
        $key = self::CACHE_PREFIX . $type;
        Cache::forget($key);
    }

    protected function createAuditTrail(string $action, array $content, array $context): void
    {
        Log::info('Content ' . $action, [
            'content_id' => $content['id'] ?? null,
            'type' => $content['type'],
            'user_id' => $context['user_id'] ?? null,
            'timestamp' => time(),
            'ip' => $context['ip'] ?? null
        ]);
    }
}
