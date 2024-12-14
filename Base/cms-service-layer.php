<?php

namespace App\Services;

use App\Repositories\ContentRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\TagRepository;
use App\Repositories\MediaRepository;
use App\Events\ContentPublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ContentService
{
    protected $contentRepo;
    protected $categoryRepo;
    protected $tagRepo;
    protected $mediaRepo;

    public function __construct(
        ContentRepository $contentRepo,
        CategoryRepository $categoryRepo,
        TagRepository $tagRepo,
        MediaRepository $mediaRepo
    ) {
        $this->contentRepo = $contentRepo;
        $this->categoryRepo = $categoryRepo;
        $this->tagRepo = $tagRepo;
        $this->mediaRepo = $mediaRepo;
    }

    public function createContent(array $data)
    {
        return DB::transaction(function () use ($data) {
            // Create main content
            $content = $this->contentRepo->create([
                'title' => $data['title'],
                'slug' => \Str::slug($data['title']),
                'content' => $data['content'],
                'excerpt' => $data['excerpt'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'category_id' => $data['category_id'],
                'user_id' => auth()->id(),
                'published_at' => $data['status'] === 'published' ? now() : null
            ]);

            // Handle tags
            if (!empty($data['tags'])) {
                $tags = $this->tagRepo->findOrCreateMultiple($data['tags']);
                $content->tags()->sync(collect($tags)->pluck('id'));
            }

            // Handle media
            if (!empty($data['media'])) {
                foreach ($data['media'] as $file) {
                    $media = $this->mediaRepo->upload($file, [
                        'title' => $content->title,
                        'user_id' => auth()->id()
                    ]);
                    $content->media()->attach($media->id);
                }
            }

            if ($content->status === 'published') {
                event(new ContentPublished($content));
            }

            return $content;
        });
    }

    public function updateContent($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $content = $this->contentRepo->find($id);

            $updateData = [
                'title' => $data['title'],
                'content' => $data['content'],
                'excerpt' => $data['excerpt'] ?? $content->excerpt,
                'category_id' => $data['category_id'],
                'updated_at' => now()
            ];

            if (isset($data['status']) && $data['status'] !== $content->status) {
                $updateData['status'] = $data['status'];
                $updateData['published_at'] = $data['status'] === 'published' ? now() : null;
            }

            $content = $this->contentRepo->update($updateData, $id);

            if (isset($data['tags'])) {
                $tags = $this->tagRepo->findOrCreateMultiple($data['tags']);
                $content->tags()->sync(collect($tags)->pluck('id'));
            }

            if (!empty($data['media'])) {
                foreach ($data['media'] as $file) {
                    $media = $this->mediaRepo->upload($file, [
                        'title' => $content->title,
                        'user_id' => auth()->id()
                    ]);
                    $content->media()->attach($media->id);
                }
            }

            return $content;
        });
    }

    public function deleteContent($id)
    {
        return DB::transaction(function () use ($id) {
            $content = $this->contentRepo->find($id);
            
            // Delete associated media files
            foreach ($content->media as $media) {
                $this->mediaRepo->delete($media->id);
            }
            
            // Remove tags association
            $content->tags()->detach();
            
            // Delete the content
            return $this->contentRepo->delete($id);
        });
    }

    public function publishContent($id)
    {
        $content = $this->contentRepo->update([
            'status' => 'published',
            'published_at' => now()
        ], $id);

        event(new ContentPublished($content));
        return $content;
    }

    public function unpublishContent($id)
    {
        return $this->contentRepo->update([
            'status' => 'draft',
            'published_at' => null
        ], $id);
    }

    public function getRelatedContent($contentId, $limit = 5)
    {
        $content = $this->contentRepo->find($contentId);
        $tagIds = $content->tags->pluck('id');

        return $this->contentRepo->findWhere([
            ['id', '!=', $contentId],
            ['status', '=', 'published'],
            ['category_id', '=', $content->category_id]
        ])
        ->whereHas('tags', function ($query) use ($tagIds) {
            $query->whereIn('id', $tagIds);
        })
        ->limit($limit)
        ->get();
    }

    public function searchContent($term, array $filters = [])
    {
        $query = $this->contentRepo->newQuery()
            ->where(function ($q) use ($term) {
                $q->where('title', 'LIKE', "%{$term}%")
                  ->orWhere('content', 'LIKE', "%{$term}%");
            })
            ->where('status', 'published');

        if (!empty($filters['category'])) {
            $query->where('category_id', $filters['category']);
        }

        if (!empty($filters['tags'])) {
            $query->whereHas('tags', function ($q) use ($filters) {
                $q->whereIn('id', (array) $filters['tags']);
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where('published_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('published_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('published_at', 'desc')
                    ->paginate($filters['per_page'] ?? 15);
    }
}
