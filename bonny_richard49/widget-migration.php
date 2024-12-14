// database/migrations/[timestamp]_create_widgets_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('title');
            $table->json('settings')->nullable();
            $table->json('permissions')->nullable();
            $table->string('status')->default('active');
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('widget_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('widget_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            $table->timestamps();
            
            $table->unique(['widget_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_metadata');
        Schema::dropIfExists('widgets');
    }
};