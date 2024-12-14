<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('content');
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->string('author_ip', 45)->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('spam_marked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['content_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('parent_id');
            $table->index('status');
        });

        Schema::create('comment_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value');
            $table->timestamps();

            $table->unique(['comment_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_meta');
        Schema::dropIfExists('comments');
    }
};
