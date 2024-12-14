<?php

namespace App\Core\CMS\Models;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ContentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Content extends Model
{
    use SoftDeletes;

    protected $table = 'cms_contents';

    protected $fillable = [
        'title',
        'content',
        'status',
        'user_id',
        'locale',
        'metadata',
        'security_hash',
        'version'
    ];

    protected $casts = [
        'metadata' => 'array',
        'published_at' => 'datetime',
        'archived_at' => 'datetime'
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
        'published_at',
        'archived_at'
    ];

    public function versions()
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'cms_content_categories');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'cms_content_tags');
    }

    public function media()
    {
        return $this->belongsToMany(Media::class, 'cms_content_media')
            ->withPivot(['type', 'order'])
            ->orderBy('order');
    }

    public function isValid(): bool
    {
        return $this->validateContentIntegrity() && 
               $this->validateSecurityConstraints() &&
               $this->validateBusinessRules();
    }

    public function publish(): bool
    {
        if (!$this->canBePublished()) {
            throw new ContentException('Content cannot be published');
        }

        DB::beginTransaction();

        try {
            $this->createVersion();
            
            $this->status = 'published';
            $this->published_at = now();
            $this->save();

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content publication failed', 0, $e);
        }
    }

    public function archive(): bool
    {
        if (!$this->canBeArchived()) {
            throw new ContentException('Content cannot be archived');
        }

        DB::beginTransaction();

        try {
            $this->createVersion();
            
            $this->status = 'archived';
            $this->archived_at = now();
            $this->save();

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content archival failed', 0, $e);
        }
    }

    public function restore(): bool
    {
        if (!$this->canBeRestored()) {
            throw new ContentException('Content cannot be restored');
        }

        DB::beginTransaction();

        try {
            $this->createVersion();
            
            $this->status = 'draft';
            $this->archived_at = null;
            $this->save();

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content restoration failed', 0, $e);
        }
    }

    protected function createVersion(): void
    {
        $version = new ContentVersion([
            'content_data' => $this->getVersionData(),
            'version' => $this->version,
            'created_by' => auth()->id()
        ]);

        $this->versions()->save($version);
    }

    protected function getVersionData(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'status' => $this->status,
            'security_hash' => $this->security_hash
        ];
    }

    protected function validateContentIntegrity(): bool
    {
        if (empty($this->title) || empty($this->content)) {
            return false;
        }

        if (!in_array($this->status, ['draft', 'published', 'archived'])) {
            return false;
        }

        return $this->validateSecurityHash();
    }

    protected function validateSecurityConstraints(): bool
    {
        if (empty($this->security_hash) || empty($this->version)) {
            return false;
        }

        return app(SecurityManagerInterface::class)->validateContentSecurity($this);
    }

    protected function validateBusinessRules(): bool
    {
        if ($this->status === 'published' && empty($this->published_at)) {
            return false;
        }

        if ($this->status === 'archived' && empty($this->archived_at)) {
            return false;
        }

        return true;
    }

    protected function validateSecurityHash(): bool
    {
        $data = [
            'title' => $this->title,
            'content' => $this->content,
            'metadata' => $this->metadata
        ];

        $hash = hash_hmac('sha256', serialize($data), config('cms.security_key'));
        return hash_equals($this->security_hash, $hash);
    }

    protected function canBePublished(): bool
    {
        return $this->status === 'draft' && 
               $this->isValid() && 
               !$this->trashed();
    }

    protected function canBeArchived():