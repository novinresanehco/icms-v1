<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateContentsTable extends Migration
{
    public function up()
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->string('type', 50);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->json('metadata')->nullable();
            $table->foreignId('author_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('category_id')->constrained('categories')->onDelete('restrict');
            $table->foreignId('template_id')->nullable()->constrained('templates')->onDelete('set null');
            $table->foreignId('parent_id')->nullable()->constrained('contents')->onDelete('set null');
            $table->integer('order')->default(0);
            $table->enum('visibility', ['public', 'private', 'password'])->default('public');
            $table->string('password')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['type', 'status']);
            $table->index('author_id');
            $table->index('category_id');
            $table->index('parent_id');
            $table->index('order');
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->integer('version');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();

            $table->index(['content_id', 'version']);
        });

        Schema::create('content_tag', function (Blueprint $table) {
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->primary(['content_id', 'tag_id']);
        });

        Schema::create('content_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->string('type', 50);
            $table->string('path');
            $table->string('filename');
            $table->string('mime_type');
            $table->integer('size');
            $table->json('metadata')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['content_id', 'type']);
            $table->index('order');
        });
    }

    public function down()
    {
        Schema::dropIfExists('content_media');
        Schema::dropIfExists('content_tag');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('contents');
    }
}
