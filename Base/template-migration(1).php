<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTemplatesTables extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('theme');
            $table->json('layout')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('theme');
            $table->index(['is_active', 'is_default']);
        });

        Schema::create('template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->longText('content')->nullable();
            $table->json('settings')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->unique(['template_id', 'key']);
            $table->index(['template_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_sections');
        Schema::dropIfExists('templates');
    }
}
