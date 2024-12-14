namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class ContentManager
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ContentRepository $repository;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ContentRepository $repository
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->repository = $repository;
    }

    public function create(array $data): Content
    {
        return DB::transaction(function () use ($data) {
            $this->security->validateContentCreation($data);
            $content = $this->repository->create($data);
            $this->cache->invalidateContent($content->id);
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function () use ($id, $data) {
            $content = $this->repository->findOrFail($id);
            $this->security->validateContentUpdate($content);
            $updated = $this->repository->update($id, $data);
            $this->cache->invalidateContent($id);
            return $updated;
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $content = $this->repository->findOrFail($id);
            $this->security->validateContentDeletion($content);
            $this->repository->delete($id);
            $this->cache->invalidateContent($id);
        });
    }

    public function publish(int $id): void
    {
        DB::transaction(function () use ($id) {
            $content = $this->repository->findOrFail($id);
            $this->security->validateContentPublication($content);
            $this->repository->publish($id);
            $this->cache->invalidateContent($id);
        });
    }
}

class ContentRepository
{
    protected $model;

    public function create(array $data): Content
    {
        return $this->model->create($this->prepareData($data));
    }

    public function update(int $id, array $data): Content
    {
        $content = $this->findOrFail($id);
        $content->update($this->prepareData($data));
        return $content->fresh();
    }

    public function delete(int $id): void
    {
        $this->findOrFail($id)->delete();
    }

    public function publish(int $id): void
    {
        $this->findOrFail($id)->update(['published_at' => now()]);
    }

    public function findOrFail(int $id): Content
    {
        $content = $this->model->find($id);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }
        return $content;
    }

    protected function prepareData(array $data): array
    {
        return array_merge($data, [
            'updated_at' => now(),
            'updated_by' => auth()->id()
        ]);
    }
}

class MediaManager
{
    protected SecurityManager $security;
    protected MediaRepository $repository;
    protected FileSystem $storage;

    public function __construct(
        SecurityManager $security,
        MediaRepository $repository,
        FileSystem $storage
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->storage = $storage;
    }

    public function upload(UploadedFile $file): Media
    {
        return DB::transaction(function () use ($file) {
            $this->security->validateMediaUpload($file);
            $path = $this->storage->store($file);
            return $this->repository->create([
                'path' => $path,
                'type' => $file->getMimeType(),
                'size' => $file->getSize()
            ]);
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $media = $this->repository->findOrFail($id);
            $this->security->validateMediaDeletion($media);
            $this->storage->delete($media->path);
            $this->repository->delete($id);
        });
    }
}

class CategoryManager
{
    protected SecurityManager $security;
    protected CategoryRepository $repository;
    protected CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        CategoryRepository $repository,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
    }

    public function create(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            $this->security->validateCategoryCreation($data);
            $category = $this->repository->create($data);
            $this->cache->invalidateCategories();
            return $category;
        });
    }

    public function update(int $id, array $data): Category
    {
        return DB::transaction(function () use ($id, $data) {
            $category = $this->repository->findOrFail($id);
            $this->security->validateCategoryUpdate($category);
            $updated = $this->repository->update($id, $data);
            $this->cache->invalidateCategories();
            return $updated;
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $category = $this->repository->findOrFail($id);
            $this->security->validateCategoryDeletion($category);
            $this->repository->delete($id);
            $this->cache->invalidateCategories();
        });
    }
}

class FileSystem
{
    public function store(UploadedFile $file): string
    {
        return $file->store('media', 'public');
    }

    public function delete(string $path): void
    {
        Storage::disk('public')->delete($path);
    }
}

class ContentNotFoundException extends \Exception {}
