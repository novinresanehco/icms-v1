<?php

namespace App\Core\Content;

use App\Core\Security\SecurityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ContentRepository extends BaseRepository
{
    protected $model = Content::class;
    protected array $searchable = ['title', 'content'];

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->model::create($data);
            
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }
            
            return $content;
        });
    }

    public function update(Content $content, array $data): Content
    {
        return DB::transaction(function() use ($content, $data) {
            $content->update($data);
            
            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }
            
            return $content->fresh();
        });
    }

    public function findByType(string $type): Collection
    {
        return $this->model::where('type', $type)
            ->whereNull('deleted_at')
            ->get();
    }

    public function findByStatus(string $status): Collection
    {
        return $this->model::where('status', $status)
            ->whereNull('deleted_at')
            ->get();
    }

    public function search(string $query, array $options = []): Collection
    {
        return $this->model::where(function(Builder $q) use ($query) {
            foreach ($this->searchable as $field) {
                $q->orWhere($field, 'LIKE', "%{$query}%");
            }
        })
        ->when(
            isset($options['type']),
            fn($q) => $q->where('type', $options['type'])
        )
        ->when(
            isset($options['status']),
            fn($q) => $q->where('status', $options['status'])
        )
        ->whereNull('deleted_at')
        ->get();
    }

    public function findWithTags(array $tags): Collection
    {
        return $this->model::whereHas('tags', function(Builder $q) use ($tags) {
            $q->whereIn('name', $tags);
        })
        ->whereNull('deleted_at')
        ->get();
    }

    public function getRevisions(Content $content): Collection
    {
        return $content->revisions()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function softDelete(Content $content, int $userId): bool
    {
        return $content->update([
            'deleted_at' => now(),
            'deleted_by' => $userId
        ]);
    }

    public function restore(Content $content): bool
    {
        return $content->update([
            'deleted_at' => null,
            'deleted_by' => null
        ]);
    }

    public function permanentDelete(Content $content): bool
    {
        return DB::transaction(function() use ($content) {
            $content->tags()->detach();
            $content->revisions()->delete();
            return $content->forceDelete();
        });
    }

    public function getDeletedItems(): Collection
    {
        return $this->model::whereNotNull('deleted_at')
            ->with('deletedBy')
            ->get();
    }

    protected function applyDefaultScope(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }
}

class ContentRevision extends Model
{
    protected $fillable = [
        'content_id',
        'data',
        'user_id',
        'created_at'
    ];

    protected $casts = [
        'data' => 'encrypted',
        'created_at' => 'datetime'
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
