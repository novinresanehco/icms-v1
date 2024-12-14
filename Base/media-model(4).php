<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'name',
        'type',
        'path',
        'size',
        'mime_type',
        'metadata'
    ];
    
    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer'
    ];
    
    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }
    
    public function getSizeForHumansAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
