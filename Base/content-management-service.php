<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentManagementService
{
    protected $validationService;
    protected $sanitizationService;
    protected $mediaService;

    public function __construct(
        ValidationService $validationService,
        ContentSanitizationService $sanitizationService,
        MediaProcessingService $mediaService
    ) {
        $this->validationService = $validationService;
        $this->sanitizationService = $sanitizationService;
        $this->mediaService = $mediaService;
    }

    public function createContent(array $data): Content
    {
        // Validate input data
        $validatedData = $this->validationService->validateContent($data, 'create');

        // Start database transaction
        return DB::transaction(function () use ($validatedData, $data) {
            // Sanitize content
            $validatedData['content'] = $this->sanitizationService->sanitize($validatedData['content']);
            
            // Generate excerpt if not provided
            if (empty($validatedData['excerpt'])) {
                $validatedData['excerpt'] = $this->sanitizationService->sanitizeExcerpt($validatedData['content']);
            }

            // Process featured image if provided
            if (isset($data['featured_image'])) {
                $imagePaths = $this->mediaService->processUploadedImage($data['featured_image'], 'content/featured');
                $validatedData['featured_image'] = json_encode($imagePaths);
            }

            // Process attachments if provided
            if (isset($data['attachments'])) {
                $attachmentPaths = [];
                foreach ($data['attachments'] as $attachment) {
                    // Store attachment in appropriate directory
                    $path = $attachment->store('content/attachments', 'public');
                    $attachmentPaths[] = [
                        'path' => $path,
                        'filename' => $attachment->getClientOriginalName(),
                        'mime_type' => $attachment->getMimeType(),
                        'size' => $attachment->getSize()
                    ];
                }
                $validatedData['attachments'] = json_encode($attachmentPaths);
            }

            // Create content
            $content = Content::create($validatedData);

            // Sync tags if provided
            if (isset($validatedData['tags'])) {
                $content->tags()->sync($validatedData['tags']);
            }

            return $content;
        });
    }

    public function updateContent(Content $content, array $data): Content
    {
        // Validate input data
        $validatedData = $this->validationService->validateContent($data, 'update');

        // Start database transaction
        return DB::transaction(function () use ($content, $validatedData, $data) {
            // Sanitize content if provided
            if (isset($validatedData['content'])) {
                $validatedData['content'] = $this->sanitizationService->sanitize($validatedData['content']);
                
                // Update excerpt if content changed and no explicit excerpt provided
                if (empty($validatedData['excerpt'])) {
                    $validatedData['excerpt'] = $this->sanitizationService->sanitizeExcerpt($validatedData['content']);
                }
            }

            // Process new featured image if provided
            if (isset($data['featured_image'])) {
                // Clean up old featured image
                if ($content->featured_image) {
                    $this->mediaService->cleanup(json_decode($content->featured_image, true));
                }
                
                $imagePaths = $this->mediaService->processUploadedImage($data['featured_image'], 'content/featured');
                $validatedData['featured_image'] = json_encode($imagePaths);
            }

            // Process new attachments if provided
            if (isset($data['attachments'])) {
                $currentAttachments = $content->attachments ? json_decode($content->attachments, true) : [];
                $newAttachments = [];
                
                foreach ($data['attachments'] as $attachment) {
                    $path = $attachment->store('content/attachments', 'public');
                    $newAttachments[] = [
                        'path' => $path,
                        'filename' => $attachment->getClientOriginalName(),
                        'mime_type' => $attachment->getMimeType(),
                        'size' => $attachment->getSize()
                    ];
                }
                
                $validatedData['attachments'] = json_encode(array_merge($currentAttachments, $newAttachments));
            }

            // Update content
            $content->update($validatedData);

            // Sync tags if provided
            if (isset($validatedData['tags'])) {
                $content->tags()->sync($validatedData['tags']);
            }

            return $content->fresh();
        });
    }

    public function deleteContent(Content $content): bool
    {
        return DB::transaction(function () use ($content) {
            // Clean up media files
            if ($content->featured_image) {
                $this->mediaService->cleanup(json_decode($content->featured_image, true));
            }

            if ($content->attachments) {
                foreach (json_decode($content->attachments, true) as $attachment) {
                    Storage::delete('public/' . $attachment['path']);
                }
            }

            // Delete content and related data
            $content->tags()->detach();
            return $content->delete();
        });
    }

    public function getContentList(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Content::query()
            ->with(['category', 'tags'])
            ->when(isset($filters['status']), function ($query) use ($filters) {
                return $query->where('status', $filters['status']);
            })
            ->when(isset($filters['category_id']), function ($query) use ($filters) {
                return $query->where('category_id', $filters['category_id']);
            })
            ->when(isset($filters['tag']), function ($query) use ($filters) {
                return $query->whereHas('tags', function ($query) use ($filters) {
                    $query->where('name', $filters['tag']);
                });
            })
            ->when(isset($filters['search']), function ($query) use ($filters) {
                return $query->where(function ($query) use ($filters) {
                    $query->where('title', 'like', "%{$filters['search']}%")
                          ->orWhere('content', 'like', "%{$filters['search']}%");
                });
            })
            ->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    public function publishContent(Content $content): Content
    {
        return DB::transaction(function () use ($content) {
            $content->update([
                'status' => 'published',
                'published_at' => now()
            ]);

            return $content->fresh();
        });
    }
}
