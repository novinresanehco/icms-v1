<?php

namespace App\Core\Cms;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Exceptions\{CmsException, SecurityException};

class ContentManager 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function createContent(array $data): mixed 
    {
        return $this->executeSecureOperation(function() use ($data) {
            // Pre-validation
            $validatedData = $this->validator->validateContent($data);
            
            DB::beginTransaction();
            
            try {
                // Create content with security checks
                $content = Content::create($validatedData);
                
                // Process relationships
                $this->processRelationships($content, $validatedData);
                
                // Handle media
                if (!empty($validatedData['media'])) {
                    $this->processMedia($content, $validatedData['media']);
                }

                DB::commit();
                
                // Cache invalidation
                $this->invalidateContentCache();
                
                return $content;
            } catch (\Throwable $e) {
                DB::rollBack();
                throw new CmsException('Content creation failed', 0, $e);
            }
        });
    }

    public function updateContent(int $id, array $data): mixed
    {
        return $this->executeSecureOperation(function() use ($id, $data) {
            $validatedData = $this->validator->validateContent($data);
            
            DB::beginTransaction();
            
            try {
                $content = Content::findOrFail($id);
                
                // Update with security verification
                $content->update($validatedData);
                
                // Update relationships
                $this->processRelationships($content, $validatedData);
                
                // Update media
                if (isset($validatedData['media'])) {
                    $this->processMedia($content, $validatedData['media']);
                }

                DB::commit();
                
                // Cache invalidation
                $this->invalidateContentCache($id);
                
                return $content;
            } catch (\Throwable $e) {
                DB::rollBack();
                throw new CmsException('Content update failed', 0, $e);
            }
        });
    }

    private function executeSecureOperation(callable $operation): mixed 
    {
        $operationId = uniqid('cms_op_');
        
        try {
            // Start monitoring
            $this->startOperationMonitoring($operationId);
            
            // Execute with security
            $result = $this->security->executeSecure($operation);
            
            // Record success
            $this->recordOperationSuccess($operationId);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Record failure
            $this->recordOperationFailure($operationId, $e);
            throw $e;
        }
    }

    private function processRelationships(Content $content, array $data): void
    {
        if (!empty($data['categories'])) {
            $this->validator->validateCategories($data['categories']);
            $content->categories()->sync($data['categories']);
        }

        if (!empty($data['tags'])) {
            $this->validator->validateTags($data['tags']);
            $content->tags()->sync($data['tags']);
        }
    }

    private function processMedia(Content $content, array $media): void
    {
        $this->validator->validateMedia($media);
        
        foreach ($media as $item) {
            if (!$this->security->validateMediaAccess($item['id'])) {
                throw new SecurityException('Invalid media access');
            }
        }
        
        $content->media()->sync(array_column($media, 'id'));
    }

    private function invalidateContentCache(int $id = null): void 
    {
        if ($id) {
            Cache::forget("content_{$id}");
        } else {
            Cache::tags(['content'])->flush();
        }
    }

    private function startOperationMonitoring(string $opId): void
    {
        Log::info('Starting CMS operation', [
            'operation_id' => $opId,
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true)
        ]);
    }

    private function recordOperationSuccess(string $opId): void
    {
        Log::info('CMS operation completed', [
            'operation_id' => $opId,
            'duration' => microtime(true) - LARAVEL_START,
            'peak_memory' => memory_get_peak_usage(true)
        ]);
    }

    private function recordOperationFailure(string $opId, \Throwable $e): void
    {
        Log::error('CMS operation failed', [
            'operation_id' => $opId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'duration' => microtime(true) - LARAVEL_START
        ]);
    }
}

class ValidationService
{
    private array $rules;

    public function validateContent(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'categories' => 'array',
            'tags' => 'array',
            'media' => 'array'
        ];

        return $this->validate($data, $rules);
    }

    public function validateCategories(array $categories): void
    {
        foreach ($categories as $id) {
            if (!Category::find($id)) {
                throw new ValidationException("Invalid category: {$id}");
            }
        }
    }

    public function validateTags(array $tags): void
    {
        foreach ($tags as $id) {
            if (!Tag::find($id)) {
                throw new ValidationException("Invalid tag: {$id}");
            }
        }
    }

    public function validateMedia(array $media): void
    {
        foreach ($media as $item) {
            if (!isset($item['id']) || !Media::find($item['id'])) {
                throw new ValidationException("Invalid media item");
            }
        }
    }

    private function validate(array $data, array $rules): array
    {
        $validator = validator($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException(
                'Validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validated();
    }
}

class SecurityManager
{
    public function executeSecure(callable $operation): mixed
    {
        if (!$this->verifyEnvironment()) {
            throw new SecurityException('Insecure environment detected');
        }

        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->handleSecurityFailure($e);
            throw $e;
        }
    }

    public function validateMediaAccess(int $mediaId): bool
    {
        // Implement media access validation
        return true;
    }

    private function verifyEnvironment(): bool
    {
        // Implement environment security checks
        return true;
    }

    private function handleSecurityFailure(\Throwable $e): void
    {
        Log::error('Security failure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
