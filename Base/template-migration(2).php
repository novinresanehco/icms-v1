<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTemplatesTable extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->longText('content');
            $table->string('type');
            $table->string('category');
            $table->string('status')->default('draft');
            $table->foreignId('author_id')->constrained('users');
            $table->json('variables')->nullable();
            $table->json('settings')->nullable();
            $table->string('version')->default('1.0.0');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['type', 'category', 'status']);
        });

        Schema::create('template_regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('content');
            $table->json('settings')->nullable();
            $table->timestamps();
            
            $table->unique(['template_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_regions');
        Schema::dropIfExists('templates');
    }
}
