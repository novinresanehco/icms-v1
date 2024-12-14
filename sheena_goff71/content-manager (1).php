<?php

namespace App\Core\CMS;

use App\Core\Security\Services\SecurityManager;
use App\Core\CMS\Models\{Content, ContentVersion, ContentMeta};
use App\Core\CMS\Events\{ContentCreated, ContentUpdated, ContentDeleted};
use App\Core\Exceptions\{ContentException, ValidationException};
use Illuminate\Support\Facades\{DB, Cache, Event};

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ContentValidator $validator;
    private ContentCache $cache;
    private MetaManager $metaManager;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        ContentValidator $validator,
        ContentCache $cache,
        MetaManager $metaManager
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->metaManager = $metaManager;
    }

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeSecureOperation($context, function() use ($data, $context) {
            DB::beginTransaction();
            try {
                $this->validator->validateCreate($data);
                
                $content = $this->repository->create([
                    'title' => $data['title'],
                    'slug' => $this->generateSlug($data['title']),
                    'content' => $data['content'],
                    'status' => $data['status'] ?? 'draft',
                    'author_id' => $context->getUserId(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                $this->createVersion($content);
                $this->processMeta($content, $data['meta'] ?? []);
                
                Event::dispatch(new ContentCreated($content, $context));
                
                DB::commit();
                $this->cache->invalidateContent($content->id);
                
                return $content;

            } catch (\Throwable $e) {
                DB::rollBack();
                throw new ContentException('Content creation failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeSecureOperation($context, function() use ($id, $data, $context) {
            DB::beginTransaction();
            try {
                $content = $this->repository->findOrFail($id);
                $this->validator->validateUpdate($data, $content);
                
                $content->fill([
                    'title' => $data['title'] ?? $content->title,
                    'content' => $data['content'] ?? $content->content,
                    'status' => $data['status'] ?? $content->status,
                    'updated_at' => now()
                ]);

                $content->save();
                $this->createVersion($content);
                $this->updateMeta($content, $data['meta'] ?? []);
                
                Event::dispatch(new ContentUpdated($content, $context));
                
                DB::commit();
                $this->cache->invalidateContent($id);
                
                return $content;

            } catch (\Throwable $e) {
                DB::rollBack();
                throw new ContentException('Content update failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeSecureOperation($context, function() use ($id, $context) {
            DB::beginTransaction();
            try {
                $content = $this->repository->findOrFail($id);
                
                $this->repository->delete($id);
                $this->metaManager->deleteAllMeta($id);
                $this->cache->invalidateContent($id);
                
                Event::dispatch(new ContentDeleted($content, $context));
                
                DB::commit();
                return true;

            } catch (\Throwable $e) {
                DB::rollBack();
                throw new ContentException('Content deletion failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    public function publish(int $id, SecurityContext $context): Content
    {
        return $this->security->executeSecureOperation($context, function() use ($id, $context) {
            DB::beginTransaction();
            try {
                $content = $this->repository->findOrFail($id);
                
                if ($content->status === 'published') {
                    throw new ContentException('Content is already published');
                }

                $content->status = 'published';
                $content->published_at = now();
                $content->save();
                
                $this->createVersion($content);
                $this->cache->invalidateContent($id);
                
                Event::dispatch(new ContentPublished($content, $context));
                
                DB::commit();
                return $content;

            } catch (\Throwable $e) {
                DB::rollBack();
                throw new ContentException('Content publication failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    private function createVersion(Content $content): ContentVersion
    {
        return ContentVersion::create([
            'content_id' => $content->id,
            'title' => $content->title,
            'content' => $content->content,
            'status' => $content->status,
            'version' => $this->getNextVersion($content->id),
            'created_at' => now()
        ]);
    }

    private function getNextVersion(int $contentId): int
    {
        return ContentVersion::where('content_id', $contentId)->max('version') + 1;
    }

    private function generateSlug(string $title): string
    {
        $slug = str_slug($title);
        $count = 1;

        while ($this->repository->slugExists($slug)) {
            $slug = str_slug($title) . '-' . $count++;
        }

        return $slug;
    }

    private function processMeta(Content $content, array $meta): void
    {
        foreach ($meta as $key => $value) {
            $this->metaManager->setMeta($content->id, $key, $value);
        }
    }

    private function updateMeta(Content $content, array $meta): void
    {
        $this->metaManager->deleteAllMeta($content->id);
        $this->processMeta($content, $meta);
    }
}
