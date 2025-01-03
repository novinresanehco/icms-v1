<?php

namespace Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHealthTablesSchema
{
    public function up(): void
    {
        Schema::create('health_reports', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20);
            $table->timestamp('created_at');
            $table->index('created_at');
            $table->index('status');
        });

        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')
                ->constrained('health_reports')
                ->onDelete('cascade');
            $table->string('check_name', 100);
            $table->string('status', 20);
            $table->text('message');
            $table->json('metrics')->nullable();
            $table->timestamp('created_at');
            $table->index(['report_id', 'check_name']);
            $table->index('created_at');
            $table->index('status');
        });

        Schema::create('health_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('check_name', 100);
            $table->string('status', 20);
            $table->text('message');
            $table->json('metrics')->nullable();
            $table->boolean('notified')->default(false);
            $table->timestamp('created_at');
            $table->timestamp('resolved_at')->nullable();
            $table->index('check_name');
            $table->index('status');
            $table->index('created_at');
            $table->index('notified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_alerts');
        Schema::dropIfExists('health_checks');
        Schema::dropIfExists('health_reports');
    }
}
