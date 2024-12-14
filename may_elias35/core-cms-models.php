<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Content extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'content',
        'status',
        'author_id',
        'published_at',
        'meta_data'
    ];

    protected $casts = [
        'meta_data' => 'array',
        'published_at' => 'datetime',
        'status' => 'string'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (!$model->author_id) {
                $model->author_id = auth()->id();
            }
            $model->slug = str_slug($model->title);
        });
    }

    public function setContentAttribute($value)
    {
        $this->attributes['content'] = Crypt::encryptString($value);
    }

    public function getContentAttribute($value)
    {
        return Crypt::decryptString($value);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function media()
    {
        return $this->morphToMany(Media::class, 'mediable');
    }
}

class Media extends Model 
{
    protected $fillable = [
        'path',
        'type',
        'size',
        'meta_data'
    ];

    protected $casts = [
        'meta_data' => 'array',
        'size' => 'integer'
    ];

    public function contents()
    {
        return $this->morphedByMany(Content::class, 'mediable');
    }
}

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'meta_data'
    ];

    protected $casts = [
        'meta_data' => 'array'
    ];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function contents()
    {
        return $this->belongsToMany(Content::class);
    }
}

// Critical migrations
Schema::create('contents', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->string('slug')->unique();
    $table->text('content');
    $table->string('status')->default('draft');
    $table->foreignId('author_id')->constrained('users');
    $table->timestamp('published_at')->nullable();
    $table->json('meta_data')->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['status', 'published_at']);
});

Schema::create('media', function (Blueprint $table) {
    $table->id();
    $table->string('path');
    $table->string('type');
    $table->unsignedBigInteger('size');
    $table->json('meta_data')->nullable();
    $table->timestamps();
});

Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->foreignId('parent_id')->nullable()->constrained('categories');
    $table->json('meta_data')->nullable();
    $table->timestamps();
});

Schema::create('category_content', function (Blueprint $table) {
    $table->foreignId('category_id')->constrained()->cascadeOnDelete();
    $table->foreignId('content_id')->constrained()->cascadeOnDelete();
    $table->primary(['category_id', 'content_id']);
});

Schema::create('mediables', function (Blueprint $table) {
    $table->foreignId('media_id')->constrained()->cascadeOnDelete();
    $table->morphs('mediable');
    $table->primary(['media_id', 'mediable_id', 'mediable_type']);
});
