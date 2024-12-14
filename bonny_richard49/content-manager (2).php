<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\ContentManagerInterface;
use App\Core\Services\{CacheManager, ValidationService, SecurityManager};
use App\Core\Exceptions\ContentException;

class ContentManager implements ContentManagerInterface
{
    private CacheManager $cache;
    private ValidationService $validator;
    private SecurityManager $security;
    private array $config;

    public function __construct(
        CacheManager $cache,
        ValidationService $validator,
        SecurityManager $security,
        array $config
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->security = $security;
        $this->config = $config;
    }

    public function create(array $data): array
    {
        try {
            $this->security->validateOperation('content.create', $data);
            $validatedData = $this->validator->validate($data, $this->config['validation']['create']);

            return DB::transaction(function() use ($validatedData) {
                $content = $this->insertContent($validatedData);
                $this->handleRelations($content['id'], $validatedData);
                $this->invalidateCache($content['id']);
                
                return $content;
            });
        } catch (\Exception $e) {
            throw new ContentException('Content creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function update(int $id, array $data): array
    {
        try {
            $this->security->validateOperation('content.update', ['id' => $id, 'data' => $data]);
            $validatedData = $this->validator->validate($data, $this->config['validation']['update']);

            return DB::transaction(function() use ($id, $validatedData) {
                $this->validateContentExists($id);
                $this->createRevision($id);
                
                $content = $this->updateContent($id, $validatedData);
                $this->handleRelations($id, $validatedData);
                $this->invalidateCache($id);
                
                return $content;
            });
        } catch (\Exception $e) {
            throw new ContentException('Content update failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function get(int $id, array $options = []): array
    {
        try {
            $this->security->validateOperation('content.read', ['id' => $id]);
            
            return $this->cache->remember(
                $this->getCacheKey($id, $options),
                fn() => $this->fetchContent($id, $options)
            );
        } catch (\Exception $e) {
            throw new ContentException('Content retrieval failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        try {
            $this->security->validateOperation('content.delete', ['id' => $id]);

            return DB::transaction(function() use ($id) {
                $this->validateContentExists($id);
                $this->createRevision($id);
                
                $result = $this->softDeleteContent($id);
                $this->invalidateCache($id);
                
                return $result;
            });
        } catch (\Exception $e) {
            throw new ContentException('Content deletion failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function publish(int $id): bool
    {
        try {
            $this->security->validateOperation('content.publish', ['id' => $id]);

            return DB::transaction(function() use ($id) {
                $this->validateContentExists($id);
                $this->validatePublishable($id);
                
                $result = $this->publishContent($id);
                $this->invalidateCache($id);
                
                return $result;
            });
        } catch (\Exception $e) {
            throw new ContentException('Content publication failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function restore(int $id, int $version): array
    {
        try {
            $this->security->validateOperation('content.restore', ['id' => $id, 'version' => $version]);

            return DB::transaction(function() use ($id, $version) {
                $this->validateContentExists($id);
                $this->validateVersionExists($id, $version);
                
                $content = $this->restoreVersion($id, $version);
                $this->invalidateCache($id);
                
                return $content;
            });
        } catch (\Exception $e) {
            throw new ContentException('Content restoration failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function insertContent(array $data): array
    {
        $timestamp = time();
        
        $content = array_merge($data, [
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'status' => 'draft'
        ]);

        $id = DB::table($this->config['tables']['content'])->insertGetId($content);
        return array_merge($content, ['id' => $id]);
    }

    protected function updateContent(int $id, array $data): array
    {
        $content = array_merge($data, [
            'updated_at' => time()
        ]);

        DB::table($this->config['tables']['content'])
            ->where('id', $id)
            ->update($content);

        return array_merge($content, ['id' => $id]);
    }

    protected function fetchContent(int $id, array $options): array
    {
        $query = DB::table($this->config['tables']['content'])
            ->where('id', $id)
            ->where('deleted_at', null);

        if (!($options['include_draft'] ?? false)) {
            $query->where('status', 'published');
        }

        $content = $query->first();

        if (!$content) {
            throw new ContentException('Content not found');
        }

        if ($options['with_relations'] ?? false) {
            $content = $this->loadRelations($content);
        }

        return (array)$content;
    }

    protected function softDeleteContent(int $id): bool
    {
        return DB::table($this->config['tables']['content'])
            ->where('id', $id)
            ->update(['deleted_at' => time()]) > 0;
    }

    protected function publishContent(int $id): bool
    {
        return DB::table($this->config['tables']['content'])
            ->where('id', $id)
            ->update([
                'status' => 'published',
                'published_at' => time()
            ]) > 0;
    }

    protected function createRevision(int $id): void
    {
        $content = $this->fetchContent($id, ['include_draft' => true]);
        
        DB::table($this->config['tables']['revisions'])->insert([
            'content_id' => $id,
            'data' => json_encode($content),
            'created_at' => time()
        ]);
    }

    protected function restoreVersion(int $id, int $version): array
    {
        $revision = DB::table($this->config['tables']['revisions'])
            ->where('content_id', $id)
            ->where('id', $version)
            ->first();

        if (!$revision) {
            throw new ContentException('Version not found');
        }

        $data = json_decode($revision->data, true);
        return $this->update($id, $data);
    }

    protected function handleRelations(int $id, array $data): void
    {
        foreach ($this->config['relations'] as $relation => $config) {
            if (isset($data[$relation])) {
                $this->updateRelation($id, $relation, $data[$relation], $config);
            }
        }
    }

    protected function updateRelation(int $id, string $relation, array $values, array $config): void
    {
        DB::table($config['table'])
            ->where($config['foreign_key'], $id)
            ->delete();

        $records = array_map(
            fn($value) => [
                $config['foreign_key'] => $id,
                $config['value_key'] => $value,
                'created_at' => time()
            ],
            $values
        );

        if (!empty($records)) {
            DB::table($config['table'])->insert($records);
        }
    }

    protected function loadRelations(object $content): array
    {
        $result = (array)$content;

        foreach ($this->config['relations'] as $relation => $config) {
            $result[$relation] = DB::table($config['table'])
                ->where($config['foreign_key'], $content->id)
                ->pluck($config['value_key'])
                ->all();
        }

        return $result;
    }

    protected function validateContentExists(int $id): void
    {
        if (!$this->contentExists($id)) {
            throw new ContentException('Content not found');
        }
    }

    protected function validateVersionExists(int $id, int $version): void
    {
        $exists = DB::table($this->config['tables']['revisions'])
            ->where('content_id', $id)
            ->where('id', $version)
            ->exists();

        if (!$exists) {
            throw new ContentException('Version not found');
        }
    }

    protected function validatePublishable(int $id): void
    {
        $status = DB::table($this->config['tables']['content'])
            ->where('id', $id)
            ->value('status');

        if ($status === 'published') {
            throw new ContentException('Content already published');
        }
    }

    protected function contentExists(int $id): bool
    {
        return DB::table($this->config['tables']['content'])
            ->where('id', $id)
            ->where('deleted_at', null)
            ->exists();
    }

    protected function getCacheKey(int $id, array $options): string
    {
        $optionsHash = md5(json_encode($options));
        return "content:{$id}:{$optionsHash}";
    }

    protected function invalidateCache(int $id): void
    {
        $this->cache->tags(['content', "content:{$id}"])->flush();
    }
}
