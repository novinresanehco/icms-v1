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
            $table->unsignedInteger('failure_