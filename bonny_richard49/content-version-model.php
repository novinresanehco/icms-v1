<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;

class ContentVersion extends Model
{
    protected $fillable = [
        'content_id',
        'title',
        'content',
        'metadata',
        'version',
        'created_by'
    ];

    protected $casts = [
        'metadata' => 'array',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('version', 'desc');
    }

    public function scopeLatest($query)
    {
        return $query->where('version', function ($query) {
            $query->selectRaw('max(version)')
                ->from('content_versions')
                ->whereColumn('content_id', 'content_versions.content_id');
        });
    }

    public function restore()
    {
        $this->content->restoreVersion($this);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($version) {
            if (!$version->version) {
                $version->version = static::where('content_id', $version->content_id)
                    ->max('version') + 1;
            }
        });
    }
}
