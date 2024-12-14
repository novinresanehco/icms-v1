<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teams = config('permission.teams', false);
        
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->string('module')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            if ($teams) {
                $table->unique(['name', 'guard_name', 'team_id']);
            } else {
                $table->unique(['name', 'guard_name']);
            }
            
            $table->index('module');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id']);
            
            if ($teams) {
                $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
                $table->unique(['permission_id', 'role_id', 'team_id']);
            }
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->primary(['permission_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type']);
            
            if ($teams) {
                $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
                $table->unique(['permission_id', 'model_id', 'model_type', 'team_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('permissions');
    }
};
