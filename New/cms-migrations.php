<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateContentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->string('status')->default('draft');
            $table->foreignId('author_id')->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->string('checksum');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['status', 'published_at']);
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
}

class CreateMediaTable extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path');
            $table->string('type');
            $table->integer('size');
            $table->string('mime_type');
            $table->json('metadata')->nullable();
            $table->foreignId('uploader_id')->constrained('users');
            $table->string('checksum');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('uploader_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
}

class CreateContentMediaTable extends Migration
{
    public function up(): void
    {
        Schema::create('content_media', function (Blueprint $table) {
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->foreignId('media_id')->constrained()->onDelete('cascade');
            $table->integer('order')->default(0);
            $table->string('caption')->nullable();
            $table->timestamps();
            
            $table->primary(['content_id', 'media_id']);
            $table->index(['content_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_media');
    }
}