<?php
namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private DatabaseManager $db;
    private AuditLogger $audit;

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            DB::beginTransaction();
            
            try {
                $validatedData = $this->validator->validate($data, $this->getRules());
                $content = $this->db->content()->create($validatedData);
                
                $this->audit->logCreation($content);
                $this->cache->invalidate(['content']);
                
                DB::commit();
                return $content;
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->audit->logFailure('content_creation', $e);
                throw $e;
            }
        });
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            DB::beginTransaction();
            
            try {
                $validatedData = $this->validator->validate($data, $this->getRules());
                $content = $this->db->content()->findOrFail($id);
                
                $this->audit->logUpdate($content, $validatedData);
                $content->update($validatedData);
                
                $this->cache->invalidate(['content', "content.$id"]);
                
                DB::commit();
                return $content;
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->audit->logFailure('content_update', $e);
                throw $e;
            }
        });
    }

    public function delete(int $id): void
    {
        $this->security->executeCriticalOperation(function() use ($id) {
            DB::beginTransaction();
            
            try {
                $content = $this->db->content()->findOrFail($id);
                $this->audit->logDeletion($content);
                
                $content->delete();
                $this->cache->invalidate(['content', "content.$id"]);
                
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->audit->logFailure('content_deletion', $e);
                throw $e;
            }
        });
    }

    private function getRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'published_at' => 'nullable|date'
        ];
    }
}

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private StorageManager $storage;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function upload(UploadedFile $file): Media
    {
        return $this->security->executeCriticalOperation(function() use ($file) {
            DB::beginTransaction();
            
            try {
                $this->validator->validateFile($file);
                $path = $this->storage->store($file, 'media', true);
                
                $media = $this->db->media()->create([
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'name' => $file->getClientOriginalName()
                ]);
                
                $this->audit->logUpload($media);
                DB::commit();
                return $media;
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->storage->delete($path ?? null);
                $this->audit->logFailure('media_upload', $e);
                throw $e;
            }
        });
    }

    public function delete(int $id): void
    {
        $this->security->executeCriticalOperation(function() use ($id) {
            DB::beginTransaction();
            
            try {
                $media = $this->db->media()->findOrFail($id);
                $this->storage->delete($media->path);
                
                $this->audit->logDeletion($media);
                $media->delete();
                
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->audit->logFailure('media_deletion', $e);
                throw $e;
            }
        });
    }
}

class CategoryManager implements CategoryManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function create(array $data): Category
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            DB::beginTransaction();
            
            try {
                $validatedData = $this->validator->validate($data, $this->getRules());
                $category = $this->db->categories()->create($validatedData);
                
                $this->audit->logCreation($category);
                $this->cache->invalidate(['categories']);
                
                DB::commit();
                return $category;
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->audit->logFailure('category_creation', $e);
                throw $e;
            }
        });
    }

    public function update(int $id, array $data): Category
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            DB::beginTransaction();
            
            try {
                $validatedData = $this->validator->validate($data, $this->getRules());
                $category = $this->db->categories()->findOrFail($id);
                
                $this->audit->logUpdate($category, $validatedData);
                $category->update($validatedData);
                
                $this->cache->invalidate(['categories', "category.$id"]);
                
                DB::commit();
                return $category;
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->audit->logFailure('category_update', $e);
                throw $e;
            }
        });
    }

    private function getRules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:categories,name',
            'slug' => 'required|string|max:255|unique:categories,slug',
            'parent_id' => 'nullable|exists:categories,id'
        ];
    }
}

class VersionManager implements VersionManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private DatabaseManager $db;
    private AuditLogger $audit;

    public function createVersion(Content $content): Version
    {
        return $this->security->executeCriticalOperation(function() use ($content) {
            DB::beginTransaction();
            
            try {
                $version = $this->db->versions()->create([
                    'content_id' => $content->id,
                    'data' => $content->toArray(),
                    'created_by' => auth()->id()
                ]);
                
                $this->audit->logVersionCreation($version);
                DB::commit();
                return $version;
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->audit->logFailure('version_creation', $e);
                throw $e;
            }
        });
    }

    public function restore(Version $version): Content
    {
        return $this->security->executeCriticalOperation(function() use ($version) {
            DB::beginTransaction();
            
            try {
                $content = $this->db->content()->findOrFail($version->content_id);
                $content->update($version->data);
                
                $this->audit->logVersionRestore($version, $content);
                $this->cache->invalidate(['content', "content.{$content->id}"]);
                
                DB::commit();
                return $content;
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->audit->logFailure('version_restore', $e);
                throw $e;
            }
        });
    }
}
