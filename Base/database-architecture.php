<?php

namespace Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCmsCoreTables extends Migration
{
    public function up(): void
    {
        // Contents Table
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->json('meta')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('category_id')->constrained('categories');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['status', 'published_at']);
            $table->fulltext(['title', 'content']);
        });

        // Content Versions Table
        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->onDelete('cascade');
            $table->text('content');
            $table->json('meta');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            
            $table->index(['content_id', 'created_at']);
        });

        // Categories Table
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'order']);
        });

        // Media Table
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path');
            $table->string('mime_type');
            $table->integer('size');
            $table->json('meta')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['mime_type', 'created_at']);
        });

        // Tags Table
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Taggables Pivot Table
        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
            $table->morphs('taggable');
            $table->timestamps();

            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });
    }
}

// Models
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'slug', 'content', 'meta', 
        'status', 'author_id', 'category_id'
    ];

    protected $casts = [
        'meta' => 'array',
        'published_at' => 'datetime',
    ];

    protected $with = ['category'];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function versions()
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 
        'parent_id', 'order'
    ];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')
            ->orderBy('order');
    }

    public function contents()
    {
        return $this->hasMany(Content::class);
    }
}

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'filename', 'path', 'mime_type',
        'size', 'meta', 'uploaded_by'
    ];

    protected $casts = [
        'meta' => 'array',
        'size' => 'integer'
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute()
    {
        return Storage::url($this->path);
    }
}

class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    public function contents()
    {
        return $this->morphedByMany(Content::class, 'taggable');
    }
}
