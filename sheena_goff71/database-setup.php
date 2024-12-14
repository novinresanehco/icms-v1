<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, Event};

class CreateCmsTables extends Migration
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

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('permission');
            $table->timestamps();
            $table->unique(['user_id', 'permission']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('status');
            $table->foreignId('category_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'category_id']);
            $table->index('created_by');
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->onDelete('cascade');
            $table->text('data');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('mime_type');
            $table->string('path');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->morphs('loggable');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->json('data');
            $table->timestamps();
            $table->index(['type', 'loggable_type', 'loggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('media');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('contents');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('users');
    }
}

namespace App\Core\Events;

class SecurityEvent
{
    public function __construct(
        public string $type,
        public array $data,
        public ?int $userId
    ) {}
}

class ContentEvent
{
    public function __construct(
        public string $type,
        public int $contentId,
        public array $data,
        public int $userId
    ) {}
}

namespace App\Core\Listeners;

class SecurityEventListener
{
    private LogService $logger;

    public function __construct(LogService $logger)
    {
        $this->logger = $logger;
    }

    public function handle(SecurityEvent $event): void
    {
        $this->logger->log('security', $event->type, [
            'data' => $event->data,
            'user_id' => $event->userId,
            'timestamp' => now()
        ]);

        if ($this->isHighRiskEvent($event)) {
            $this->handleHighRiskEvent($event);
        }
    }

    private function isHighRiskEvent(SecurityEvent $event): bool
    {
        return in_array($event->type, [
            'failed_login',
            'permission_violation',
            'suspicious_activity'
        ]);
    }

    private function handleHighRiskEvent(SecurityEvent $event): void
    {
        if ($event->userId) {
            DB::table('user_sessions')
                ->where('user_id', $event->userId)
                ->delete();
        }
    }
}

class ContentEventListener
{
    private CacheService $cache;
    private LogService $logger;

    public function __construct(
        CacheService $cache,
        LogService $logger
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function handle(ContentEvent $event): void
    {
        $this->cache->forget("content.{$event->contentId}");
        
        $this->logger->info("Content {$event->type}", [
            'content_id' => $event->contentId,
            'user_id' => $event->userId,
            'data' => $event->data
        ]);

        DB::table('activity_logs')->insert([
            'type' => "content.{$event->type}",
            'loggable_type' => 'content',
            'loggable_id' => $event->contentId,
            'user_id' => $event->userId,
            'data' => json_encode($event->data),
            'created_at' => now()
        ]);
    }
}
