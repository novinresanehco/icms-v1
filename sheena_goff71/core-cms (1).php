<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Services\{CacheService, ValidationService};
use Illuminate\Support\Facades\DB;

class ContentManager
{
    private SecurityManager $security;
    private CacheService $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        CacheService $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function createContent(array $data, $user): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processContentCreation($data),
            ['user' => $user, 'permission' => 'content.create']
        );
    }

    public function updateContent(int $id, array $data, $user): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processContentUpdate($id, $data),
            ['user' => $user, 'permission' => 'content.update']
        );
    }

    public function deleteContent(int $id, $user): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processContentDeletion($id),
            ['user' => $user, 'permission' => 'content.delete']
        );
    }

    public function getContent(int $id, $user = null): ?Content
    {
        return $this->cache->remember("content.$id", function() use ($id, $user) {
            return $this->security->executeSecureOperation(
                fn() => $this->retrieveContent($id),
                ['user' => $user, 'permission' => 'content.view']
            );
        });
    }

    private function processContentCreation(array $data): Content
    {
        $validatedData = $this->validator->validate($data, [
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'status' => 'required|in:draft,published',
            'category_id' => 'required|exists:categories,id'
        ]);

        $content = DB::table('contents')->insert([
            'title' => $validatedData['title'],
            'body' => $validatedData['body'],
            'status' => $validatedData['status'],
            'category_id' => $validatedData['category_id'],
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return $this->retrieveContent($content->id);
    }

    private function processContentUpdate(int $id, array $data): Content
    {
        $validatedData = $this->validator->validate($data, [
            'title' => 'string|max:200',
            'body' => 'string',
            'status' => 'in:draft,published',
            'category_id' => 'exists:categories,id'
        ]);

        DB::table('contents')
            ->where('id', $id)
            ->update(array_merge($validatedData, [
                'updated_at' => now()
            ]));

        $this->cache->forget("content.$id");
        return $this->retrieveContent($id);
    }

    private function processContentDeletion(int $id): bool
    {
        $deleted = DB::table('contents')->delete($id);
        if ($deleted) {
            $this->cache->forget("content.$id");
        }
        return $deleted;
    }

    private function retrieveContent(int $id): ?Content
    {
        $data = DB::table('contents')->find($id);
        return $data ? new Content($data) : null;
    }

    public function getContentList(array $filters = [], $user = null): array
    {
        return $this->security->executeSecureOperation(
            fn() => $this->retrieveContentList($filters),
            ['user' => $user, 'permission' => 'content.list']
        );
    }

    private function retrieveContentList(array $filters): array
    {
        $query = DB::table('contents');

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['search'])) {
            $query->where('title', 'like', "%{$filters['search']}%");
        }

        return $query->orderBy('created_at', 'desc')
                    ->limit($filters['limit'] ?? 50)
                    ->get()
                    ->map(fn($data) => new Content($data))
                    ->toArray();
    }
}

class Content
{
    public int $id;
    public string $title;
    public string $body;
    public string $status;
    public int $category_id;
    public string $created_at;
    public string $updated_at;

    public function __construct(object $data)
    {
        $this->id = $data->id;
        $this->title = $data->title;
        $this->body = $data->body;
        $this->status = $data->status;
        $this->category_id = $data->category_id;
        $this->created_at = $data->created_at;
        $this->updated_at = $data->updated_at;
    }
}
