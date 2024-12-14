<?php

namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\{SecurityManager, AccessControl};
use App\Core\Content\{ContentManager, MediaManager};
use App\Core\Template\TemplateEngine;
use App\Exceptions\{SecurityException, ValidationException};

class CoreCMS
{
    private SecurityManager $security;
    private ContentManager $content;
    private MediaManager $media;
    private TemplateEngine $template;
    
    public function __construct(
        SecurityManager $security,
        ContentManager $content,
        MediaManager $media,
        TemplateEngine $template
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->media = $media;
        $this->template = $template;
    }

    public function handleRequest(Request $request): Response
    {
        try {
            DB::beginTransaction();

            // Security validation
            $context = $this->security->validateRequest($request);
            
            // Process request
            $result = match($request->type) {
                'content' => $this->content->process($request, $context),
                'media' => $this->media->process($request, $context),
                'template' => $this->template->process($request, $context),
                default => throw new ValidationException('Invalid request type')
            };

            // Cache if applicable
            if ($result->isCacheable()) {
                Cache::put(
                    $this->getCacheKey($request),
                    $result,
                    config('cms.cache_ttl')
                );
            }

            DB::commit();
            return new Response($result);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::critical('CMS operation failed', [
                'exception' => $e->getMessage(),
                'request' => $request->toArray(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getContent(string $id, array $options = []): Content
    {
        $cacheKey = "content.{$id}" . md5(serialize($options));

        return Cache::remember($cacheKey, config('cms.cache_ttl'), function() use ($id, $options) {
            return $this->content->get($id, $options);
        });
    }

    public function storeContent(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->content->store($data);
            
            if (isset($data['media'])) {
                $this->media->attachToContent($content->id, $data['media']);
            }

            Cache::tags(['content'])->flush();
            return $content;
        });
    }

    public function updateContent(string $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->content->update($id, $data);
            
            if (isset($data['media'])) {
                $this->media->syncWithContent($content->id, $data['media']);
            }

            Cache::tags(['content'])->flush();
            return $content;
        });
    }

    private function getCacheKey(Request $request): string
    {
        return sprintf(
            '%s.%s.%s',
            $request->type,
            $request->action,
            md5(serialize($request->data))
        );
    }
}

namespace App\Core\Security;

class SecurityManager
{
    private AccessControl $access;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function validateRequest(Request $request): SecurityContext
    {
        // Validate input
        $this->validator->validate($request);

        // Check authentication
        $user = $this->access->authenticate($request);
        
        // Verify permissions
        if (!$this->access->authorize($user, $request->getRequiredPermissions())) {
            throw new SecurityException('Unauthorized access attempt');
        }

        // Log access attempt
        $this->logger->logAccess($user, $request);

        return new SecurityContext($user);
    }
}

namespace App\Core\Content;

class ContentManager
{
    private Repository $repository;
    private ValidationService $validator;
    private VersionManager $versions;

    public function store(array $data): Content
    {
        $validated = $this->validator->validate($data);
        
        $content = $this->repository->create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'meta' => $validated['meta'] ?? [],
            'status' => ContentStatus::DRAFT,
            'version' => 1
        ]);

        $this->versions->create($content);
        return $content;
    }

    public function update(string $id, array $data): Content
    {
        $content = $this->repository->findOrFail($id);
        $validated = $this->validator->validate($data);
        
        $content->fill([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'meta' => array_merge($content->meta, $validated['meta'] ?? [])
        ]);

        if ($content->isDirty()) {
            $content->version++;
            $content->save();
            $this->versions->create($content);
        }

        return $content;
    }
}

namespace App\Core\Template;

class TemplateEngine
{
    private TemplateLoader $loader;
    private TemplateCompiler $compiler;
    private CacheManager $cache;

    public function render(string $template, array $data = []): string
    {
        $cacheKey = $this->getCacheKey($template, $data);

        return $this->cache->remember($cacheKey, config('cms.template_cache_ttl'), function() use ($template, $data) {
            $source = $this->loader->load($template);
            $compiled = $this->compiler->compile($source);
            return $compiled->render($data);
        });
    }

    private function getCacheKey(string $template, array $data): string
    {
        return sprintf(
            'template.%s.%s',
            $template,
            md5(serialize($data))
        );
    }
}
