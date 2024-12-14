<?php

// database/migrations/2024_01_01_000001_create_contents_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('type');
            $table->string('status');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['type', 'status']);
            $table->index('published_at');
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->json('content_data');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            
            $table->index('created_at');
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->integer('file_size');
            $table->string('status');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['mime_type', 'status']);
        });

        Schema::create('content_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->foreignId('media_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['content_id', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_media');
        Schema::dropIfExists('media');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('contents');
    }
};

// app/Models/Content.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\{HasMany, BelongsToMany, BelongsTo};

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'content',
        'type',
        'status',
        'published_at'
    ];

    protected $casts = [
        'published_at' => 'datetime'
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'content_media')
                    ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                     ->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}

// app/Models/ContentVersion.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentVersion extends Model
{
    protected $fillable = [
        'content_id',
        'content_data',
        'created_by'
    ];

    protected $casts = [
        'content_data' => 'array'
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

// app/Models/Media.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'status',
        'created_by'
    ];

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_media')
                    ->withTimestamps();
    }
}
