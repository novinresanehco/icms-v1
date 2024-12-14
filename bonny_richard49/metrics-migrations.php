<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMetricsTables extends Migration
{
    public function up(): void
    {
        // Raw metrics storage
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metrics_id')->unique();
            $table->json('metrics');
            $table->string('signature');
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->index(['metrics_id', 'expires_at']);
        });

        // 5-minute aggregations
        Schema::create('metrics_aggregations', function (Blueprint $table) {
            $table->id();
            $table->integer('timestamp');
            $table->float('avg_response_time');
            $table->float('max_response_time');
            $table->integer('total_requests');
            $table->float('error_rate');
            $table->bigInteger('memory_usage');
            $table->float('cpu_usage');
            $table->timestamp('updated_at');
            $table->unique('timestamp');
            $table->index(['timestamp', 'updated_at']);
        });

        // Alert tracking
        Schema::create('metrics_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('alert_id')->unique();
            $table->string('type');
            $table->string('severity');
            $table->json('context');
            $table->timestamp('created_at');
            $table->index(['type', 'severity', 'created_at']);
        });

        // Alert statistics
        Schema::create('metrics_alert_stats', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('severity');
            $table->integer('count');
            $table->timestamp('last_occurrence');
            $table->unique(['type', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_alert_stats');
        Schema::dropIfExists('metrics_alerts');
        Schema::dropIfExists('metrics_aggregations');
        Schema::dropIfExists('metrics');
    }
}
