<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCriticalDataTable extends Migration
{
    public function up()
    {
        Schema::create('critical_data', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->json('data');
            $table->integer('version');
            $table->timestamps();
            
            $table->unique(['key', 'version']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('critical_data');
    }
}

class CreateUsersTable extends Migration 
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('role');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('users');
    }
}

class CreateAuthLogsTable extends Migration
{
    public function up()
    {
        Schema::create('auth_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->string('username');
            $table->string('ip_address');
            $table->json('details')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('auth_logs');
    }
}
