<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCoreSchema extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['token', 'expires_at']);
        });

        Schema::create('content', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('status');
            $table->foreignId('author_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->string('path');
            $table->string('type');
            $table->integer('size');
            $table->timestamps();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('view');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('path');
            $table->json('assets');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->json('payload');
            $table->timestamp('created_at');
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });

        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->float('value');
            $table->timestamp('timestamp');
            $table->index(['name', 'timestamp']);
        });

        Schema::create('metric_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->json('context');
            $table->timestamp('timestamp');
            $table->index(['type', 'timestamp']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('metric');
            $table->float('value');
            $table->boolean('resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('timestamp');
            $table->index(['metric', 'timestamp']);
            $table->index('resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('metric_contexts');
        Schema::dropIfExists('metrics');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('themes');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('media');
        Schema::dropIfExists('content');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
}
