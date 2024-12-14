<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->default('general');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'name']);
        });

        Schema::create('content_tag', function (Blueprint $table) {
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->primary(['content_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_tag');
        Schema::dropIfExists('tags');
    }
};