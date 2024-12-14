<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_analytics', function (Blueprint $table) {
            $table->id();
            $table->string('notification_id')->unique();
            $table->string('type');
            $table->string('status');
            $table->unsignedBigInteger('user_id');
            $table->json('metrics');
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('notification_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->decimal('avg_delivery_time', 10, 2)->default(0);
            $table->unsignedInteger('total_opened')->default(0);
            $table->unsignedInteger('total_clicked')->default(0);
            $table->unsignedInteger('total_converted')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_delivery_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('notification_id');
            $table->timestamp('sent_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('notification_id');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_analytics');
        Schema::dropIfExists('notification_metrics');
        Schema::dropIfExists('notification_delivery_metrics');
    }
};
