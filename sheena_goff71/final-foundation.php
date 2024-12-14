<?php

namespace App\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateCmsStructure extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['email', 'is_active']);
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['token', 'expires_at']);
        });

        Schema::create('content_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->timestamps();
        });

        Schema::create('content', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('content');
            $table->string('status', 20);
            $table->foreignId('category_id')->constrained('content_categories');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'category_id']);
            $table->index('created_by');
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 255);
            $table->string('mime_type', 100);
            $table->string('path', 500);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('content_media', function (Blueprint $table) {
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->foreignId('media_id')->constrained()->onDelete('cascade');
            $table->primary(['content_id', 'media_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->morphs('auditable');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->json('data');
            $table->timestamps();
            $table->index(['type', 'auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('content_media');
        Schema::dropIfExists('media');
        Schema::dropIfExists('content');
        Schema::dropIfExists('content_categories');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('users');
    }
}

namespace App\Enums;

enum ContentStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Draft',
            self::PUBLISHED => 'Published',
            self::ARCHIVED => 'Archived'
        };
    }

    public function isEditable(): bool
    {
        return match($this) {
            self::DRAFT => true,
            self::PUBLISHED => true,
            self::ARCHIVED => false
        };
    }
}

enum PermissionType: string
{
    case CONTENT_CREATE = 'content.create';
    case CONTENT_EDIT = 'content.edit';
    case CONTENT_DELETE = 'content.delete';
    case CONTENT_PUBLISH = 'content.publish';
    case MEDIA_UPLOAD = 'media.upload';
    case MEDIA_DELETE = 'media.delete';
    case USER_MANAGE = 'user.manage';

    public function description(): string
    {
        return match($this) {
            self::CONTENT_CREATE => 'Create new content',
            self::CONTENT_EDIT => 'Edit existing content',
            self::CONTENT_DELETE => 'Delete content',
            self::CONTENT_PUBLISH => 'Publish content',
            self::MEDIA_UPLOAD => 'Upload media files',
            self::MEDIA_DELETE => 'Delete media files',
            self::USER_MANAGE => 'Manage users'
        };
    }
}

enum AuditType: string
{
    case AUTH_LOGIN = 'auth.login';
    case AUTH_LOGOUT = 'auth.logout';
    case CONTENT_CREATE = 'content.create';
    case CONTENT_UPDATE = 'content.update';
    case CONTENT_DELETE = 'content.delete';
    case CONTENT_PUBLISH = 'content.publish';
    case MEDIA_UPLOAD = 'media.upload';
    case MEDIA_DELETE = 'media.delete';
    case SECURITY_VIOLATION = 'security.violation';

    public function shouldAlert(): bool
    {
        return match($this) {
            self::SECURITY_VIOLATION => true,
            self::CONTENT_DELETE => true,
            default => false
        };
    }

    public function retentionDays(): int
    {
        return match($this) {
            self::SECURITY_VIOLATION => 365,
            self::AUTH_LOGIN,
            self::AUTH_LOGOUT => 90,
            default => 30
        };
    }
}
