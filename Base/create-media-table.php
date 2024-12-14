<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMediaTable extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // image, video, document, etc.
            $table->string('path');
            $table->string('mime_type');
            $table->bigInteger('size');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['type', 'created_at']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
}
